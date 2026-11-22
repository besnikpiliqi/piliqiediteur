<?php

namespace Database\Seeders\Semis;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Charges\Actions\ConfirmerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\CorrigerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\DeclarerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Actions\VerifierLeSeuilDuTrimestrielAction;
use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\CreditsDeTva;
use App\Domain\Charges\Services\SeuilDuTrimestriel;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\ReportDeLaPeriode;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Support\ValeursLues;
use LogicException;

/**
 * La TVA des sociétés semées (lot TVA27, cahier « fin du réel simplifié et
 * crédit de TVA reporté », point 6 de CLAUDE.md : tous les cas, de part et
 * d'autre de chaque limite), chaque geste par sa vraie action, au nom de
 * qui le fait :
 *
 * - le crédit de TVA de départ (choix K2 de l'éditeur) dans chacun de ses
 *   états : Maison Ferrand, −1 250,00 € saisis par son propriétaire, « à
 *   vérifier » par son comptable (l'« À faire » du cabinet Martin sur le
 *   poste et dans le modèle du cabinet, celui de son expert-comptable
 *   déclaré dans le modèle du commerce) ; l'Épicerie Lemoine, −480,00 €
 *   saisis par sa propriétaire et confirmés par Emma Rousseau ; Conseil
 *   Vasseur, −320,00 € saisis par son propriétaire, corrigés à −350,00 €
 *   par Marc Petit, son expert-comptable déclaré, avec un motif, puis une
 *   déclaration plus récente que Marc Petit saisit lui-même, née vérifiée ;
 *   le Comptoir des Îles, sans comptable, −1 250,00 € « non vérifiés par un
 *   comptable » ; le Studio Lumière, « Non, aucun crédit » ; le Groupe Nord
 *   Distribution n'a pas encore répondu (son étape et son « À faire ») ;
 *   l'inscription qui attend son code a choisi son régime trimestriel le
 *   jour de son arrivée : sa déclaration est encore à venir (« en
 *   attente ») ;
 * - le report du crédit : chez Maison Ferrand, au mensuel, deux mois en
 *   crédit qui s'enchaînent, un mois qui l'épuise en partie, puis un qui
 *   l'épuise tout à fait ; chez l'Épicerie Lemoine, au trimestriel, un
 *   trimestre en crédit reporté sur le suivant ; chaque fois, le comptable
 *   y ajoute la charge, née validée, qui amène la période au résultat visé
 *   quelle que soit la TVA qu'elle avait déjà (des travaux de rénovation
 *   ou des vitrines réfrigérées, dont il déduit la TVA, ou la location
 *   d'une voiture de direction à un loueur établi en Belgique,
 *   autoliquidée, dont la TVA ne se déduit pas) ;
 * - le seuil du réel normal trimestriel, le dernier jour de l'histoire :
 *   Conseil Vasseur, au trimestriel, dépasse 1 100 000 € de chiffre
 *   d'affaires de l'année (sa ligne de l'« À faire » pour son propriétaire
 *   et Mathilde Garnier, son administratrice qui gère les paramètres de la
 *   société) ; le Studio Lumière, au trimestriel, à 1 100 000,00 € tout
 *   juste (aucune ligne) ; le Comptoir des Îles, au mensuel, au-delà (aucune
 *   ligne) ; l'Épicerie Lemoine, au-delà, dont la propriétaire dit « C'est
 *   vérifié » (au journal d'audit, plus dans l'« À faire ») ;
 * - les charges du Studio Lumière, l'une validée, l'autre à valider, pour
 *   que sa TVA réelle et son estimation diffèrent, comme chez les sociétés
 *   qui déposent leurs charges chaque mois (voir ChargesDesSocietes).
 *
 * Les cas du seuil et les sociétés des cas ne sont semés que sur le poste ;
 * les bases modèles du site de démonstration ont chacune son crédit de
 * départ et son report.
 */
final class TvaDesSocietes
{
    /** Le crédit de départ de Maison Ferrand et du Comptoir des Îles, en centimes (un solde de −1 250,00 €). */
    public const CREDIT_DE_DEPART = -125000;

