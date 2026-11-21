<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Actions\CorrigerUneChargeAction;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\GererLesTypesDeChargeAction;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\SaisirUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;

/**
 * Les charges des sociétés semées (page « Charges », lot CHARGES), mois
 * après mois, chacune par sa vraie action, au nom de qui fait le geste : le
 * 5 de chaque mois, le propriétaire dépose les charges du mois précédent
 * avec leur pièce et leurs chiffres (« à valider ») ; le 12, le comptable de
 * la société (le collaborateur du cabinet lié à qui elle est confiée, ou
 * l'expert-comptable qu'elle a déclaré) les valide, sauf celles du dernier
 * mois, qui restent à valider. S'y ajoutent les autres cas de la page : les
 * types habituels et les types propres à la société (dont un archivé), une
 * charge de « Toute la société » et des charges de magasins, plusieurs taux
 * et une charge sans TVA, la TVA déductible proposée à 0 % (véhicule de
 * tourisme) et à 80 % (carburant), un fournisseur établi hors de France
 * (autoliquidé), une TVA recopiée au-delà d'un centime (« TVA à vérifier »),
 * un doublon probable confirmé, une charge renvoyée au client puis
 * corrigée par lui et validée (le mois des cas particuliers, deux mois et
 * demi avant la fin), une charge renvoyée qui attend encore, une
 * charge validée puis corrigée par le comptable, une charge que le
 * comptable ajoute lui-même (née validée) et une pièce sans chiffres.
 */
final class ChargesDesSocietes
{
    /**
     * Le nombre de jours avant la fin de l'histoire où le Comptoir des Îles
     * commence à déposer ses charges.
     */
    private const DEBUT_DES_CHARGES_DU_COMPTOIR = 120;

    /**
     * Le type « societe:{uuid} » de chaque type propre semé, par nom.
     *
     * @var array<string, string>
     */
    private array $typesPropres = [];

    /**
     * Les charges déposées et pas encore validées, par société, pour la
     * validation du 12.
     *
     * @var array<string, list<string>>
     */
    private array $aValider = [];

    /**
     * La charge renvoyée au client le mois des cas particuliers, qu'il
     * corrige ensuite.
     */
    private ?string $renvoyee = null;

