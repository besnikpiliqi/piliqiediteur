<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDuCreditDeTva;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Support\TextesDuCreditDeTva;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Models\User;
use Closure;

/**
 * Ce que PILIQI dit du crédit de TVA d'une société, selon son régime et
 * où en est sa déclaration (lot TVA27, maquette « Message TVA avant
 * PILIQI » approuvée par l'éditeur le 18/11/2026), à chaque affichage :
 *
 * - le message du tableau de bord et de la page des charges (voir
 *   message()), qui ne se ferme pas : le régime à choisir, en rouge ; le
 *   crédit en attente d'une déclaration encore à venir, à indiquer, saisi
 *   à vérifier ou non vérifié, en orange ou en bleu ; vérifié, en vert ;
 *   rien en franchise en base, ni dans un cabinet ;
 * - l'étape « Votre TVA avant PILIQI » de la liste du propriétaire (voir
 *   etape()) ;
 * - les lignes de l'« À faire » de l'espace de la société, pour son
 *   propriétaire (voir chosesAFaire()), et, pour son comptable, « Crédit
 *   de TVA de départ à vérifier » (voir aVerifier()).
 *
 * Il ne bloque rien : seul le régime de TVA, à choisir, arrête les
 * opérations commerciales réelles (décision de l'éditeur du 18/11/2026,
 * voir Magasin::refusDeVente()).
 *
 * @phpstan-type MessageDeLaTva array{etat: string, couleur: string, titre: string, texte: string, bouton: string|null, url: string|null, desactive: bool}
 */
final class MessagesDuCreditDeTva
{
    public function __construct(private readonly CreditsDeTva $credits) {}

    /**
     * Le message de la TVA pour ce compte, sur un écran de la société
     * ($pourLeComptable faux) ou de son comptable ; null quand il n'y a
     * rien à dire (franchise en base, cabinet). Le régime à choisir se dit
     * à tout compte, qui ne peut plus vendre ; le crédit, seulement à qui
     * peut l'indiquer ou voit la TVA de toute la société avec son report
     * ($voitLeCredit, lu seulement pour qui ne peut pas l'indiquer), les
     * autres n'en voyant pas le montant. Le bouton
     * n'est donné qu'à qui peut faire le geste, avec son adresse : les
     * paramètres fiscaux pour le régime, $urlDuCredit pour le crédit ;
     * « desactive » quand le crédit ne se déclare pas encore.
     *
     * @param  Closure(): bool  $voitLeCredit
     * @return MessageDeLaTva|null
     */
    public function message(Societe $societe, User $compte, bool $pourLeComptable, ?string $urlDuCredit, Closure $voitLeCredit): ?array
    {
        if ($societe->estUnCabinet() || ! $societe->type_activite->aDesMagasins()) {
            return null;
        }

        if ($societe->regime_tva === null) {
            $regle = $compte->can('voirLesParametresFiscaux', $societe);

            return [
                'etat' => 'regime_a_choisir',
                'couleur' => 'danger',
                'titre' => $regle ? 'Choisissez d\'abord le régime de TVA de la société' : 'Le régime de TVA de la société n\'est pas encore choisi',
                'texte' => $regle
                    ? 'Sans régime, PILIQI ne sait pas comment calculer votre TVA et refuse toute vente. Choisissez-le dans les paramètres fiscaux.'
                    : 'Sans régime, PILIQI ne sait pas comment calculer la TVA et refuse toute vente : demandez-le au propriétaire.',
                'bouton' => $regle ? 'Ouvrir les paramètres fiscaux' : null,
                'url' => $regle ? ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $societe) : null,
                'desactive' => false,
            ];
        }

        $cadence = $societe->regime_tva->cadence();
        $declare = $this->credits->peutDeclarer($compte, $societe);

        if ($cadence === null || (! $declare && ! $voitLeCredit())) {
            return null;
        }

        $ancre = $this->credits->ancre($societe);
        $url = $declare ? $urlDuCredit : null;

        if ($ancre === null) {
            return $this->sansDeclaration($societe, $cadence, $declare, $url);
        }

        $etat = $this->credits->etat($societe, $ancre);
        $solde = TextesDuCreditDeTva::soldeDeclare($ancre);
        $paris = Calendrier::deParis();