    /** Le crédit de départ de l'Épicerie Lemoine, confirmé par le cabinet. */
    public const CREDIT_DE_L_EPICERIE = -48000;

    /** Le crédit de départ de Conseil Vasseur, saisi par son propriétaire, puis corrigé par son expert-comptable déclaré. */
    public const CREDIT_SAISI_PAR_LE_CONSEIL = -32000;

    public const CREDIT_CORRIGE_DU_CONSEIL = -35000;

    public const MOTIF_DE_LA_CORRECTION = 'La ligne « Crédit de TVA à reporter » de la déclaration porte 350 €, et non 320 € : le propriétaire avait recopié la ligne du mois d\'avant.';

    /** Le crédit de la déclaration plus récente que l'expert-comptable de Conseil Vasseur saisit lui-même. */
    public const CREDIT_SAISI_PAR_L_EXPERT = -9000;

    /**
     * Les crédits de TVA à reporter visés à la fin de chacun des mois de la
     * chaîne de Maison Ferrand, par mois compté depuis le mois de la fin de
     * l'histoire : 164,00 €, puis 374,00 €, puis 234,00 €, comme la
     * maquette du report (−16,40 €, −21,00 €, puis 140,00 € de TVA du
     * mois), en centimes, au décuple ; le mois −1 paie au moins
     * TVA_DU_DERNIER_MOIS_DE_LA_CHAINE, ce qui épuise le crédit.
     *
     * @var array<int, int>
     */
    public const CREDITS_DE_LA_CHAINE = [-4 => 16400, -3 => 37400, -2 => 23400];

    /** La TVA à payer, au moins, du mois −1 de la chaîne de Maison Ferrand, après son crédit reporté. */
    public const TVA_DU_DERNIER_MOIS_DE_LA_CHAINE = 15000;

    /** Le crédit à reporter visé à la fin du trimestre −2 de l'Épicerie Lemoine. */
    public const CREDIT_DU_TRIMESTRE_DE_L_EPICERIE = 80000;

    /** Le chiffre d'affaires HT de chaque grosse vente du dernier jour : 1 150 journées ou coffrets à 1 200,00 € TTC. */
    private const QUANTITE_DE_LA_GROSSE_VENTE = 1150;

    private const PRIX_TTC_DE_LA_GROSSE_VENTE = '1200.00';

