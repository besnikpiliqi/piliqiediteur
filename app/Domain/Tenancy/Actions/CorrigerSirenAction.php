<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Sirene\EtablissementSirene;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Throwable;

/**
 * La procédure de l'éditeur qui corrige le SIREN d'une société validée,
 * saisi par erreur (editeur:societe:corriger-siren), procédure grave
 * lancée par deux opérateurs distincts, seulement après un examen qui a
 * conclu à une erreur pour ce même SIREN (voir ExaminerSirenAction).
 *
 * Le nouveau SIREN est celui d'une entreprise active au répertoire Sirene,
 * qu'aucune autre inscription ne garde. Chaque magasin réel qui peut
 * encore vendre (ouvert, ou fermé temporairement) reçoit son nouveau
 * SIRET : un établissement actif, rattaché au nouveau SIREN, du même
 * territoire, qu'aucun magasin ne réserve ; un magasin fermé pour
 * cessation ou cédé garde le sien, comme ses documents gardent l'ancien
 * SIREN (sa réouverture recontrôlerait son établissement). Le SIREN d'un
 * cabinet d'expertise comptable ne se corrige pas ainsi : son code
 * d'activité et son inscription à l'Ordre ont été vérifiés sous ce SIREN.
 *
 * Les contrôles du répertoire se font avant toute écriture ; puis, sous
 * les verrous des registres de tous ses magasins réels, de sa ligne, puis
 * de chaque magasin qui change de SIRET, et seulement si aucun de ces
 * magasins ne porte le code d'un magasin d'une autre inscription qui a
 * déjà numéroté des documents sous le nouveau SIREN (une société révoquée
 * qui l'avait, voir CodeMagasin::aServiSousCeSiren()), dont il répéterait
 * les numéros : le SIREN change (Societe::changerLeSirenParLaProcedure(),
 * seul passage de sa garde), avec son n° de TVA : celui que donne
 * l'éditeur, au format d'un n° de TVA comme sur l'écran de l'identité de
 * la société ; sinon, celui que forme le nouveau SIREN quand l'ancien n°
 * était celui que formait l'ancien SIREN ; un n° qui ne l'était pas (celui
 * d'un assujetti unique, ou une faute de saisie) n'est gardé que si
 * l'éditeur confirme qu'il reste celui de l'assujetti unique dont la
 * société est membre, et la correction est refusée sans l'un ou l'autre ;
 * une société sans n° de TVA n'en reçoit pas. Le SIREN et le n° de TVA
 * s'inscrivent au journal des événements de chacun (JET 270 siren et
 * numero_tva, voir JournaliseSesParametresDeConformite) ; chaque magasin
 * passe à son nouveau SIRET, l'ancien restant réservé (voir
 * SiretsDesMagasins::remplacer(), JET 270 siret) ; les contrôles du
 * répertoire s'inscrivent au registre des vérifications, et la correction
 * au journal d'audit, avec l'intervention de l'éditeur. Si les magasins
 * ont changé depuis les contrôles, rien n'est corrigé. Aucun document émis
 * ne change : chacun garde l'ancien SIREN qu'il a signé. Ensuite, le
 * propriétaire est prévenu, sans la justification de l'éditeur. Une
 * nouvelle attestation par magasin (étape 3) et la connexion à la
 * plateforme agréée (étape 2) suivront.
 */
class CorrigerSirenAction
{
    public const MESSAGE_DEUX_OPERATEURS = 'La correction d\'un SIREN exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_SANS_EXAMEN = 'Aucun examen n\'a conclu à une erreur pour ce SIREN : examinez d\'abord le SIREN de la société (editeur:societe:examiner-siren --decision=erreur).';

    public const MESSAGE_CABINET = 'Le SIREN d\'un cabinet d\'expertise comptable ne se corrige pas par cette procédure : son code d\'activité et son inscription à l\'Ordre ont été vérifiés sous ce SIREN.';

    public const MESSAGE_SIREN_PRIS = 'Ce SIREN ne peut pas être pris : il est celui d\'une autre inscription.';

    public const MESSAGE_SIRETS = 'Chaque magasin ouvert ou fermé temporairement reçoit un nouveau SIRET, et un seul (--siret=CODE:SIRET) : il en manque ou il y en a de trop.';

