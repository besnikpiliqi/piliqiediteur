<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\OrganeDeLaDecision;
use App\Domain\Tenancy\Models\ChangementFinExercice;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ExercicesDeLaSociete;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Collection;
use LogicException;

/**
 * La procédure de l'éditeur qui change légalement la date de clôture de
 * l'exercice d'une société, sur procès-verbal
 * (editeur:societe:changer-fin-exercice) : son mois de fin d'exercice,
 * verrouillé dès la première vente de l'un de ses magasins (voir
 * Societe::aVenduUneFois()), ne change que par elle. Elle reçoit la date
 * de fin de l'exercice en cours que fixe le procès-verbal : inchangée, le
 * changement vaut à partir de l'exercice suivant, qui est transitoire ;
 * avancée, l'exercice en cours, transitoire, finit à cette date, et le
 * suivant au nouveau mois : la procédure n'accepte qu'un exercice
 * raccourci, terminé le dernier jour d'un mois, jamais un exercice
 * allongé (décisions T28.1 et T28.2 de l'onglet « À faire vérifier par un
 * professionnel », appliquées sans attendre, D76). Elle montre les
 * exercices qu'aurait la société (voir apercu()) et garde l'organe qui a
 * décidé, la date de la décision, sa pièce, la référence de son dépôt au
 * registre s'il y en a une, et le nom d'un expert-comptable consulté,
 * facultatif.
 *
 * Refusée, sans rien écrire : un nouveau mois égal à l'ancien ; une
 * décision postérieure à aujourd'hui, ou à la fin de l'exercice qu'elle
 * fixe (un changement de la date de clôture n'est jamais rétroactif : un
 * exercice ne se modifie qu'avant sa clôture) ; une fin qui n'est pas le
 * dernier jour d'un mois, qui tombe dans un exercice déjà clos (avant le
 * début de l'exercice en cours), ou qui repousserait la clôture
 * obligatoire de l'exercice en cours (après sa fin) ; une décision sans son
 * organe ; un nom d'expert-comptable ou une référence de dépôt trop longs.
 * Une fin déjà passée, dans l'exercice en cours, décidée
 * avant elle, sera rattrapée par les clôtures d'exercice de l'étape 3,
 * comme les journées manquées.
 *
 * Sous le verrou des registres des magasins réels de la société, puis de
 * sa ligne : le nouveau mois s'enregistre et s'inscrit au journal des
 * événements de chacun (JET 270 mois_fin_exercice, voir
 * JournaliseSesParametresDeConformite), comme la fin avancée de
 * l'exercice en cours (JET 270 fin_exercice_en_cours) ; le changement
 * s'inscrit dans changements_fin_exercice (voir ChangementFinExercice) et
 * au journal d'audit, avec l'intervention de l'éditeur. Jamais un
 * exercice clos ni une archive ne se modifient, et aucune clôture ne se
 * repousse.
 */
class ChangerFinExerciceAction
{
    public const MESSAGE_CABINET = 'Un cabinet d\'expertise comptable n\'a pas d\'exercice dans PILIQI : il n\'a ni magasin ni document de vente.';

    public const MESSAGE_MOIS = 'Le nouveau mois de fin d\'exercice est un mois de 1 à 12, différent du mois actuel de la société.';

    public const MESSAGE_JOUR = 'La décision et la fin de l\'exercice en cours s\'écrivent « AAAA-MM-JJ ».';

    public const MESSAGE_DECISION_APRES_LA_FIN = 'La décision est postérieure à la fin de l\'exercice qu\'elle fixe (%s) : un changement de la date de clôture n\'est jamais rétroactif.';

    public const MESSAGE_DECISION_FUTURE = 'La décision ne peut pas être postérieure à aujourd\'hui.';

    public const MESSAGE_FIN_DE_MOIS = 'La fin d\'un exercice est le dernier jour d\'un mois. PILIQI n\'accepte qu\'un exercice raccourci, terminé le dernier jour d\'un mois.';

    public const MESSAGE_EXERCICE_CLOS = 'Cette fin tombe avant le début de l\'exercice en cours (%s), dans un exercice déjà clos : un exercice clos ne se modifie jamais.';

    public const MESSAGE_CLOTURE_REPOUSSEE = 'Cette fin est postérieure à celle de l\'exercice en cours (%s) : elle repousserait une clôture obligatoire. PILIQI n\'accepte qu\'un exercice raccourci, terminé le dernier jour d\'un mois.';