    private const PRIX_TTC_DE_LA_GROSSE_VENTE_EN_CENTIMES = 120000;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
    ) {}

    /**
     * L'administratrice de Conseil Vasseur qui gère les paramètres de la
     * société, et lit donc la ligne du seuil de son « À faire » (sur le
     * poste seulement).
     */
    public function nommerLAdministratriceDeLaFiscalite(): void
    {
        if ($this->semis->surLePoste() && $this->semis->seme('services')) {
            $this->semis->administrateur('services', 'Mathilde Garnier', null, [], [], [AutorisationsSociete::PARAMETRES_GERER]);
        }
    }

    /**
     * Les déclarations du crédit de TVA de départ, au fil de l'histoire, à
     * 6 h 40, avant tout autre geste de la journée (le premier, les achats
     * de la carte « Écart des prix d'achat », est à 7 h) : l'horloge du
     * semis ne remonte jamais le temps.
     */
    public function auJour(int $rang): void
    {
        if ($rang === 40) {
            $this->horloge->aller($rang, '06:40');
            $this->declarerLeDepart('commerce', self::CREDIT_DE_DEPART);

            if ($this->semis->surLePoste()) {
                $this->declarerLeDepart('comptoir', self::CREDIT_DE_DEPART);
            }
        }

        if ($rang === 100) {
            $this->horloge->aller($rang, '06:40');
            $this->declarerLeDepart('client', self::CREDIT_DE_L_EPICERIE);
            $this->declarerLeDepart('services', self::CREDIT_SAISI_PAR_LE_CONSEIL);

            if ($this->semis->surLePoste()) {
                $this->declarerLeDepart('studio', 0);
            }
        }

        if ($rang === 102 && $this->semis->seme('client')) {
            $this->horloge->aller($rang, '06:40');
            $credit = $this->declaration('client');
            $this->semis->horsDeToutContexte(fn (): CreditDeTva => app(ConfirmerUnCreditDeTvaAction::class)->handle($this->semis->compte('Emma Rousseau'), $this->semis->societe('client'), (string) $credit->uuid, GestesDuMagasin::jeton()));
        }

        if ($rang === 103 && $this->semis->seme('services')) {
            $this->horloge->aller($rang, '06:40');
            $credit = $this->declaration('services');
            $this->semis->horsDeToutContexte(fn (): CreditDeTva => app(CorrigerUnCreditDeTvaAction::class)->handle($this->semis->compte('Marc Petit'), $this->semis->societe('services'), (string) $credit->uuid, self::CREDIT_CORRIGE_DU_CONSEIL, self::MOTIF_DE_LA_CORRECTION, GestesDuMagasin::jeton()));
        }

        if ($rang === 250 && $this->semis->seme('services')) {
            $this->horloge->aller($rang, '06:40');
            $societe = $this->semis->societe('services')->refresh();
            $cadence = $this->cadence($societe->regime_tva);
            $plusRecente = CreditsDeTva::periodesDeclarables($societe, $cadence, $this->horloge->jour($rang))[0] ?? throw new LogicException('Conseil Vasseur n\'a encore aucune période à déclarer.');
            $this->semis->horsDeToutContexte(fn (): CreditDeTva => app(DeclarerUnCreditDeTvaAction::class)->handle($this->semis->compte('Marc Petit'), $societe, $plusRecente, self::CREDIT_SAISI_PAR_L_EXPERT, GestesDuMagasin::jeton()));
        }
    }

    /**
     * Le dernier jour de l'histoire : la chaîne de Maison Ferrand et le
     * trimestre de l'Épicerie Lemoine, puis, sur le poste, les cas du seuil,
     * les charges du Studio Lumière et le régime de l'inscription qui
     * attend son code.
     */
    public function dernierJour(): void
    {
        $rang = SemisComplet::JOURS - 1;
        $finDuMois = Mois::duJour($this->horloge->jour($rang));

        if ($this->semis->seme('commerce')) {
            $this->horloge->aller($rang, '10:00');

            foreach (self::CREDITS_DE_LA_CHAINE as $decalage => $creditVise) {
                $this->amenerLeResultat('commerce', 'Paul Lefèvre', [Mois::decaler($finDuMois, $decalage)], -$creditVise, false, ['Atelier Durand Rénovation', 'Travaux de rénovation de la boutique', 'halles']);
            }

            $this->amenerLeResultat('commerce', 'Paul Lefèvre', [Mois::decaler($finDuMois, -1)], self::TVA_DU_DERNIER_MOIS_DE_LA_CHAINE, true, ['Atelier Durand Rénovation', 'Travaux de rénovation de la boutique', 'halles']);
        }

        if ($this->semis->seme('client')) {
            $this->horloge->aller($rang, '10:10');
            $trimestre = CadenceDeLaTva::Trimestrielle->decaler(CadenceDeLaTva::Trimestrielle->periodeDu($finDuMois), -2);
            $this->amenerLeResultat('client', 'Emma Rousseau', CadenceDeLaTva::Trimestrielle->mois($trimestre), -self::CREDIT_DU_TRIMESTRE_DE_L_EPICERIE, false, ['Froid Commercial du Midi', 'Vitrines réfrigérées', 'epicerie']);
        }

        if (! $this->semis->surLePoste()) {
            return;
        }

        $this->horloge->aller($rang, '10:20');
        $this->vendreEnGros('conseil', 'Claire Dubois', 'Journée de mission de transformation', ['nom' => 'Groupe Hôtelier Atlantique', 'siren' => IdentifiantsInsee::avecSaCle('00000061'), 'adresse' => '2 quai de la Fosse', 'code_postal' => '44000', 'ville' => 'Nantes', 'email' => 'achats@hotelier-atlantique.exemple.test']);
        $this->vendreEnGros('epicerie', 'Nicolas Lemoine', 'Coffret gourmand d\'entreprise', ['nom' => 'Comité d\'entreprise Aéro Sud', 'siren' => IdentifiantsInsee::avecSaCle('00000062'), 'adresse' => '18 avenue Jean Monnet', 'code_postal' => '13700', 'ville' => 'Marignane', 'email' => 'cse@aero-sud.exemple.test']);
        $this->vendreEnGros('bordeaux', 'Inès Lafitte', 'Aménagement complet d\'une chambre d\'hôtel', ['nom' => 'Hôtel des Chartrons', 'siren' => IdentifiantsInsee::avecSaCle('00000063'), 'adresse' => '81 cours de Verdun', 'code_postal' => '33000', 'ville' => 'Bordeaux', 'email' => 'direction@hotel-chartrons.exemple.test']);
        $this->amenerLeStudioAuSeuil($rang);

        $this->horloge->aller($rang, '10:30');
        $this->semis->horsDeToutContexte(fn () => app(VerifierLeSeuilDuTrimestrielAction::class)->handle($this->semis->proprietaire('client'), $this->semis->societe('client')));

        $this->horloge->aller($rang, '10:35');
        $this->chargesDuStudio(Mois::decaler($finDuMois, -1));

        $this->horloge->aller($rang, '10:40');
        $this->semis->reglerLaFiscalite('inscription', ['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]);
    }

    /**
     * Le propriétaire de la société indique le crédit de TVA de sa
     * déclaration de la période de son arrivée dans PILIQI.
     */
    private function declarerLeDepart(string $cle, int $solde): void
    {
        if (! $this->semis->seme($cle)) {
            return;
        }

        $societe = $this->semis->societe($cle)->refresh();
        $periode = CreditsDeTva::premierePeriode($societe, $this->cadence($societe->regime_tva));
        $this->semis->horsDeToutContexte(fn (): CreditDeTva => app(DeclarerUnCreditDeTvaAction::class)->handle($this->semis->proprietaire($cle), $societe, $periode, $solde, GestesDuMagasin::jeton()));
    }

    /**
     * La déclaration la plus récente de la société.
     */
    private function declaration(string $cle): CreditDeTva
    {
        return app(CreditsDeTva::class)->declarations($this->semis->societe($cle))->first() ?? throw new LogicException("La société {$cle} n'a déclaré aucun crédit de TVA.");
    }

    /**
     * Le comptable de la société ajoute, sur le dernier de ces mois, la
     * charge née validée qui amène le résultat de leur période au résultat
     * visé, en centimes, depuis la TVA que la période a déjà et le crédit
     * qu'elle reçoit de la période d'avant, tels que le propriétaire les
     * lit : négatif, un crédit à reporter de ce montant ; positif, une TVA
     * à payer de ce montant, ou, avec $auMoins, d'au moins un centime, qu'il
     * amène à ce montant quand la période n'en paie pas. Pour baisser la
     * TVA de la période, la charge de $travaux (le fournisseur, l'objet et
     * le magasin), dont il déduit la TVA ; pour la monter, la location
     * d'une voiture de direction à un loueur établi en Belgique, pour toute
     * la société, autoliquidée, dont la TVA ne se déduit pas (véhicule de
     * tourisme) : elle est due sans être déductible.
     *
     * @param  list<string>  $moisDeLaPeriode
     * @param  array{0: string, 1: string, 2: string}  $travaux
     */
    private function amenerLeResultat(string $cle, string $comptable, array $moisDeLaPeriode, int $resultatVise, bool $auMoins, array $travaux): void
    {
        $societe = $this->semis->societe($cle)->refresh();
        $periode = $this->semis->horsDeToutContexte(function () use ($cle, $societe, $moisDeLaPeriode): ?ReportDeLaPeriode {
            $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($this->semis->proprietaire($cle), $societe);
            $tva = app(TvaDeLaPeriode::class)->avecLeReport($perimetre, $moisDeLaPeriode);

            return $tva === null ? null : $tva['report']?->deLaPeriodeDu($moisDeLaPeriode[0]);
        });

        if (! $periode instanceof ReportDeLaPeriode) {
            throw new LogicException("La période de {$moisDeLaPeriode[0]} de la société {$cle} n'a pas de report du crédit.");
        }

        $resultat = $periode->reelle->tva - $periode->reelle->creditReporte;
        $ecart = $resultatVise - $resultat;

        if ($ecart === 0 || ($auMoins && $resultat > 0)) {
            return;
        }

        $mois = $moisDeLaPeriode[count($moisDeLaPeriode) - 1];
        [$fournisseur, $objet, $magasin] = $ecart < 0
            ? $travaux
            : ['Ardennes Auto Lease SA', 'Location longue durée d\'une voiture de direction', null];
        $saisie = new SaisieDeCharge(
            type: $ecart < 0 ? 'piliqi:entretien_reparations' : 'piliqi:vehicule_tourisme',
            magasin: $magasin === null ? null : (string) $this->semis->magasin($magasin)->uuid,
            fournisseur: $fournisseur,
            numeroPiece: ($ecart < 0 ? 'TR-' : 'AAL-').$mois,
            datePiece: $mois.'-20',
            datePaiement: $mois.'-25',
            moisTva: $mois,
            fournisseurHorsFrance: $ecart > 0,
            vehiculeUtilitaire: false,
            note: $objet,
            lignes: [$ecart < 0
                ? ['taux' => TauxDeCharge::Vingt, 'ht' => -$ecart * 5, 'tva' => -$ecart, 'ttc' => -$ecart * 6, 'tva_deductible' => -$ecart]
                : ['taux' => TauxDeCharge::Vingt, 'ht' => $ecart * 5, 'tva' => $ecart, 'ttc' => $ecart * 5, 'tva_deductible' => 0]],
        );
        $charge = $this->semis->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($this->semis->compte($comptable), $societe, $saisie, new PieceDeposee('Facture '.$fournisseur.' '.$mois.'.pdf', PieceDuSemis::pdf($fournisseur, $objet)), GestesDuMagasin::jeton()));

        if (! $charge->estValidee()) {
            throw new LogicException("La charge de {$cle} ajoutée par son comptable pour {$mois} devait naître validée.");
        }
    }

    /**
     * Une grosse vente d'un professionnel, réglée par virement à son
     * émission : 1 150 journées, coffrets ou aménagements à 1 200,00 € TTC à
     * 20 %, soit 1 150 000,00 € HT, au-delà du seuil du trimestriel.
     *
     * @param  array<string, string>  $client
     */
    private function vendreEnGros(string $magasin, string $vendeur, string $service, array $client): void
    {
        $gestes = new GestesDuMagasin($this->semis->magasin($magasin));
        $compte = $this->semis->compte($vendeur);
        $acheteur = $gestes->fiche($compte, Client::class, [...$client, 'type_client' => TypeClient::Professionnel, 'pays' => 'FR', 'mode_paiement_prefere' => ModePaiement::Virement->value]);
        $gestes->fiche($compte, Service::class, ['nom' => $service, 'prix_ttc' => self::PRIX_TTC_DE_LA_GROSSE_VENTE, 'taux_tva' => '20', 'actif' => true]);
        $gestes->facturer($compte, $acheteur, [$gestes->ligne($service, self::QUANTITE_DE_LA_GROSSE_VENTE)], [new PartDeReglement(ModePaiement::Virement, self::QUANTITE_DE_LA_GROSSE_VENTE * self::PRIX_TTC_DE_LA_GROSSE_VENTE_EN_CENTIMES)]);
    }

    /**
     * Le Studio Lumière vend à l'Agence Pixel la production de son film
     * institutionnel, au prix qui porte son chiffre d'affaires HT de
     * l'année à 1 100 000,00 € tout juste : le seuil, qui n'avertit pas.
     *
     * @throws LogicException quand le chiffre d'affaires n'arrive pas au seuil
     */
    private function amenerLeStudioAuSeuil(int $rang): void
    {
        $studio = $this->semis->societe('studio')->refresh();
        $annee = (int) substr($this->horloge->jour($rang), 0, 4);
        $manque = RegimeTva::SEUIL_DU_TRIMESTRIEL_EN_CENTIMES - app(SeuilDuTrimestriel::class)->chiffreDAffaires($studio, $annee);
        $prixTtc = self::prixTtcDUnHt($manque);
        $gestes = new GestesDuMagasin($this->semis->magasin('studio'));
        $remi = $this->semis->compte('Rémi Lacroix');
        $pixel = $gestes->dans(fn (): Client => Client::query()->where('nom', 'Agence Pixel')->sole());
        $gestes->fiche($remi, Service::class, ['nom' => 'Production du film institutionnel', 'prix_ttc' => number_format($prixTtc / 100, 2, '.', ''), 'taux_tva' => '20', 'actif' => true]);
        $gestes->facturer($remi, $pixel, [$gestes->ligne('Production du film institutionnel', 1)], [new PartDeReglement(ModePaiement::Virement, $prixTtc)]);

        if (app(SeuilDuTrimestriel::class)->chiffreDAffaires($studio, $annee) !== RegimeTva::SEUIL_DU_TRIMESTRIEL_EN_CENTIMES) {
            throw new LogicException('Le chiffre d\'affaires du Studio Lumière devait arriver à 1 100 000,00 € tout juste.');
        }
    }

    /**
     * Le prix TTC, en centimes, d'un article seul à 20 % dont le HT est ce
     * montant : son TTC moins sa TVA, le TTC × 20 ÷ 120 arrondi au centime
     * le plus proche (un demi-centime au centime supérieur), retombe sur ce
     * HT. Un TTC de 6k + r centimes a pour HT 5k + r − (1 si r ≥ 3) : chaque
     * reste de 0 à 4 du HT par 5 a son TTC.
     */
    private static function prixTtcDUnHt(int $ht): int
    {
        $k = intdiv($ht, 5);
        $reste = $ht % 5;

        return 6 * $k + [0 => 0, 1 => 1, 2 => 2, 3 => 4, 4 => 5][$reste];
    }

    /**
     * Les charges du Studio Lumière du mois d'avant la fin, sans
     * comptable : un loyer que sa propriétaire valide elle-même, un
     * abonnement qu'elle laisse à valider.
     */
    private function chargesDuStudio(string $mois): void
    {
        $studio = $this->semis->societe('studio')->refresh();
        $proprietaire = $this->semis->proprietaire('studio');
        $magasin = (string) $this->semis->magasin('studio')->uuid;
        $loyer = ValeursLues::instance($this->semis->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($proprietaire, $studio, $this->saisieDuStudio('piliqi:loyer', $magasin, 'SCI des Ateliers', 'SA-'.$mois, $mois.'-01', 65000), new PieceDeposee('Quittance SCI des Ateliers.pdf', PieceDuSemis::pdf('SCI des Ateliers', 'Quittance de loyer')), GestesDuMagasin::jeton())), Charge::class);
        $this->semis->horsDeToutContexte(fn (): Charge => app(ValiderUneChargeAction::class)->handle($proprietaire, $studio, (string) $loyer->uuid, GestesDuMagasin::jeton()));
        $this->semis->horsDeToutContexte(fn (): Charge => app(DeposerUneChargeAction::class)->handle($proprietaire, $studio, $this->saisieDuStudio('piliqi:abonnements_logiciels', null, 'Photo Cloud', 'PC-'.$mois, $mois.'-03', 2400), new PieceDeposee('Facture Photo Cloud.pdf', PieceDuSemis::pdf('Photo Cloud', 'Abonnement')), GestesDuMagasin::jeton()));
    }

    private function saisieDuStudio(string $type, ?string $magasin, string $fournisseur, string $numero, string $datePiece, int $ht): SaisieDeCharge
    {
        return new SaisieDeCharge(
            type: $type,
            magasin: $magasin,
            fournisseur: $fournisseur,
            numeroPiece: $numero,
            datePiece: $datePiece,
            datePaiement: $datePiece,
            moisTva: null,
            fournisseurHorsFrance: false,
            vehiculeUtilitaire: false,
            note: null,
            lignes: [['taux' => TauxDeCharge::Vingt, 'ht' => $ht, 'tva' => null, 'ttc' => null, 'tva_deductible' => null]],
        );
    }

    /**
     * @throws LogicException une société semée qui n'est pas au réel normal
     */
    private function cadence(?RegimeTva $regime): CadenceDeLaTva
    {
        return $regime?->cadence() ?? throw new LogicException('Le crédit de TVA ne se sème qu\'au réel normal.');
    }
}
