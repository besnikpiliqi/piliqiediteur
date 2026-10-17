<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Notifications\ResponsableNommeParUnAdministrateur;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
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
            AvisDeLEquipe::class => new AvisDeLEquipe($societe, 'Titre', 'Texte'),
            AvisDeVerification::class => new AvisDeVerification($societe, 'Titre', 'Texte'),
            ResponsableNommeParUnAdministrateur::class => new ResponsableNommeParUnAdministrateur($administrateur, $responsable, $magasin),
            EnvoiDocumentEchoue::class => new EnvoiDocumentEchoue($envoi),
            FacturesPasseesEnRetard::class => new FacturesPasseesEnRetard($magasin, ['FAC-MABOUT-2026-000001']),
            StockSousLeSeuilDAlerte::class => new StockSousLeSeuilDAlerte(Produit::factory()->create()),
            StockDuMagasinCede::class => new StockDuMagasinCede($magasin, '28.75', []),
        ];

        $this->assertEqualsCanonicalizing(array_keys($notifications), $this->notificationsEnBase());
        foreach ($notifications as $classe => $notification) {
            $proprietaire->notify($notification);
            $donnee = $proprietaire->notifications()->where('type', $classe)->sole()->data;
            $this->assertSame((int) $societe->id, (int) $donnee['societe_id'], $classe);
        }
        foreach ([EnvoiDocumentEchoue::class, FacturesPasseesEnRetard::class, StockSousLeSeuilDAlerte::class, StockDuMagasinCede::class] as $classe) {
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
            ->filter(fn (string $classe): bool => in_array('database', (new ReflectionClass($classe))->newInstanceWithoutConstructor()->via(new \stdClass), true))
            ->values()
            ->all();
    }
}
