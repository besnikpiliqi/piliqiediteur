<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\NicValide;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Corrige le SIREN d'une société mal saisi à l'inscription, tant qu'elle
 * n'est pas validée : la validation le verrouille (Q3, l'option la plus
 * stricte ; voir Societe::booted()). Réservé à son propriétaire (voir
 * SocietePolicy::corrigerLeSiren()).
 *
 * Le SIREN corrigé passe le contrôle du répertoire Sirene comme à
 * l'inscription (une entreprise cessée ou inconnue est refusée), et le
 * NIC de chaque magasin réel se ressaisit : son SIRET, qui commence par le
 * SIREN, change avec lui, contrôlé comme à la création (voir
 * CreerEtablissementAction). Avant la validation, aucun SIRET n'est
 * réservé : rien n'est libéré.
 *
 * L'identité que le répertoire avait donnée de l'entreprise de l'ancien
 * SIREN, une autre que la sienne, ne reste pas : la raison sociale, la
 * catégorie juridique, la forme qu'elle donne et le siège deviennent ceux
 * que le répertoire donne du nouveau SIREN (voir
 * ControleSirene::identiteDepuisSirene()), vides quand il ne les donne pas
 * (diffusion partielle, ou INSEE injoignable : la reprise du contrôle les
 * complète, voir app:reverifier-sirene). Une forme qui change emporte son
 * libellé exact et ce qu'elle exclut (voir
 * Societe::retirerCeQueSaFormeExclut()) ; le n° de TVA, s'il était celui
 * que formait l'ancien SIREN, devient celui que forme le nouveau ; le
 * reste de l'identité (capital, immatriculation) est celui que le
 * propriétaire a saisi, et ne change pas.
 *
 * Les vérifications de la qualité de représentant et de l'adresse se
 * refont pour le nouveau SIREN (section 7.7 de la conception) : celles de
 * l'ancien restent au registre, mais ne valent plus (voir
 * VerificationSociete::vautPourLeSiren()) ; les codes encore actifs de la
 * société, partis vers le siège de l'ancien SIREN, s'annulent ; les
 * demandes d'accord qui attendent un dirigeant de l'ancienne entreprise
 * s'annulent ; les pièces qui attendent l'examen de l'éditeur, déposées
 * pour l'ancienne entreprise, s'effacent. L'identité vérifiée du
 * propriétaire, celle de sa personne, reste : elle se rapproche des
 * dirigeants du nouveau SIREN (voir
 * RecevoirResultatIdentiteAction::rapprocherDeNouveau()).
 *
 * Tout se fait sous le verrou des registres de tous les magasins réels de
 * la société (le magasin de démonstration n'a ni SIREN ni SIRET), qui
 * reçoivent le nouveau SIREN, et chacun son nouveau SIRET, à leur journal
 * des événements (JET 270, voir
 * JournaliseSesParametresDeConformite). Les contrôles Sirene s'inscrivent
 * au registre des vérifications, et la correction au journal d'audit,
 * avec les anciennes et les nouvelles valeurs des identifiants et de
 * l'identité qui ont changé ; chaque code, demande d'accord et pièce
 * annulé ou effacé s'y inscrit aussi.
 */
class CorrigerSirenAvantValidationAction
{
    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire corrige le SIREN de la société, et seulement tant qu\'elle n\'est pas validée.';

    public const MESSAGE_NIC_MANQUANT = 'Ressaisissez le NIC de chaque magasin : son SIRET commence par le SIREN.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly PiecesDeVerification $pieces,
        private readonly AnnulationDesCodes $annulationDesCodes,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  array<int|string, mixed>  $nics  le NIC de chaque magasin de la société, par identifiant de magasin
     *
     * @throws LogicException quand l'auteur ne peut pas corriger le SIREN
     * @throws ValidationException quand le SIREN ou un NIC est refusé
     */
    public function handle(User $auteur, Societe $societe, string $siren, array $nics): Societe
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException(self::MESSAGE_SANS_AUTORISATION);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $societe, $siren, $nics): Societe {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'corrigerLeSiren', $societe, $societe, new LogicException(self::MESSAGE_SANS_AUTORISATION));

            $siren = Validator::make(['siren' => $siren], ['siren' => ['required', 'string', new SirenValide]])->validate()['siren'];
            $magasins = $societe->magasinsReels()->orderBy('id')->get();
            $sirets = $this->siretsCorriges($magasins, $siren, $nics);

            $unite = $this->controleSirene->unite($siren);

            if ($unite['resultat'] === ResultatVerification::Negative) {
                throw ValidationException::withMessages(['siren' => $unite['motif']]);
            }

            $identite = [
                ...array_fill_keys(ControleSirene::CHAMPS_DU_REPERTOIRE, null),
                ...($unite['unite'] === null ? [] : array_intersect_key($this->controleSirene->identiteDepuisSirene($unite['unite']), array_flip(ControleSirene::CHAMPS_DU_REPERTOIRE))),
            ];

            $etablissements = [];

            foreach ($magasins as $magasin) {
                $consultation = $this->controleSirene->etablissement($sirets[$magasin->getKey()], $siren);

                if ($consultation['resultat'] === ResultatVerification::Negative) {
                    throw ValidationException::withMessages(["nics.{$magasin->getKey()}" => $consultation['motif']]);
                }

                if ($consultation['etablissement'] !== null && ! ControleSirene::estDansUnTerritoireGere($consultation['etablissement'])) {
                    throw ValidationException::withMessages(["nics.{$magasin->getKey()}" => ControleSirene::MOTIF_TERRITOIRE_NON_GERE]);
                }

                $etablissements[$magasin->getKey()] = $consultation;
            }

            return $this->operateur->pendant($auteur, fn (): Societe => $this->registre->sousVerrouDesMagasins($magasins, function () use ($auteur, $societe, $siren, $magasins, $sirets, $unite, $identite, $etablissements): Societe {
                $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

                if ($enregistree->estValidee()) {
                    throw new LogicException(self::MESSAGE_SANS_AUTORISATION);
                }

                $avant = [
                    'siren' => $enregistree->siren,
                    'sirets' => $magasins->mapWithKeys(fn (Magasin $magasin): array => [(string) $magasin->getKey() => $magasin->siret])->all(),
                    ...$enregistree->identiteAuditee(),
                ];
                $numeroTvaPropose = $enregistree->numero_tva === IdentifiantsInsee::numeroTvaPropose((string) $enregistree->siren);
                $formeChangee = $identite['forme_juridique'] !== $enregistree->forme_juridique;

                $enregistree->forceFill([
                    'siren' => $siren,
                    ...$identite,
                    ...($formeChangee ? ['forme_juridique_autre' => null] : []),
                    ...($numeroTvaPropose ? ['numero_tva' => IdentifiantsInsee::numeroTvaPropose($siren)] : []),
                ])->save();

                foreach ($magasins as $magasin) {
                    $etablissement = $etablissements[$magasin->getKey()]['etablissement'];
                    $magasin->setRelation('societe', $enregistree);
                    $magasin->forceFill([
                        'siret' => $sirets[$magasin->getKey()],
                        'etablissement_verifie_le' => $etablissement === null ? null : now(),
                        ...($etablissement === null ? [] : [...ControleSirene::adresseDe($etablissement), 'pays' => 'FR']),
                    ])->save();

                    $this->controleSirene->noterEtablissement($magasin, $etablissements[$magasin->getKey()], $auteur);
                }

                $this->controleSirene->noterUnite($enregistree, $unite, $auteur);
                $this->journalAudit->inscrireLesChangements(ActionAuditee::SirenCorrigeAvantValidation, $auteur, null, $avant, [
                    'siren' => $siren,
                    'sirets' => array_map(strval(...), $sirets),
                    ...$enregistree->identiteAuditee(),
                ], $enregistree);
                $this->annulerLesPreuvesEnCours($enregistree, $auteur);

                return $enregistree;
            }));
        });
    }

    /**
     * Annule ce qui attendait encore pour l'ancien SIREN, dans la société
     * active : ses codes actifs (les plis partis vers l'ancien siège), ses
     * demandes d'accord en attente (un dirigeant de l'ancienne entreprise),
     * et efface les pièces qui attendaient l'examen de l'éditeur ; chacun
     * au journal d'audit.
     */
    private function annulerLesPreuvesEnCours(Societe $societe, User $auteur): void
    {
        CodeConfirmation::query()->whereNull('utilise_le')->whereNull('annule_le')->orderBy('id')->lockForUpdate()->get()
            ->each(fn (CodeConfirmation $code) => $this->annulationDesCodes->annuler($code, AnnulationDesCodes::SIREN_CORRIGE, $auteur));

        DemandeAccordDirigeant::query()->whereNull('approuvee_le')->whereNull('refusee_le')->whereNull('annulee_le')->orderBy('id')->lockForUpdate()->get()
            ->each(function (DemandeAccordDirigeant $demande) use ($auteur): void {
                $demande->forceFill(['annulee_le' => now()])->save();
                $this->journalAudit->inscrire(ActionAuditee::AccordDirigeantAnnule, $auteur, null, null, ['dirigeant' => $demande->representant()->libelle(), 'raison' => AnnulationDesCodes::SIREN_CORRIGE], $demande);
            });

        $this->pieces->effacerToutes($societe, PiecesDeVerification::MOTIF_SIREN_CORRIGE);
    }

    /**
     * Le nouveau SIRET de chaque magasin, par identifiant : son NIC
     * ressaisi après le SIREN corrigé, valide et différent de ceux des
     * autres magasins de la société. Avant la validation, rien n'est
     * réservé pour la société, et rien ne lui dit qu'un SIRET l'est par
     * une autre : la réservation se joue à la validation.
     *
     * @param  iterable<Magasin>  $magasins
     * @param  array<int|string, mixed>  $nics
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    private function siretsCorriges(iterable $magasins, string $siren, array $nics): array
    {
        $sirets = [];
        $erreurs = [];

        foreach ($magasins as $magasin) {
            $champ = "nics.{$magasin->getKey()}";
            $nic = $nics[$magasin->getKey()] ?? null;

            if (! is_string($nic) || ! IdentifiantsInsee::nicValide($nic)) {
                $erreurs[$champ] = is_string($nic) && $nic !== '' ? NicValide::MESSAGE : self::MESSAGE_NIC_MANQUANT;

                continue;
            }

            $siret = $siren.$nic;

            $erreurs[$champ] = match (true) {
                ! IdentifiantsInsee::siretValide($siret) => SiretValide::MESSAGE,
                in_array($siret, $sirets, true) => SiretsDesMagasins::MESSAGE_SIRET_RESERVE,
                default => null,
            };

            $sirets[$magasin->getKey()] = $siret;
        }

        $erreurs = array_filter($erreurs);

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return $sirets;
    }
}