        return match ($etat) {
            EtatDuCreditDeTva::Verifie => [
                'etat' => 'verifie',
                'couleur' => 'success',
                'titre' => ($pourLeComptable ? 'Crédit de TVA vérifié le ' : 'Crédit de TVA vérifié par votre comptable le ').$paris->afficher($ancre->verifie_le ?? $ancre->saisi_le, 'd/m/Y'),
                'texte' => $ancre->solde === 0
                    ? 'Aucun crédit à reporter de la déclaration '.TextesDuCreditDeTva::deLaPeriode($ancre->cadence, $ancre->periode).'.'
                    : 'Crédit reporté de la déclaration '.TextesDuCreditDeTva::deLaPeriode($ancre->cadence, $ancre->periode).' : '.TextesDuCreditDeTva::euros($ancre->credit()).'.',
                'bouton' => $declare ? ($pourLeComptable ? 'Voir' : 'Modifier') : null,
                'url' => $url,
                'desactive' => false,
            ],
            EtatDuCreditDeTva::AVerifier => [
                'etat' => 'a_verifier',
                'couleur' => 'info',
                'titre' => $pourLeComptable ? 'Crédit de TVA de départ à vérifier : '.$solde : 'Crédit de TVA saisi : '.$solde.', à vérifier par votre comptable',
                'texte' => $pourLeComptable
                    ? 'Saisi par '.$ancre->saisi_par_nom.' le '.$paris->afficher($ancre->saisi_le, 'd/m/Y').'. Il compte déjà dans la TVA : confirmez-le, ou corrigez-le avec un motif.'
                    : 'Il compte déjà dans votre TVA. Votre comptable le confirme ou le corrige.',
                'bouton' => $declare ? ($pourLeComptable ? 'Vérifier' : 'Modifier') : null,
                'url' => $url,
                'desactive' => false,
            ],
            EtatDuCreditDeTva::NonVerifie => [
                'etat' => 'non_verifie',
                'couleur' => 'warning',
                'titre' => 'Crédit de TVA saisi : '.$solde.', non vérifié par un comptable',
                'texte' => CreditsDeTva::CONSEIL,
                'bouton' => $declare ? 'Modifier' : null,
                'url' => $url,
                'desactive' => false,
            ],
        };
    }

    /**
     * L'étape « Votre TVA avant PILIQI » de la liste du propriétaire, au
     * réel normal seulement, faite dès qu'une déclaration sert d'ancre.
     *
     * @return list<array{titre: string, faite: bool, etat: string, url: string|null}>
     */
    public function etape(User $compte, Societe $societe, ?string $url): array
    {
        $cadence = $societe->regime_tva?->cadence();

        if ($cadence === null || ! CreditsDeTva::aLieuDansLa($societe) || ! $this->credits->estLeProprietaire($compte, $societe)) {
            return [];
        }

        $ancre = $this->credits->ancre($societe);
        $premiere = CreditsDeTva::premierePeriode($societe, $cadence);

        return [[
            'titre' => TextesDuCreditDeTva::TITRE_DE_L_ETAPE,
            'faite' => $ancre !== null,
            'etat' => match (true) {
                $ancre !== null => 'Indiqué : '.TextesDuCreditDeTva::soldeDeclare($ancre).', '.mb_strtolower(TextesDuCreditDeTva::etat($this->credits->etat($societe, $ancre), false)).'.',
                CreditsDeTva::periodesDeclarables($societe, $cadence, Calendrier::deParis()->aujourdhui()) === [] => 'À indiquer à partir du '.Calendrier::afficherJour(CreditsDeTva::premiereDeclarationLe($societe, $cadence), 'd/m/Y').', avec la déclaration '.TextesDuCreditDeTva::deLaPeriode($cadence, $premiere).'.',
                default => 'Indiquez le crédit de TVA de votre déclaration '.TextesDuCreditDeTva::deLaPeriode($cadence, $premiere).', ou d\'une plus récente : négatif pour un crédit, 0 € si vous n\'en avez pas.',
            },
            'url' => $url,
        ]];
    }

    /**
     * Les lignes de l'« À faire » de l'espace de la société que laisse son
     * crédit de TVA, pour son propriétaire seul : à déclarer quand sa
     * déclaration sera déposée, en gris ; à indiquer, en orange ; saisi, en
     * attente de la vérification du comptable ou non vérifié, en gris ;
     * rien une fois vérifié.
     *
     * @return list<ChoseAFaire>
     */
    public function chosesAFaire(User $compte, Societe $societe, string $url): array
    {
        $cadence = $societe->regime_tva?->cadence();

        if ($cadence === null || ! CreditsDeTva::aLieuDansLa($societe) || ! $this->credits->estLeProprietaire($compte, $societe)) {
            return [];
        }

        $ancre = $this->credits->ancre($societe);
        $premiere = CreditsDeTva::premierePeriode($societe, $cadence);

        if ($ancre === null) {
            $le = CreditsDeTva::premiereDeclarationLe($societe, $cadence);

            return CreditsDeTva::periodesDeclarables($societe, $cadence, Calendrier::deParis()->aujourdhui()) === []
                ? [new ChoseAFaire(
                    cle: 'credit_de_tva_a_venir',
                    groupe: 'TVA',
                    urgence: UrgenceAFaire::Gris,
                    nombre: 1,
                    phrase: 'Crédit de TVA à déclarer à partir du '.Calendrier::afficherJour($le, 'd/m/Y').' (déclaration '.TextesDuCreditDeTva::deLaPeriode($cadence, $premiere).').',
                    url: null,
                    bouton: 'Voir',
                    aSurveiller: true,
                    date: $le,
                )]
                : [new ChoseAFaire(
                    cle: 'credit_de_tva_a_indiquer',
                    groupe: 'TVA',
                    urgence: UrgenceAFaire::Orange,
                    nombre: 1,
                    phrase: 'Indiquez le crédit de TVA de votre dernière déclaration.',
                    url: $url,
                    bouton: 'Saisir',
                    aSurveiller: false,
                    date: $le,
                    precision: 'Tant qu\'il manque, la TVA à payer est une estimation sans crédit. Vous pouvez le déclarer pour n\'importe quel '.$cadence->nomDeLaPeriode().' depuis votre arrivée ('.$cadence->libelle($premiere).').',
                )];
        }

        return match ($this->credits->etat($societe, $ancre)) {
            EtatDuCreditDeTva::Verifie => [],
            EtatDuCreditDeTva::AVerifier => [self::enAttente('Crédit de TVA saisi, en attente de la vérification de votre comptable.', $url, $ancre)],
            EtatDuCreditDeTva::NonVerifie => [self::enAttente('Crédit de TVA saisi, non vérifié par un comptable : faites-le vérifier par votre expert-comptable.', $url, $ancre)],
        };
    }

    /**
     * Pour le comptable de la société : la déclaration qui sert d'ancre
     * attend-elle sa vérification ?
     */
    public function aVerifier(User $compte, Societe $societe): bool
    {
        if (! CreditsDeTva::aLieuDansLa($societe) || ! $this->credits->peutVerifier($compte, $societe)) {
            return false;
        }

        $ancre = $this->credits->ancre($societe);

        return $ancre !== null && ! $ancre->estVerifie();
    }

    /**
     * Le message sans déclaration : en attente d'une période qui ne se
     * déclare pas encore, ou à indiquer.
     *
     * @return MessageDeLaTva
     */
    private function sansDeclaration(Societe $societe, CadenceDeLaTva $cadence, bool $declare, ?string $url): array
    {
        $premiere = CreditsDeTva::premierePeriode($societe, $cadence);
        $nom = $cadence->nomDeLaPeriode();
        $periodeDArrivee = ($cadence === CadenceDeLaTva::Mensuelle ? 'du mois d\'arrivée (' : 'du trimestre d\'arrivée (').$cadence->libelle($premiere).') ou d\'un '.$nom.' suivant';

        if (CreditsDeTva::periodesDeclarables($societe, $cadence, Calendrier::deParis()->aujourdhui()) === []) {
            return [
                'etat' => 'attente',
                'couleur' => 'warning',
                'titre' => 'En attente de la déclaration de votre crédit de TVA',
                'texte' => 'PILIQI calcule déjà votre TVA depuis le '.Calendrier::afficherJour(CreditsDeTva::jourDArrivee($societe), 'd/m/Y').'. Pour qu\'elle soit exacte, indiquez le crédit de TVA de l\'une de vos déclarations, '.$periodeDArrivee.'. Vous pourrez le saisir à partir du '.Calendrier::afficherJour(CreditsDeTva::premiereDeclarationLe($societe, $cadence), 'd/m/Y').', avec la déclaration '.TextesDuCreditDeTva::deLaPeriode($cadence, $premiere).'.',
                'bouton' => $declare ? 'Indiquer mon crédit de TVA' : null,
                'url' => null,
                'desactive' => true,
            ];
        }

        return [
            'etat' => 'a_saisir',
            'couleur' => 'warning',
            'titre' => 'Indiquez le crédit de TVA de votre déclaration '.TextesDuCreditDeTva::deLaPeriode($cadence, $premiere).', ou d\'une plus récente',
            'texte' => 'Tant qu\'il manque, le montant à payer est une estimation sans crédit : il peut changer. Vous pouvez le déclarer pour n\'importe quel '.$nom.' depuis votre arrivée.',
            'bouton' => $declare ? 'Indiquer mon crédit de TVA' : null,
            'url' => $url,
            'desactive' => false,
        ];
    }

    private static function enAttente(string $phrase, string $url, CreditDeTva $ancre): ChoseAFaire
    {
        return new ChoseAFaire(
            cle: 'credit_de_tva_saisi',
            groupe: 'TVA',
            urgence: UrgenceAFaire::Gris,
            nombre: 1,
            phrase: $phrase,
            url: $url,
            bouton: 'Voir',
            aSurveiller: true,
            date: Calendrier::deParis()->jourDe($ancre->saisi_le),
            precision: 'Indiqué : '.TextesDuCreditDeTva::soldeDeclare($ancre).'. '.CreditsDeTva::CONSEIL,
        );
    }
}
