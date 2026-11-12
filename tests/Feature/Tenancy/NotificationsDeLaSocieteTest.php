<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Facturation\Notifications\ReponseDuClientAuDevis;
use App\Domain\Facturation\Notifications\TransmissionNonAboutie;
use App\Domain\Facturation\Services\ArchiveDesDocuments;
use App\Domain\Stock\Notifications\EcartDansLeStock;
use App\Domain\Stock\Notifications\StockNegatif;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Notifications\AvisDuTelechargementDesDocuments;
use App\Domain\Tenancy\Notifications\ResponsableNommeParUnAdministrateur;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Les notifications rangées en base, dans la cloche de leur destinataire :
 * chacune porte la société dont elle parle (societe_id), et celle d'un
 * magasin (voir NotificationDuMagasin) porte aussi ce magasin
 * (magasin_id).
 */
class NotificationsDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque notification de l'application qui passe par la base, avec la
     * société dans sa donnée enregistrée ; aucune n'échappe à la liste.
     */
    public function test_toute_notification_porte_sa_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin);
        $responsable = $this->responsable($magasin);
        $envoi = EnvoiDocument::factory()->for(Facture::factory()->create(), 'document')->create();
        $notifications = [
            AvisDeLAntivirus::class => new AvisDeLAntivirus($societe, 'Titre', 'Texte'),
            AvisDeLEquipe::class => new AvisDeLEquipe($societe, 'Titre', 'Texte'),
            AvisDeVerification::class => new AvisDeVerification($societe, 'Titre', 'Texte'),
            AvisDuCabinet::class => new AvisDuCabinet($societe, 'Titre', 'Texte'),
            ResponsableNommeParUnAdministrateur::class => new ResponsableNommeParUnAdministrateur($administrateur, $responsable, $magasin),
            EnvoiDocumentEchoue::class => new EnvoiDocumentEchoue($envoi),
            FacturesPasseesEnRetard::class => new FacturesPasseesEnRetard($magasin, ['FAC-MABOUT-2026-000001']),
            TransmissionNonAboutie::class => new TransmissionNonAboutie(Facture::factory()->create()),
            ReponseDuClientAuDevis::class => new ReponseDuClientAuDevis($magasin, Devis::factory()->create(), (new ReponseDuDevis)->forceFill(['reponse' => ReponseAuDevis::Refus, 'created_at' => self::aParis('2026-11-12 10:00:00')]), []),
            StockSousLeSeuilDAlerte::class => new StockSousLeSeuilDAlerte(Produit::factory()->create()),
            StockNegatif::class => new StockNegatif(Produit::factory()->create(), 'T-MABOUT-2026-000001'),
            EcartDansLeStock::class => new EcartDansLeStock($magasin, 2),
            StockDuMagasinCede::class => new StockDuMagasinCede($magasin, '28.75', []),
            AvisDuTelechargementDesDocuments::class => new AvisDuTelechargementDesDocuments(app(TenantContext::class)->runAsSociete($societe, fn (): TelechargementDeDocuments => tap((new TelechargementDeDocuments)->forceFill([
                'demande_par' => $proprietaire->id,
                'magasins' => [$magasin->id],
                'du' => '2026-03-01',
                'au' => '2026-03-31',
                'types' => [TypeDeDocumentTelechargeable::Ventes->value],
                'origine' => OrigineDesAchats::Toutes,
                'piece_manquante_seulement' => false,
                'format' => FormatDuTelechargement::Csv,
                'selection' => [],
                'statut' => StatutDuTelechargement::Echec,
                'motif_echec' => ArchiveDesDocuments::AUCUN_DOCUMENT,
                'expire_le' => now()->addHour(),
            ]))->save())),
        ];

        $this->assertEqualsCanonicalizing(array_keys($notifications), $this->notificationsEnBase());
        foreach ($notifications as $classe => $notification) {
            $proprietaire->notify($notification);
            $donnee = $proprietaire->notifications()->where('type', $classe)->sole()->data;
            $this->assertSame((int) $societe->id, (int) $donnee['societe_id'], $classe);
        }
        foreach ([EnvoiDocumentEchoue::class, FacturesPasseesEnRetard::class, StockSousLeSeuilDAlerte::class, StockNegatif::class, StockDuMagasinCede::class, AvisDuTelechargementDesDocuments::class, ReponseDuClientAuDevis::class] as $classe) {
            $this->assertSame((int) $magasin->id, (int) $proprietaire->notifications()->where('type', $classe)->sole()->data['magasin_id'], $classe);
        }
    }

    /**
     * Les classes de notification de l'application qui passent par la
     * base.
     *
     * @return list<class-string<Notification>>
     */
    private function notificationsEnBase(): array
    {
        return collect(File::allFiles(app_path()))
            ->map(fn (SplFileInfo $fichier): string => 'App\\'.Str::of($fichier->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
            ->filter(fn (string $classe): bool => class_exists($classe) && is_subclass_of($classe, Notification::class) && ! (new ReflectionClass($classe))->isAbstract())
            ->filter(fn (string $classe): bool => in_array('database', self::sansSonConstructeur($classe)->via(new \stdClass), true))
            ->values()
            ->all();
    }

    /**
     * Une notification créée sans son constructeur, dont chaque propriété
     * promue qui a une valeur par défaut la reçoit, comme une notification
     * créée sans cet argument : son canal (via()) peut la lire.
     *
     * @param  class-string<Notification>  $classe
     */
    private static function sansSonConstructeur(string $classe): Notification
    {
        $reflexion = new ReflectionClass($classe);
        $notification = $reflexion->newInstanceWithoutConstructor();

        foreach ($reflexion->getConstructor()?->getParameters() ?? [] as $parametre) {
            if ($parametre->isPromoted() && $parametre->isDefaultValueAvailable()) {
                $reflexion->getProperty($parametre->getName())->setValue($notification, $parametre->getDefaultValue());
            }
        }

        return $notification;
    }
}