    public const MESSAGE_ORGANE = 'Indiquez l\'organe qui a décidé le changement, tel que le nomme le procès-verbal : assemblée générale extraordinaire, décision des associés, organe prévu par les statuts, associé unique ou entrepreneur individuel.';

    public const MESSAGE_EXPERT_COMPTABLE_CONSULTE = 'Le nom de l\'expert-comptable consulté compte 150 caractères au plus.';

    public const MESSAGE_DEPOT_AU_REGISTRE = 'La référence du dépôt au registre compte 100 caractères au plus.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly ExercicesDeLaSociete $exercices,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * Les exercices qu'aurait la société après ce changement, sans rien
     * écrire : l'exercice en cours (son début, sa fin d'origine, sa fin
     * après le changement) et l'exercice suivant, chacun avec son nombre de
     * mois.
     *
     * @return array{debut: string, fin_d_origine: string, fin: string, mois_en_cours: int, suivant_debut: string, suivant_fin: string, mois_suivant: int}
     *
     * @throws LogicException
     */
    public function apercu(Societe $societe, int $nouveauMois, string $fin): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $nouveauMois, $fin): array {
            $aujourdhui = Calendrier::deParis()->aujourdhui();

            if (Calendrier::jourValide($fin) === null) {
                throw new LogicException(self::MESSAGE_JOUR);
            }

            $debut = $this->exercices->debutDeLExerciceEnCours($societe, $aujourdhui);
            $suivantDebut = Calendrier::ajouterJours($fin, 1);
            $suivantFin = ExercicesDeLaSociete::finDeMoisAuPlusTot($nouveauMois, $suivantDebut);

            return [
                'debut' => $debut,
                'fin_d_origine' => $this->exercices->finDeLExerciceEnCours($societe, $aujourdhui),
                'fin' => $fin,
                'mois_en_cours' => ExercicesDeLaSociete::nombreDeMois($debut, $fin),
                'suivant_debut' => $suivantDebut,
                'suivant_fin' => $suivantFin,
                'mois_suivant' => ExercicesDeLaSociete::nombreDeMois($suivantDebut, $suivantFin),
            ];
        });
    }

    /**
     * @param  string  $fin  la fin de l'exercice en cours tirée du procès-verbal (« AAAA-MM-JJ »)
     * @param  string  $decisionDu  le jour de la décision (« AAAA-MM-JJ »)
     * @param  OrganeDeLaDecision|null  $organe  l'organe qui a décidé, que nomme le procès-verbal ; null est refusé
     * @param  string|null  $dureeValideePar  l'expert-comptable consulté, s'il y en a un
     * @param  string|null  $depotAuRegistre  la référence du dépôt du procès-verbal au registre, s'il y en a une
     *
     * @throws LogicException quand le changement est refusé
     */
    public function handle(Societe $societe, int $nouveauMois, string $fin, string $decisionDu, ?OrganeDeLaDecision $organe, ?string $dureeValideePar, ?string $depotAuRegistre, InterventionDeLEditeur $editeur): ChangementFinExercice
    {
        if ($societe->estUnCabinet()) {
            throw new LogicException(self::MESSAGE_CABINET);
        }

        if ($organe === null) {
            throw new LogicException(self::MESSAGE_ORGANE);
        }

        $dureeValideePar = self::texteFacultatif($dureeValideePar, 150, self::MESSAGE_EXPERT_COMPTABLE_CONSULTE);
        $depotAuRegistre = self::texteFacultatif($depotAuRegistre, 100, self::MESSAGE_DEPOT_AU_REGISTRE);

        return app(TenantContext::class)->runAsSociete($societe, fn (): ChangementFinExercice => $this->operateur->pendantLeSysteme(fn (): ChangementFinExercice => $this->registre->sousVerrouDesMagasins(
            $societe->magasinsReels()->orderBy('id')->get(),
            function () use ($societe, $nouveauMois, $fin, $decisionDu, $organe, $dureeValideePar, $depotAuRegistre, $editeur): ChangementFinExercice {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
                $apercu = $this->verifier($verrouillee, $nouveauMois, $fin, $decisionDu);
                $ancienMois = (int) $verrouillee->mois_fin_exercice;

                $verrouillee->changerLeMoisDeFinDExerciceParLaProcedure($nouveauMois);

                if ($fin !== $apercu['fin_d_origine']) {
                    $this->journaliserLaFinAvancee($verrouillee->magasinsReels()->orderBy('id')->get(), $apercu['fin_d_origine'], $fin);
                }

                $changement = tap((new ChangementFinExercice)->forceFill([
                    'ancien_mois' => $ancienMois,
                    'nouveau_mois' => $nouveauMois,
                    'debut_exercice_en_cours' => $apercu['debut'],
                    'fin_exercice_en_cours_avant' => $apercu['fin_d_origine'],
                    'fin_exercice_en_cours_apres' => $fin,
                    'decision_du' => $decisionDu,
                    'organe_de_la_decision' => $organe,
                    'piece_reference' => $editeur->piece,
                    'depot_au_registre' => $depotAuRegistre,
                    'duree_validee_par' => $dureeValideePar,
                    'justification' => $editeur->justification,
                    'operateur' => $editeur->operateur(),
                    'created_at' => now(),
                ]))->save();

                $this->journalAudit->inscrire(ActionAuditee::FinExerciceChangee, null, null, [
                    'mois_fin_exercice' => $ancienMois,
                    'fin_exercice_en_cours' => $apercu['fin_d_origine'],
                ], [
                    'mois_fin_exercice' => $nouveauMois,
                    'fin_exercice_en_cours' => $fin,
                    'decision_du' => $decisionDu,
                    'organe_de_la_decision' => $organe->value,
                    'duree_validee_par' => $dureeValideePar,
                ], $changement, $editeur);

                return $changement;
            },
        )));
    }

    /**
     * Un texte facultatif, sans espaces autour : null quand il est vide,
     * refusé avec ce message au-delà de cette longueur.
     *
     * @throws LogicException
     */
    private static function texteFacultatif(?string $texte, int $longueurMaximale, string $refus): ?string
    {
        $texte = trim((string) $texte);

        if (mb_strlen($texte) > $longueurMaximale) {
            throw new LogicException($refus);
        }

        return $texte === '' ? null : $texte;
    }

    /**
     * Les conditions du changement, lues sous les verrous : rend l'aperçu
     * des exercices qu'il donne.
     *
     * @return array{debut: string, fin_d_origine: string, fin: string, mois_en_cours: int, suivant_debut: string, suivant_fin: string, mois_suivant: int}
     *
     * @throws LogicException
     */
    private function verifier(Societe $verrouillee, int $nouveauMois, string $fin, string $decisionDu): array
    {
        if ($nouveauMois < 1 || $nouveauMois > 12 || $nouveauMois === (int) $verrouillee->mois_fin_exercice) {
            throw new LogicException(self::MESSAGE_MOIS);
        }

        if (Calendrier::jourValide($fin) === null || Calendrier::jourValide($decisionDu) === null) {
            throw new LogicException(self::MESSAGE_JOUR);
        }

        $apercu = $this->apercu($verrouillee, $nouveauMois, $fin);
        $afficher = static fn (string $jour): string => Calendrier::afficherJour($jour, 'd/m/Y');

        $refus = match (true) {
            $decisionDu > Calendrier::deParis()->aujourdhui() => self::MESSAGE_DECISION_FUTURE,
            ! ExercicesDeLaSociete::estUneFinDeMois($fin) => self::MESSAGE_FIN_DE_MOIS,
            $fin < $apercu['debut'] => sprintf(self::MESSAGE_EXERCICE_CLOS, $afficher($apercu['debut'])),
            $fin > $apercu['fin_d_origine'] => sprintf(self::MESSAGE_CLOTURE_REPOUSSEE, $afficher($apercu['fin_d_origine'])),
            $decisionDu > $fin => sprintf(self::MESSAGE_DECISION_APRES_LA_FIN, $afficher($fin)),
            default => null,
        };

        if ($refus !== null) {
            throw new LogicException($refus);
        }

        return $apercu;
    }

    /**
     * La fin avancée de l'exercice en cours, au journal des événements de
     * chaque magasin réel (JET 270 fin_exercice_en_cours), sous le verrou de
     * son registre, tenu par l'appelant.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function journaliserLaFinAvancee(Collection $magasins, string $finDOrigine, string $fin): void
    {
        foreach ($magasins as $magasin) {
            app(TenantContext::class)->runAs($magasin, fn () => $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => 'fin_exercice_en_cours',
                'ancienne_valeur' => $finDOrigine,
                'nouvelle_valeur' => $fin,
            ]));
        }
    }
}
