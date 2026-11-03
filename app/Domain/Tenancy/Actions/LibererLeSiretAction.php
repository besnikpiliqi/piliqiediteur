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
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use LogicException;

/**
 * La procédure de l'éditeur qui libère le SIRET d'un magasin dont le
 * territoire a été mal lu au répertoire Sirene, puis corrigé par l'INSEE,
 * après son premier document numéroté (voir editeur:liberer-siret) : son
 * territoire ne se corrige plus (voir CorrigerLeTerritoireAction), et seul
 * un nouveau magasin, sur le même établissement, prend le bon. Dans
 * l'ordre : la société ferme ce magasin pour cessation (voir
 * FermerMagasinAction), l'éditeur libère son SIRET, puis la société crée
 * le nouveau magasin (voir CreerEtablissementAction), qui prend le
 * territoire que lit le répertoire.
 *
 * Sur ce seul constat : le répertoire, consulté de nouveau, situe
 * l'établissement confirmé du magasin, sous le même SIRET, dans un autre
 * territoire que le sien, que le logiciel gère (ailleurs, aucun nouveau
 * magasin ne s'ouvrirait). Seulement pour un magasin fermé pour cessation
 * qui a numéroté un document ; avant, l'éditeur corrige son territoire.
 *
 * Le magasin garde son SIRET, que ses documents ont signé, son territoire,
 * son registre et son historique ; il ne se rouvre plus et ne change plus
 * de SIRET (voir Magasin::booted()). Sous le verrou de son registre,
 * premier verrou de la transaction, puis de sa ligne, relue (une
 * réouverture ou un changement de SIRET passe tout entier avant ou
 * après) : la libération se pose sur le magasin (siret_libere_le, que la
 * base ne laisse plus effacer) et sur sa réservation au registre des SIRET
 * réservés, s'il en a une (voir SiretsDesMagasins::liberer()) ; le contrôle
 * Sirene s'inscrit au registre des vérifications et la libération au
 * journal d'audit, avec l'intervention de l'éditeur ; le propriétaire en
 * est prévenu. Rien ne touche la caisse du magasin.
 */
class LibererLeSiretAction
{
    public const MOTIF_TERRITOIRE_MAL_LU = 'Territoire mal lu au répertoire Sirene';

    public const MESSAGE_DEMONSTRATION = 'Le magasin de démonstration n\'a pas de SIRET.';

    public const MESSAGE_DEJA_LIBERE = 'Le SIRET de ce magasin est déjà libéré.';

    public const MESSAGE_PAS_FERME_POUR_CESSATION = 'Ce magasin n\'est pas fermé pour cessation : la société le ferme d\'abord pour cessation, puis l\'éditeur libère son SIRET pour un nouveau magasin.';

    public const MESSAGE_ETABLISSEMENT_NON_CONFIRME = 'Ce magasin n\'a pas d\'établissement confirmé par le répertoire Sirene : son SIRET ne se libère pas.';

    public const MESSAGE_AUCUN_DOCUMENT = 'Ce magasin n\'a numéroté aucun document : l\'éditeur corrige son territoire (editeur:corriger-territoire), sans nouveau magasin.';

    public const MESSAGE_REPERTOIRE_NE_CONFIRME_PAS = 'Le répertoire Sirene de l\'INSEE ne confirme pas l\'établissement du magasin aujourd\'hui : rien n\'est libéré.';

    public const MESSAGE_MEME_TERRITOIRE = 'Le répertoire Sirene situe l\'établissement dans le territoire du magasin : son SIRET ne se libère pas.';

    public const MESSAGE_CONTROLE_PERIME = 'Le SIRET du magasin a changé pendant son contrôle au répertoire Sirene : relancez la procédure.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly SiretsDesMagasins $siretsDesMagasins,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @return Territoire le territoire où le répertoire situe l'établissement, que prendra le nouveau magasin
     *
     * @throws LogicException quand le magasin, le répertoire ou ses documents ne permettent pas la libération
     */
    public function handle(Magasin $magasin, InterventionDeLEditeur $editeur): Territoire
    {
        self::verifierQuIlSeLibere($magasin);
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

            $libere = app(TenantContext::class)->runAs($magasin, fn (): Magasin => $this->operateur->pendantLeSysteme(fn (): Magasin => $this->registre->sousVerrou($magasin, function () use ($magasin, $siret, $consultation, $lu, $editeur): Magasin {
                $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

                if ($verrouille->siret !== $siret) {
                    throw new LogicException(self::MESSAGE_CONTROLE_PERIME);
                }

                self::verifierQuIlSeLibere($verrouille);

                if ($verrouille->territoire === $lu['territoire']) {
                    throw new LogicException(self::MESSAGE_MEME_TERRITOIRE);
                }

                $verrouille->forceFill(['siret_libere_le' => now()])->save();
                $this->siretsDesMagasins->liberer($verrouille, self::MOTIF_TERRITOIRE_MAL_LU);
                $this->controleSirene->noterEtablissement($verrouille, $consultation, editeur: $editeur);
                $this->journalAudit->inscrire(ActionAuditee::SiretLibere, null, $verrouille, null, [
                    'siret' => $siret,
                    'motif' => self::MOTIF_TERRITOIRE_MAL_LU,
                    'territoire' => $verrouille->territoire->value,
                    'territoire_lu' => $lu['territoire']->value,
                ], $verrouille, $editeur);

                return $verrouille;
            })));

            $societe->proprietaire?->notify(new AvisDeVerification($societe, 'SIRET de votre magasin libéré', "Le répertoire Sirene situe l'établissement de {$libere->nom} (SIRET {$libere->siret}) {$lu['territoire']->lieu()}, et non {$libere->territoire->lieu()} : l'éditeur a libéré son SIRET. Vous pouvez créer un nouveau magasin sur cet établissement, qui prendra ce territoire, son taux de TVA par défaut et son heure ; {$libere->nom} reste fermé, avec ses documents."));

            return $lu['territoire'];
        });
    }

    /**
     * Le magasin, tel qu'il est lu, peut-il voir son SIRET libéré ? Un
     * magasin réel dont le SIRET n'est pas déjà libéré, dont
     * l'établissement a été confirmé, qui a numéroté un document et que la
     * société a fermé pour cessation. Le refus dit la suite qui convient :
     * sans document numéroté, l'éditeur corrige le territoire, même d'un
     * magasin ouvert ; avec, la société ferme d'abord le magasin pour
     * cessation.
     *
     * @throws LogicException
     */
    private static function verifierQuIlSeLibere(Magasin $magasin): void
    {
        $refus = match (true) {
            $magasin->est_demonstration => self::MESSAGE_DEMONSTRATION,
            $magasin->aLibereSonSiret() => self::MESSAGE_DEJA_LIBERE,
            $magasin->etablissement_verifie_le === null || $magasin->territoire === null => self::MESSAGE_ETABLISSEMENT_NON_CONFIRME,
            ! $magasin->aNumeroteUnDocument() => self::MESSAGE_AUCUN_DOCUMENT,
            ! $magasin->estFermePourCessation() => self::MESSAGE_PAS_FERME_POUR_CESSATION,
            default => null,
        };

        if ($refus !== null) {
            throw new LogicException($refus);
        }
    }
}
