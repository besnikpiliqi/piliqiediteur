<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use LogicException;

/**
 * La procédure de l'éditeur qui corrige le territoire d'un magasin mal lu
 * au répertoire Sirene, puis corrigé par l'INSEE (voir
 * editeur:corriger-territoire), sur ce seul constat : le répertoire,
 * consulté de nouveau, situe l'établissement confirmé du magasin, sous le
 * même SIRET, dans un autre territoire que le logiciel gère. Le nouveau
 * territoire est celui qu'il lit, jamais un territoire saisi.
 *
 * Seulement tant que le magasin n'a numéroté aucun document (voir
 * Magasin::aNumeroteUnDocument()) : aucune date ni aucune année signée ne
 * dépend encore de son territoire, et la correction ne fausse rien.
 * Ensuite, son territoire ne change plus (la base le refuse elle-même,
 * voir DeclencheursDeProtection::colonneFigee()) : la société ouvre un
 * nouveau magasin et ferme celui-ci pour cessation.
 *
 * Sous le verrou du registre du magasin, premier verrou de la
 * transaction, puis de sa ligne, relue (une numérotation crée sa première
 * série sous un verrou partagé de cette ligne, voir
 * DocumentNumberingService, et passe donc tout entière avant ou après) :
 * la correction s'inscrit à son journal des événements (JET 270
 * territoire, voir Magasin::corrigerLeTerritoire()), le contrôle Sirene au
 * registre des vérifications et la correction au journal d'audit, avec
 * l'intervention de l'éditeur ; le propriétaire en est prévenu.
 */
class CorrigerLeTerritoireAction
{
    public const MESSAGE_DEMONSTRATION = 'Le magasin de démonstration prend le territoire du siège de la société : il ne se corrige pas.';

    public const MESSAGE_ETABLISSEMENT_NON_CONFIRME = 'Ce magasin n\'a pas d\'établissement confirmé par le répertoire Sirene : son territoire se fixe à la reprise du contrôle, pas par cette procédure.';

    public const MESSAGE_REPERTOIRE_NE_CONFIRME_PAS = 'Le répertoire Sirene de l\'INSEE ne confirme pas l\'établissement du magasin aujourd\'hui : rien n\'est corrigé.';

    public const MESSAGE_MEME_TERRITOIRE = 'Le répertoire Sirene situe l\'établissement dans le territoire du magasin : rien n\'est à corriger.';

    public const MESSAGE_DOCUMENT_NUMEROTE = 'Ce magasin a déjà numéroté des documents : son territoire ne se corrige plus. La société ouvre un nouveau magasin, puis ferme celui-ci pour cessation.';

    public const MESSAGE_CONTROLE_PERIME = 'Le SIRET du magasin a changé pendant son contrôle au répertoire Sirene : relancez la procédure.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @return Territoire le territoire corrigé
     *
     * @throws LogicException quand le magasin, le répertoire ou ses documents ne permettent pas la correction
     */
    public function handle(Magasin $magasin, InterventionDeLEditeur $editeur): Territoire
    {
        if ($magasin->est_demonstration) {
            throw new LogicException(self::MESSAGE_DEMONSTRATION);
        }

        $societe = $magasin->societe;

        return app(TenantContext::class)->runAsSociete($societe, function () use ($magasin, $societe, $editeur): Territoire {
            $siret = (string) $magasin->siret;
            $consultation = $this->controleSirene->etablissement($siret, (string) $societe->siren);
            $etablissement = $consultation['etablissement'];

            if ($consultation['resultat'] !== ResultatVerification::Concluante || $etablissement === null) {
                throw new LogicException(self::MESSAGE_REPERTOIRE_NE_CONFIRME_PAS);
            }

            $lu = ControleSirene::territoireDe($etablissement);

            if ($lu['territoire'] === null) {
                throw new LogicException($lu['motif']);
            }

            [$corrige, $ancien] = app(TenantContext::class)->runAs($magasin, fn (): array => $this->operateur->pendantLeSysteme(fn (): array => $this->registre->sousVerrou($magasin, function () use ($magasin, $siret, $consultation, $lu, $editeur): array {
                $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

                if ($verrouille->siret !== $siret) {
                    throw new LogicException(self::MESSAGE_CONTROLE_PERIME);
                }

                if ($verrouille->etablissement_verifie_le === null || $verrouille->territoire === null) {
                    throw new LogicException(self::MESSAGE_ETABLISSEMENT_NON_CONFIRME);
                }

                if ($verrouille->territoire === $lu['territoire']) {
                    throw new LogicException(self::MESSAGE_MEME_TERRITOIRE);
                }

                if ($verrouille->aNumeroteUnDocument()) {
                    throw new LogicException(self::MESSAGE_DOCUMENT_NUMEROTE);
                }

                $ancien = $verrouille->territoire;
                $verrouille->corrigerLeTerritoire($lu['territoire']);
                $this->controleSirene->noterEtablissement($verrouille, $consultation, editeur: $editeur);
                $this->journalAudit->inscrire(ActionAuditee::TerritoireCorrige, null, $verrouille, ['territoire' => $ancien->value], ['territoire' => $lu['territoire']->value], $verrouille, $editeur);

                return [$verrouille, $ancien];
            })));

            $societe->proprietaire?->notify(new AvisDeVerification($societe, 'Territoire de votre magasin corrigé', "Le répertoire Sirene situe désormais l'établissement de {$corrige->nom} (SIRET {$corrige->siret}) {$corrige->territoire->lieu()}, et non {$ancien->lieu()} : l'éditeur a corrigé son territoire, qui fixe son taux de TVA par défaut et son heure, avant son premier document numéroté."));

            return $corrige->territoire;
        });
    }
}