    /**
     * La charge de l'avant-dernier mois que le comptable renvoie et que le
     * client n'a pas encore corrigée.
     */
    private ?string $enAttente = null;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
    ) {}

    /**
     * Les réglages des types de charge de la société « Commerce ou atelier »
     * par son propriétaire : ses types habituels, deux types propres, dont
     * un archivé ensuite.
     */
    public function preparer(): void
    {
        if (! $this->semis->seme('commerce')) {
            return;
        }

        $proprietaire = $this->semis->proprietaire('commerce');
        $societe = $this->semis->societe('commerce');
        $types = app(GererLesTypesDeChargeAction::class);

        $this->horsDeToutContexte(function () use ($types, $proprietaire, $societe): void {
            $types->choisirLesHabituels($proprietaire, $societe, [TypeDeCharge::Loyer, TypeDeCharge::Electricite, TypeDeCharge::TelephoneEtInternet, TypeDeCharge::AbonnementsEtLogiciels]);
            $this->typesPropres['Nettoyage de la vitrine'] = 'societe:'.$types->ajouter($proprietaire, $societe, 'Nettoyage de la vitrine', NatureDeCharge::Services)->uuid;
            $ancien = $types->ajouter($proprietaire, $societe, 'Abonnement presse locale', NatureDeCharge::Biens);
            $types->archiver($proprietaire, $societe, (string) $ancien->uuid, true);
        });
    }

    /**
     * Le travail des charges de ce jour de l'histoire, selon son quantième
     * dans le calendrier de Paris : dépôt le 5, validation le 12 ; le mois
     * des cas particuliers, renvoi le 12, correction par le client le 15 et
     * validation le 20, correction d'une charge validée le 20.
     */
    public function auJour(int $rang): void
    {
        $jour = $this->horloge->jour($rang);
        $quantieme = (int) substr($jour, 8, 2);
        $moisPrecedent = substr(Calendrier::ajouterJours(Calendrier::premierJourDuMois($jour), -1), 0, 7);
        $casParticuliers = $this->semis->seme('commerce') && substr($jour, 0, 7) === substr($this->horloge->jour(SemisComplet::JOURS - 75), 0, 7);

        if ($quantieme === 5) {
            $this->horloge->aller($rang, '08:00');
            $this->deposerLeMois($moisPrecedent, $casParticuliers);
        }

        if ($quantieme === 12 && ! $this->estLeDernierMois($rang)) {
            $this->horloge->aller($rang, '08:10');
            $this->validerLeMois();
        }

        if ($quantieme === 6 && $this->semis->surLePoste() && SemisComplet::JOURS - $rang < self::DEBUT_DES_CHARGES_DU_COMPTOIR) {
            $this->horloge->aller($rang, '08:00');
            $this->chargesDuComptoir($moisPrecedent);
        }

        if ($casParticuliers && $quantieme === 15 && $this->renvoyee !== null) {
            $this->horloge->aller($rang, '08:15');
            $this->horsDeToutContexte(fn (): Charge => app(SaisirUneChargeAction::class)->handle($this->semis->proprietaire('commerce'), $this->semis->societe('commerce'), (string) $this->renvoyee, $this->saisie('piliqi:electricite', 'halles', 'EDF Entreprises', 'EDF-'.$moisPrecedent.'-B', $moisPrecedent.'-28', [[TauxDeCharge::Vingt, 21480]]), $this->piece('EDF Entreprises'), GestesDuMagasin::jeton()));
        }

        if ($casParticuliers && $quantieme === 20 && $this->renvoyee !== null) {
            $this->horloge->aller($rang, '08:20');
            $paul = $this->semis->compte('Paul Lefèvre');
            $this->valider($paul, 'commerce', (string) $this->renvoyee);
            $this->renvoyee = null;
            $derniere = $this->horsDeToutContexte(fn (): Charge => Charge::query()->withoutGlobalScopes()->where('societe_id', $this->semis->societe('commerce')->getKey())->where('fournisseur', 'Orange Pro')->whereNotNull('validee_le')->latest('id')->firstOrFail());
            $this->horsDeToutContexte(fn (): Charge => app(CorrigerUneChargeAction::class)->handle($paul, $this->semis->societe('commerce'), (string) $derniere->uuid, $this->saisie('piliqi:telephone_internet', null, 'Orange Pro', null, (string) $derniere->date_piece, [[TauxDeCharge::Vingt, 5490]]), 'Montant HT corrigé d\'après la facture : l\'option de la fibre était comptée deux fois.', GestesDuMagasin::jeton()));
        }
    }

    private function estLeDernierMois(int $rang): bool
    {
        return substr($this->horloge->jour($rang), 0, 7) === substr($this->horloge->jour(SemisComplet::JOURS - 1), 0, 7);
    }

    /**
     * Le propriétaire de chaque société dépose les charges de ce mois.
     */
    private function deposerLeMois(string $mois, bool $casParticuliers): void
    {
        $numero = (int) substr($mois, 5, 2);
        $premier = $mois.'-01';
        $paiement = $mois.'-05';

        if ($this->semis->seme('commerce')) {
            $this->deposerLeMoisDeMaisonFerrand($mois, $numero, $casParticuliers);
        }

        if ($this->semis->seme('services')) {
            $this->deposerLeMoisDuConseil($mois, $numero);
        }

        if ($this->semis->seme('client')) {
            $epicerie = $this->semis->proprietaire('client');
            $this->deposer($epicerie, 'client', $this->saisie('piliqi:loyer', 'epicerie', 'SCI Lemoine', 'SL-'.$mois, $premier, [[TauxDeCharge::Vingt, 70000]], $paiement));
            $this->deposer($epicerie, 'client', $this->saisie('piliqi:electricite', 'epicerie', 'EDF Entreprises', 'EDF-E-'.$mois, $mois.'-27', [[TauxDeCharge::Vingt, 9500 + 500 * ($numero % 5)]]));
        }

        if ($this->semis->seme('commerce') && $this->estLeDernierMoisDeposable($mois)) {
            $this->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($this->semis->proprietaire('commerce'), $this->semis->societe('commerce'), $this->saisie('piliqi:fournitures', 'halles', 'Bureau Plus', null, $mois.'-22', []), $this->piece('Bureau Plus'), GestesDuMagasin::jeton()));
        }
    }

    /**
     * Les charges du mois de Maison Ferrand, dont le loyer de son magasin de
     * Fort-de-France, à 8,5 %.
     */
    private function deposerLeMoisDeMaisonFerrand(string $mois, int $numero, bool $casParticuliers): void
    {
        $premier = $mois.'-01';
        $paiement = $mois.'-05';
        $commerce = $this->semis->proprietaire('commerce');

        $this->deposer($commerce, 'commerce', $this->saisie('piliqi:loyer', 'halles', 'SCI des Halles', 'QH-'.$mois, $premier, [[TauxDeCharge::Vingt, 150000]], $paiement));
        $this->deposer($commerce, 'commerce', $this->saisie('piliqi:loyer', 'republique', 'SCI République', 'QR-'.$mois, $premier, [[TauxDeCharge::Vingt, 110000]], $paiement));
        $this->deposer($commerce, 'commerce', $this->saisie('piliqi:loyer', 'martinique', 'SCI du Malecon', 'MAL-'.$mois, $premier, [[TauxDeCharge::HuitVirguleCinq, 95000]], $paiement));
        $this->deposer($commerce, 'commerce', $this->saisie('piliqi:telephone_internet', null, 'Orange Pro', null, $mois.'-10', [[TauxDeCharge::Vingt, 4990]], $mois.'-20'));
        $this->deposer($commerce, 'commerce', $this->saisie('piliqi:abonnements_logiciels', null, 'Cloud Software Ltd', 'CS-'.$mois, $premier, [[TauxDeCharge::Vingt, 2900]], $premier, horsDeFrance: true));
        $this->deposer($commerce, 'commerce', $this->saisie($this->typesPropres['Nettoyage de la vitrine'], 'halles', 'Net Vitrines', 'NV-'.$mois, $mois.'-15', [[TauxDeCharge::Vingt, 8000]]));

        if ($casParticuliers && $this->renvoyee === null) {
            $this->renvoyee = $this->deposer($commerce, 'commerce', $this->saisie('piliqi:electricite', 'halles', 'EDF Entreprises', 'EDF-'.$mois, $mois.'-28', [[TauxDeCharge::Vingt, 21480]]));
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:telephone_internet', null, 'Orange Pro', null, $mois.'-10', [[TauxDeCharge::Vingt, 4990]], doublonConfirme: true));
        } else {
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:electricite', 'halles', 'EDF Entreprises', 'EDF-'.$mois, $mois.'-28', [[TauxDeCharge::Vingt, 18000 + 700 * ($numero % 9)]]));
        }

        if ($numero % 2 === 0) {
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:carburant', null, 'TotalEnergies', 'TE-'.$mois, $mois.'-18', [[TauxDeCharge::Vingt, 8500]]));
        }

        if ($numero % 3 === 0) {
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:eau', 'halles', 'Eau de Paris', 'EP-'.$mois, $mois.'-20', [[TauxDeCharge::CinqVirguleCinq, 6000]]));
            $paul = $this->semis->compte('Paul Lefèvre');
            $this->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($paul, $this->semis->societe('commerce'), $this->saisie('piliqi:honoraires', null, 'Martin Expertise Comptable', 'MEC-'.$mois, $mois.'-25', [[TauxDeCharge::Vingt, 45000]]), $this->piece('Martin Expertise Comptable'), GestesDuMagasin::jeton()));
        }

        if ($numero === 12) {
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:cadeaux', null, 'Maison du Chocolat', 'MC-'.$mois, $mois.'-15', [[TauxDeCharge::CinqVirguleCinq, 9000]]));
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:vehicule_tourisme', null, 'Rent Auto', 'RA-'.$mois, $mois.'-08', [[TauxDeCharge::Vingt, 32000]]));
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:repas_receptions', null, 'Brasserie du Marché', 'BM-'.$mois, $mois.'-19', [[TauxDeCharge::Dix, 21000]]));
        }

        if ($casParticuliers) {
            $this->deposer($commerce, 'commerce', $this->saisie('piliqi:fournitures', 'republique', 'Bureau Plus', 'BP-'.$mois, $mois.'-11', [[TauxDeCharge::Vingt, 12000]], tvaRecopiee: 2405));
        }

    }

    /**
     * Les charges du mois de Conseil Vasseur, dont l'une, l'avant-dernier
     * mois, que son expert-comptable renverra.
     */
    private function deposerLeMoisDuConseil(string $mois, int $numero): void
    {
        $premier = $mois.'-01';
        $paiement = $mois.'-05';
        $services = $this->semis->proprietaire('services');

        if ($this->estLeDernierMoisDeposable(Calendrier::ajouterJours($mois.'-28', 31))) {
            $this->enAttente = $this->deposer($services, 'services', $this->saisie('piliqi:entretien_reparations', 'conseil', 'Clim Service', 'CL-'.$mois, $mois.'-14', [[TauxDeCharge::Vingt, 24000]]));
        }

        $this->deposer($services, 'services', $this->saisie('piliqi:loyer', 'conseil', 'Foncière Mozart', 'FM-'.$mois, $premier, [[TauxDeCharge::Vingt, 90000]], $paiement));
        $this->deposer($services, 'services', $this->saisie('piliqi:telephone_internet', null, 'Bouygues Pro', 'BY-'.$mois, $mois.'-12', [[TauxDeCharge::Vingt, 3990]]));

        if ($numero === 1) {
            $this->deposer($services, 'services', $this->saisie('piliqi:assurances', null, 'Mutuelle des Professions Libérales', 'MPL-'.$mois, $premier, [[TauxDeCharge::Zero, 84000]]));
        }

        if ($numero % 4 === 0) {
            $this->deposer($services, 'services', $this->saisie('piliqi:formation', null, 'Institut du Conseil', 'IC-'.$mois, $mois.'-21', [[TauxDeCharge::Vingt, 60000]]));
        }

    }

    /**
     * Le mois de dépôt du dernier mois de l'histoire, dont une pièce reste
     * sans chiffres.
     */
    private function estLeDernierMoisDeposable(string $moisOuJour): bool
    {
        $dernierJour = $this->horloge->jour(SemisComplet::JOURS - 1);

        return substr($moisOuJour, 0, 7) === substr(Calendrier::ajouterJours(Calendrier::premierJourDuMois($dernierJour), -1), 0, 7);
    }

    /**
     * Le comptable de chaque société valide les charges déposées ; le mois
     * des cas particuliers, le collaborateur du cabinet renvoie au client
     * l'électricité des Halles, qu'il corrige ensuite, et, l'avant-dernier
     * mois, l'expert-comptable renvoie une charge que le client n'a pas
     * encore corrigée.
     */
    private function validerLeMois(): void
    {
        $comptables = array_filter(['commerce' => 'Paul Lefèvre', 'services' => 'Marc Petit', 'client' => 'Emma Rousseau'], fn (string $societe): bool => $this->semis->seme($societe), ARRAY_FILTER_USE_KEY);

        foreach ($comptables as $societe => $nom) {
            $comptable = $this->semis->compte($nom);

            foreach ($this->aValider[$societe] ?? [] as $charge) {
                if ($charge === $this->enAttente) {
                    $this->horsDeToutContexte(fn (): Charge => app(RenvoyerUneChargeAction::class)->handle($comptable, $this->semis->societe($societe), $charge, 'Cette facture est adressée à une autre société : merci de vérifier le destinataire avant de la redéposer.', GestesDuMagasin::jeton()));

                    continue;
                }

                if ($charge === $this->renvoyee) {
                    $this->horsDeToutContexte(fn (): Charge => app(RenvoyerUneChargeAction::class)->handle($comptable, $this->semis->societe($societe), $charge, 'Il manque la seconde page de la facture : merci de joindre la facture complète et de vérifier le montant HT.', GestesDuMagasin::jeton()));

                    continue;
                }

                $this->valider($comptable, $societe, $charge);
            }

            $this->aValider[$societe] = [];
        }

    }

    /**
     * Le 6, le propriétaire du Comptoir des Îles, qui n'a ni cabinet lié ni
     * expert-comptable déclaré, dépose les charges du mois précédent de son
     * magasin de la Martinique (loyer à 8,5 %, épicerie du personnel à
     * 2,1 %, assurance à 0 %) et les valide lui-même : « validée par le
     * propriétaire ».
     */
    private function chargesDuComptoir(string $mois): void
    {
        $proprietaire = $this->semis->proprietaire('comptoir');

        foreach ([
            $this->saisie('piliqi:loyer', 'fort-de-france', 'SCI Les Alizés', 'ALIZES-'.$mois, $mois.'-01', [[TauxDeCharge::HuitVirguleCinq, 145000]]),
            $this->saisie('piliqi:fournitures', 'fort-de-france', 'Épicerie du Morne', null, $mois.'-18', [[TauxDeCharge::DeuxVirguleUn, 4280]]),
            $this->saisie('piliqi:assurances', null, 'Assurances Caraïbes', 'AC-'.$mois, $mois.'-02', [[TauxDeCharge::Zero, 9600]]),
        ] as $saisie) {
            $charge = $this->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($proprietaire, $this->semis->societe('comptoir'), $saisie, $this->piece((string) $saisie->fournisseur), GestesDuMagasin::jeton()));
            $this->valider($proprietaire, 'comptoir', (string) $charge->uuid);
        }
    }

    private function valider(User $comptable, string $societe, string $charge): void
    {
        $this->horsDeToutContexte(fn (): Charge => app(ValiderUneChargeAction::class)->handle($comptable, $this->semis->societe($societe), $charge, GestesDuMagasin::jeton()));
    }

    /**
     * Une charge déposée par ce compte de la société, avec la pièce de son
     * fournisseur ; son uuid, gardé pour la validation.
     */
    private function deposer(User $auteur, string $societe, SaisieDeCharge $saisie): string
    {
        $charge = $this->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($auteur, $this->semis->societe($societe), $saisie, $this->piece((string) $saisie->fournisseur), GestesDuMagasin::jeton()));
        $this->aValider[$societe][] = (string) $charge->uuid;

        return (string) $charge->uuid;
    }

    /**
     * Ce qu'une personne écrit d'une charge : une ligne par taux, chaque HT
     * en centimes ; une TVA recopiée de la pièce qui s'écarte du calcul est
     * confirmée (« TVA à vérifier »).
     *
     * @param  list<array{0: TauxDeCharge, 1: int}>  $lignes
     */
    private function saisie(string $type, ?string $magasin, string $fournisseur, ?string $numero, string $datePiece, array $lignes, ?string $datePaiement = null, bool $horsDeFrance = false, ?int $tvaRecopiee = null, bool $doublonConfirme = false): SaisieDeCharge
    {
        return new SaisieDeCharge(
            type: $type,
            magasin: $magasin === null ? null : (string) $this->semis->magasin($magasin)->uuid,
            fournisseur: $fournisseur,
            numeroPiece: $numero,
            datePiece: $datePiece,
            datePaiement: $datePaiement,
            moisTva: null,
            fournisseurHorsFrance: $horsDeFrance,
            vehiculeUtilitaire: false,
            note: null,
            lignes: array_map(static fn (array $ligne): array => ['taux' => $ligne[0], 'ht' => $ligne[1], 'tva' => $tvaRecopiee, 'ttc' => null, 'tva_deductible' => null], $lignes),
            confirmeLesEcarts: $tvaRecopiee !== null,
            confirmeLeDoublon: $doublonConfirme,
        );
    }

    /**
     * La facture du fournisseur, en PDF, telle qu'il l'a envoyée.
     */
    private function piece(string $fournisseur): PieceDeposee
    {
        return new PieceDeposee('Facture '.$fournisseur.'.pdf', PieceDuSemis::pdf($fournisseur, 'Facture'));
    }

    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    private function horsDeToutContexte(Closure $geste): mixed
    {
        app(TenantContext::class)->clear();

        try {
            return $geste();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