    public const MESSAGE_REPERTOIRE_INDISPONIBLE = 'Le répertoire Sirene de l\'INSEE ne répond pas pour le moment : rien n\'est corrigé, réessayez plus tard.';

    public const MESSAGE_SIRET_AUTRE_TERRITOIRE = 'Le nouveau SIRET du magasin %s est dans un autre territoire que le sien : un autre territoire passe par un nouveau magasin.';

    public const MESSAGE_MAGASINS_CHANGES = 'Les magasins de la société ont changé pendant la procédure : rien n\'est corrigé, relancez-la.';

    public const MESSAGE_NUMERO_TVA_A_CONFIRMER = 'Le n° de TVA de la société (%s) n\'est pas celui que formait son SIREN : donnez le nouveau (--numero-tva), ou confirmez qu\'il reste celui de l\'assujetti unique dont elle est membre (--assujetti-unique). Rien n\'est corrigé.';

    public const MESSAGE_NUMERO_TVA_OU_ASSUJETTI = 'Donnez le nouveau n° de TVA (--numero-tva) ou confirmez que l\'ancien reste celui de l\'assujetti unique (--assujetti-unique), pas les deux.';

    public const MESSAGE_CODE_DEJA_EMIS = 'Le magasin %s ne peut pas numéroter sous ce SIREN : une autre inscription de ce SIREN a déjà émis des documents sous son code, et ses numéros se répéteraient. Rien n\'est corrigé.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly SiretsDesMagasins $siretsDesMagasins,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  array<string, string>  $siretsParCode  le nouveau SIRET de chaque magasin ouvert ou fermé temporairement, par son code
     * @param  string|null  $numeroTva  le nouveau n° de TVA, s'il n'est pas celui que forme le nouveau SIREN
     * @param  bool  $assujettiUnique  l'éditeur confirme que l'ancien n° de TVA, qui n'était pas celui que formait l'ancien SIREN, reste celui de l'assujetti unique dont la société est membre
     *
     * @throws VerificationRefuseeException quand la société, l'examen, le répertoire, le n° de TVA ou les opérateurs ne permettent pas la correction
     * @throws LogicException
     */
    public function handle(Societe $societe, string $nouveauSiren, array $siretsParCode, ?string $numeroTva, InterventionDeLEditeur $editeur, bool $assujettiUnique = false): Societe
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new VerificationRefuseeException(self::MESSAGE_DEUX_OPERATEURS);
        }

        if ($numeroTva !== null && $assujettiUnique) {
            throw new VerificationRefuseeException(self::MESSAGE_NUMERO_TVA_OU_ASSUJETTI);
        }

        if ($numeroTva !== null && ! NumeroTva::estAuFormat($numeroTva)) {
            throw new VerificationRefuseeException(NumeroTvaAuFormat::MESSAGE);
        }

        $nouveauSiren = (string) preg_replace('/\s+/', '', $nouveauSiren);
        $numeroTva = $numeroTva === null ? null : NumeroTva::normaliser($numeroTva);

        $corrigee = app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $nouveauSiren, $siretsParCode, $numeroTva, $assujettiUnique, $editeur): Societe {
            ExaminerSirenAction::exigerUneSocieteValideeNonRevoquee($societe);

            if ($societe->estUnCabinet()) {
                throw new VerificationRefuseeException(self::MESSAGE_CABINET);
            }

            $this->exigerUnExamenConcluantAUneErreur($nouveauSiren);
            $unite = $this->controleSirene->unite($nouveauSiren);

            if ($unite['resultat'] !== ResultatVerification::Concluante) {
                throw new VerificationRefuseeException($unite['motif'] ?? self::MESSAGE_REPERTOIRE_INDISPONIBLE);
            }

            if (Societe::sirenPris($nouveauSiren)) {
                throw new VerificationRefuseeException(self::MESSAGE_SIREN_PRIS);
            }

            $magasins = $societe->magasinsReels()->orderBy('id')->get();
            $etablissements = $this->etablissementsDesMagasins(self::quiChangentDeSiret($magasins), $siretsParCode, $nouveauSiren);

            return $this->operateur->pendantLeSysteme(fn (): Societe => $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $nouveauSiren, $numeroTva, $assujettiUnique, $editeur, $magasins, $etablissements, $unite): Societe {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
                ExaminerSirenAction::exigerUneSocieteValideeNonRevoquee($verrouillee);
                $relus = $verrouillee->magasinsReels()->orderBy('id')->get();

                if ($relus->modelKeys() !== $magasins->modelKeys() || self::quiChangentDeSiret($relus)->modelKeys() !== array_keys($etablissements)) {
                    throw new VerificationRefuseeException(self::MESSAGE_MAGASINS_CHANGES);
                }

                foreach (self::quiChangentDeSiret($relus) as $magasin) {
                    if (CodeMagasin::aServiSousCeSiren($verrouillee, (string) $magasin->code, $nouveauSiren)) {
                        throw new VerificationRefuseeException(sprintf(self::MESSAGE_CODE_DEJA_EMIS, $magasin->code));
                    }
                }

                $ancienSiren = (string) $verrouillee->siren;
                $ancienNumeroTva = $verrouillee->numero_tva;
                $nouveauNumeroTva = $numeroTva ?? self::numeroTvaApresLaCorrection($ancienNumeroTva, $ancienSiren, $nouveauSiren, $assujettiUnique);

                try {
                    $verrouillee->changerLeSirenParLaProcedure($nouveauSiren, $nouveauNumeroTva);
                } catch (UniqueConstraintViolationException) {
                    throw new VerificationRefuseeException(self::MESSAGE_SIREN_PRIS);
                }

                $this->controleSirene->noterUnite($verrouillee, $unite, editeur: $editeur);
                $anciensSirets = [];
                $nouveauxSirets = [];

                foreach (self::quiChangentDeSiret($relus) as $magasin) {
                    app(TenantContext::class)->runAs($magasin, function () use ($magasin, $etablissements, $editeur, &$anciensSirets, &$nouveauxSirets): void {
                        $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();
                        $consultation = $etablissements[(int) $magasin->getKey()];
                        $anciensSirets[(string) $verrouille->code] = (string) $verrouille->siret;

                        try {
                            $this->siretsDesMagasins->remplacer($verrouille, $consultation['etablissement']->siret);
                        } catch (SiretDejaReserveException) {
                            throw new VerificationRefuseeException(SiretsDesMagasins::MESSAGE_SIRET_RESERVE);
                        }

                        $nouveauxSirets[(string) $verrouille->code] = (string) $verrouille->siret;
                        $this->controleSirene->noterEtablissement($verrouille, $consultation, editeur: $editeur);
                    });
                }

                $this->journalAudit->inscrire(ActionAuditee::SirenCorrige, null, null,
                    ['siren' => $ancienSiren, 'numero_tva' => $ancienNumeroTva, 'sirets' => $anciensSirets],
                    ['siren' => $nouveauSiren, 'numero_tva' => $nouveauNumeroTva, 'sirets' => $nouveauxSirets],
                    $verrouillee, $editeur);

                return $verrouillee;
            }));
        });

        $this->prevenirLeProprietaire($corrigee);

        return $corrigee;
    }

    /**
     * Le n° de TVA de la société après la correction de son SIREN, sans n°
     * donné par l'éditeur : aucun pour une société qui n'en a pas ; celui
     * que forme le nouveau SIREN pour celle dont le n° était celui que
     * formait l'ancien ; l'ancien n°, qui ne l'était pas, seulement si
     * l'éditeur confirme qu'il reste celui de l'assujetti unique dont la
     * société est membre.
     *
     * @throws VerificationRefuseeException
     */
    private static function numeroTvaApresLaCorrection(?string $ancienNumeroTva, string $ancienSiren, string $nouveauSiren, bool $assujettiUnique): ?string
    {
        return match (true) {
            $ancienNumeroTva === null => null,
            $ancienNumeroTva === IdentifiantsInsee::numeroTvaPropose($ancienSiren) => IdentifiantsInsee::numeroTvaPropose($nouveauSiren),
            $assujettiUnique => $ancienNumeroTva,
            default => throw new VerificationRefuseeException(sprintf(self::MESSAGE_NUMERO_TVA_A_CONFIRMER, $ancienNumeroTva)),
        };
    }

    /**
     * Les magasins réels qui peuvent encore vendre, et reçoivent donc leur
     * nouveau SIRET : ouverts, ou fermés temporairement.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return Collection<int, Magasin>
     */
    private static function quiChangentDeSiret(Collection $magasins): Collection
    {
        return $magasins->filter(fn (Magasin $magasin): bool => ! $magasin->estFerme() || $magasin->motif_fermeture === MotifFermeture::Temporaire)->values();
    }

    /**
     * Le dernier examen du SIREN de la société a-t-il conclu à une erreur,
     * pour ce SIREN ?
     *
     * @throws VerificationRefuseeException
     */
    private function exigerUnExamenConcluantAUneErreur(string $nouveauSiren): void
    {
        $examen = EntreeAudit::query()->where('action', ActionAuditee::SirenExamine->value)->orderByDesc('id')->first();

        if ($examen === null
            || ($examen->nouvelles_valeurs['decision'] ?? null) !== DecisionExamenSiren::Erreur->value
            || ($examen->nouvelles_valeurs['siren_correct'] ?? null) !== $nouveauSiren) {
            throw new VerificationRefuseeException(self::MESSAGE_SANS_EXAMEN);
        }
    }

    /**
     * Le contrôle au répertoire Sirene du nouveau SIRET de chacun de ces
     * magasins, par l'identifiant du magasin : un établissement actif,
     * rattaché au nouveau SIREN, du même territoire, qu'aucun magasin ne
     * réserve.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  array<string, string>  $siretsParCode
     * @return array<int, array{resultat: ResultatVerification, etablissement: EtablissementSirene, motif: string|null}>
     *
     * @throws VerificationRefuseeException
     */
    private function etablissementsDesMagasins(Collection $magasins, array $siretsParCode, string $nouveauSiren): array
    {
        if (array_diff(array_keys($siretsParCode), $magasins->map(fn (Magasin $magasin): string => (string) $magasin->code)->all()) !== []) {
            throw new VerificationRefuseeException(self::MESSAGE_SIRETS);
        }

        $etablissements = [];

        foreach ($magasins as $magasin) {
            $siret = $siretsParCode[(string) $magasin->code] ?? throw new VerificationRefuseeException(self::MESSAGE_SIRETS);
            $consultation = $this->controleSirene->etablissement($siret, $nouveauSiren);

            if ($consultation['resultat'] !== ResultatVerification::Concluante || $consultation['etablissement'] === null) {
                throw new VerificationRefuseeException($consultation['motif'] ?? self::MESSAGE_REPERTOIRE_INDISPONIBLE);
            }

            if (ControleSirene::territoireDe($consultation['etablissement'])['territoire'] !== $magasin->territoire) {
                throw new VerificationRefuseeException(sprintf(self::MESSAGE_SIRET_AUTRE_TERRITOIRE, $magasin->code));
            }

            if (SiretsDesMagasins::estReserve($siret)) {
                throw new VerificationRefuseeException(SiretsDesMagasins::MESSAGE_SIRET_RESERVE);
            }

            $etablissements[(int) $magasin->getKey()] = $consultation;
        }

        return $etablissements;
    }

    /**
     * Le propriétaire apprend la correction dans l'espace de sa société,
     * sans la justification de l'éditeur, qui reste interne ; un échec est
     * signalé sans rien défaire.
     */
    private function prevenirLeProprietaire(Societe $societe): void
    {
        try {
            app(TenantContext::class)->runAsSociete($societe, fn () => $societe->proprietaire()->first()?->notify(new AvisDeVerification(
                $societe,
                'Le SIREN de votre société est corrigé',
                "L'éditeur a corrigé le SIREN de votre société, désormais {$societe->siren}, ainsi que le SIRET de chacun de vos magasins ouverts ou fermés temporairement. Vos documents déjà émis gardent l'ancien SIREN, tels qu'ils ont été signés. Vérifiez l'immatriculation et le n° de TVA de votre société dans son identité.",
                AvisDeVerification::ATTENTION,
            )));
        } catch (Throwable $erreur) {
            report($erreur);
        }
    }
}
