<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CatalogueDeDemonstration;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ouvre le magasin de démonstration d'une société en cours de vérification
 * (voir SocietePolicy::ouvrirLaDemonstration()) : au plus un par société
 * (index unique partiel magasins_une_demonstration_par_societe), jamais
 * créé d'office, nommé « Démonstration », code DEMO, sans SIRET ni identité
 * réelle (voir Emetteur::deDemonstration()), avec son client comptoir et
 * un catalogue fictif (voir CatalogueDeDemonstration). C'est un mode école
 * au sens de la caisse sécurisée : un magasin distinct, avec son registre,
 * ses séries et ses clôtures ; chaque ouverture ouvre une période
 * d'activité et s'inscrit à son journal des événements (JET 100), avec les
 * droits implicites du propriétaire dans ce magasin (JET 130).
 *
 * Déjà ouvert, il est rendu tel quel ; fermé après 30 jours sans opération
 * (voir app:fermer-demonstrations-inactives), il se rouvre, avec ses
 * données, tant que la société n'est ni validée ni refusée (nouvelle
 * période, JET 270 sur son statut et JET 100). Une fois la société
 * validée, il ne se rouvre jamais.
 */
class OuvrirLaDemonstrationAction
{
    public const NOM = 'Démonstration';

    public const MESSAGE_SANS_AUTORISATION = 'Le magasin de démonstration ne s\'ouvre que tant que la société est en cours de vérification, par son propriétaire.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly PeriodesDActivite $periodesDActivite,
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
        private readonly CatalogueDeDemonstration $catalogue,
        private readonly SeedMagasinRolesAction $seedMagasinRoles,
    ) {}

    /**
     * @throws VerificationRefuseeException
     */
    public function handle(User $auteur, Societe $societe): Magasin
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($auteur, $societe): Magasin {
            if (Gate::forUser($auteur)->denies('ouvrirLaDemonstration', $societe)) {
                throw new VerificationRefuseeException(self::MESSAGE_SANS_AUTORISATION);
            }

            $existante = $societe->demonstration()->first();

            return match (true) {
                $existante === null => $this->creer($auteur, $societe),
                $existante->statut === StatutMagasin::Ferme => $this->rouvrir($auteur, $existante),
                default => $existante,
            };
        });
    }

    private function creer(User $auteur, Societe $societe): Magasin
    {
        return DB::transaction(function () use ($auteur, $societe): Magasin {
            $demonstration = Magasin::forceCreate([
                'societe_id' => $societe->getKey(),
                'nom' => self::NOM,
                'code' => CodeMagasin::CODE_DE_LA_DEMONSTRATION,
                'est_demonstration' => true,
                'siret' => null,
                'pays' => 'FR',
            ]);

            $this->seedMagasinRoles->handle();

            app(TenantContext::class)->runAs($demonstration, function () use ($auteur, $demonstration): void {
                $this->registre->verrouiller($demonstration);

                Client::forceCreate([
                    'nom' => Client::NOM_COMPTOIR,
                    'type_client' => TypeClient::Particulier,
                    'est_comptoir' => true,
                ]);
                $this->catalogue->remplir($demonstration);
                $this->entrer($auteur, $demonstration, 'ouverture');

                $avant = $this->journalDesDroits->avant($demonstration, null);
                $this->journalDesDroits->apres($demonstration, $demonstration->societe->proprietaire, $avant);
            });

            return $demonstration;
        });
    }

    private function rouvrir(User $auteur, Magasin $demonstration): Magasin
    {
        return app(TenantContext::class)->runAs($demonstration, fn (): Magasin => $this->registre->sousVerrou($demonstration, function () use ($auteur, $demonstration): Magasin {
            $verrouille = Magasin::query()->whereKey($demonstration->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMagasin::Ferme) {
                return $verrouille;
            }

            $verrouille->forceFill(['statut' => StatutMagasin::Actif])->save();
            $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => 'statut_magasin',
                'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
                'ancienne_valeur' => StatutMagasin::Ferme->value,
                'nouvelle_valeur' => StatutMagasin::Actif->value,
            ]);
            $this->entrer($auteur, $verrouille, 'reouverture');

            return $verrouille;
        }));
    }

    /**
     * La période d'activité ouverte, l'entrée en mode démonstration à son
     * journal des événements (JET 100) et au journal d'audit.
     */
    private function entrer(User $auteur, Magasin $demonstration, string $motif): void
    {
        $this->periodesDActivite->ouvrir($demonstration, $auteur);
        $this->registre->journaliser(CodeEvenement::EntreeEnModeDemonstration, ['motif' => $motif]);
        $this->journalAudit->inscrire(ActionAuditee::DemonstrationOuverte, $auteur, $demonstration, null, ['motif' => $motif], $demonstration);
    }
}
