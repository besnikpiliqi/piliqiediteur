<?php

namespace Database\Seeders\Semis;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use LogicException;

/**
 * Les deux premières années de l'histoire semée (lot GRAPH-AN, demande de
 * l'éditeur du 16/11/2026 : « trois années de ventes au moins »), entre le
 * stock d'ouverture du premier jour et l'histoire détaillée (voir
 * SemisComplet::JOURS_ANCIENS) : seuls les établissements qui existent
 * depuis le début vendent, à un rythme léger, par les vraies actions, pour
 * que « Découper par : année » montre plus de trois années de ventes à
 * Maison Ferrand (les Halles et la République) et à Conseil Vasseur, avec
 * leur chiffre d'affaires d'une année sur l'autre. Chaque jour, au plus un
 * ticket par établissement, d'un vendeur un jour sur deux, à l'heure de son
 * rang, deux jours sur trois ; tous les quatorze jours (et le dernier jour,
 * pour que l'histoire détaillée reprenne avec le stock qu'avait l'ancienne),
 * le responsable de chaque magasin d'articles réassortit ce qui s'est vendu
 * depuis le réassort précédent, au prix d'achat de départ, par une facture
 * d'achat validée dont il joint la pièce : le stock reste celui d'un
 * magasin qui vit, sans jamais manquer ni s'accumuler. Ni commande de
 * professionnel, ni charge, ni cas : ils commencent avec l'histoire
 * détaillée. Les sociétés des cas qui ont leur histoire ancienne (le Studio
 * Lumière, l'Atelier Brun fermé un an) l'écrivent elles-mêmes (voir
 * SocietesDesCas::auJourDeLHistoireAncienne()) ; la démonstration que
 * demande, le vingtième jour, l'inscription qui attend son code (close le
 * trentième) se fait ici, avant la clôture (voir
 * DemonstrationsDesSocietes::auJour()).
 */
final class HistoireAncienne
{
    /**
     * Le premier jour de l'histoire ancienne : le lendemain du stock
     * d'ouverture des magasins.
     */
    public const PREMIER_JOUR = 3;

    /**
     * Le dernier jour de l'histoire ancienne : la veille du jour où
     * l'histoire détaillée reprend les premières semaines (voir
     * ActiviteDesMagasins::premieresSemaines()).
     */
    public const DERNIER_JOUR = SemisComplet::JOURS_ANCIENS + 2;

    /**
     * Les ventes de chaque établissement d'origine : ses deux vendeurs, un
     * jour sur deux chacun, et ce que chacun vend (les mêmes articles que
     * dans l'histoire détaillée), dans l'ordre des heures de la journée
     * (10 h, 11 h puis 12 h).
     *
     * @var array<string, list<array{0: string, 1: array<string, int>}>>
     */
    private const VENTES = [
        'halles' => [
            ['Lucas Moreau', ['BOUGIE-FIGUE' => 1, 'TASSE-GRES' => 1]],
            ['Inès Garcia', ['CARNET-A5' => 2, 'THE-SENCHA' => 1]],
        ],
        'republique' => [
            ['Lucas Moreau (République)', ['COUSSIN-LIN' => 1]],
            ['Sarah Petit', ['BOUGIE-FIGUE' => 1]],
        ],
        'conseil' => [
            ['Maxime Henry', ['Heure de conseil' => 1]],
            ['Julie Mercier', ['Heure de conseil' => 2, 'Forfait déplacement' => 1]],
        ],
    ];

    /**
     * Le réassort de chaque magasin d'articles : son responsable, son
     * fournisseur, le préfixe de ses numéros de facture et le prix d'achat
     * HT de chaque article, en centimes (celui de l'histoire détaillée,
     * avant ses variations).
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: array<string, int>}>
     */
    private const REASSORTS = [
        'halles' => ['Camille Laurent', 'Grossiste Lumière', 'GL', ['BOUGIE-FIGUE' => 600, 'TASSE-GRES' => 420, 'THE-SENCHA' => 310, 'CARNET-A5' => 140]],
        'republique' => ['Hugo Lefebvre', 'Atelier Lin et Laine', 'ALL', ['COUSSIN-LIN' => 850, 'BOUGIE-FIGUE' => 600]],
    ];

    /**
     * La période, en jours, des réassorts.
     */
    private const PERIODE_DES_REASSORTS = 14;

    /**
     * Les encaissements des tickets, à tour de rôle.
     *
     * @var list<ModePaiement>
     */
    private const MODES = [ModePaiement::CarteBancaire, ModePaiement::Especes, ModePaiement::CarteBancaire, ModePaiement::Cheque];

    /**
     * Ce qui s'est vendu de chaque article, par magasin, depuis le dernier
     * réassort.
     *
     * @var array<string, array<string, int>>
     */
    private array $vendu = [];

    /**
     * Les lignes de vente de chaque article, par magasin, à la quantité 1.
     *
     * @var array<string, array{produit_id?: int, service_id?: int, quantite: int}>
     */
    private array $lignes = [];

    /**
     * @var array<string, Fournisseur>
     */
    private array $fournisseurs = [];

    /**
     * @param  array<string, GestesDuMagasin>  $gestes  les magasins que ce semis sème, par clé
     * @param  SocietesDesCas|null  $societesDesCas  celles du poste, que le semis d'une base modèle n'a pas
     * @param  DemonstrationsDesSocietes|null  $demonstrations  celles des sociétés du poste, de même
     */
    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
        private readonly array $gestes,
        private readonly ?SocietesDesCas $societesDesCas,
        private readonly ?DemonstrationsDesSocietes $demonstrations,
    ) {}

    /**
     * Sème les deux premières années, jour après jour.
     */
    public function semer(): void
    {
        $this->preparer();

        for ($jour = self::PREMIER_JOUR; $jour <= self::DERNIER_JOUR; $jour++) {
            $this->vendre($jour);

            if ($jour % self::PERIODE_DES_REASSORTS === 5 || $jour === self::DERNIER_JOUR) {
                $this->reassortir($jour);
            }

            $this->societesDesCas?->auJourDeLHistoireAncienne($jour);
            $this->demonstrations?->auJour($jour);
        }
    }

    /**
     * Le fournisseur de la République, que son responsable crée, comme les
     * autres fournisseurs d'origine : il livre ses réassorts depuis le
     * début (voir ActiviteDesMagasins::joursSuivants()).
     */
    private function preparer(): void
    {
        if (! $this->semis->seme('commerce')) {
            return;
        }

        $this->horloge->aller(self::PREMIER_JOUR, '08:00');
        $this->fournisseurs['republique'] = $this->gestes['republique']->fiche($this->semis->compte('Hugo Lefebvre'), Fournisseur::class, [
            'nom' => 'Atelier Lin et Laine',
            'siret' => IdentifiantsInsee::avecSaCle('0000002170001'),
            'adresse' => '12 quai de la Loire',
            'code_postal' => '44000',
            'ville' => 'Nantes',
            'pays' => 'FR',
        ]);
    }

    /**
     * Les tickets de ce jour : au plus un par établissement, deux jours sur
     * trois, d'un vendeur un jour sur deux, à 10 h, 11 h et 12 h.
     */
    private function vendre(int $jour): void
    {
        $heure = 9;

        foreach (self::VENTES as $magasin => $vendeurs) {
            $heure++;

            if (! isset($this->gestes[$magasin]) || ($jour + $heure) % 3 === 0) {
                continue;
            }

            [$nom, $quantites] = $vendeurs[$jour % 2];
            $gestes = $this->gestes[$magasin];
            $this->horloge->aller($jour, sprintf('%02d:00', $heure));
            $gestes->vendreALaCaisse(
                $this->semis->compte($nom),
                $gestes->comptoir(),
                array_map(fn (string $reference, int $quantite): array => [...$this->ligne($magasin, $reference), 'quantite' => $quantite], array_keys($quantites), $quantites),
                self::MODES[($jour + $heure) % count(self::MODES)],
            );

            foreach ($quantites as $reference => $quantite) {
                if (isset(self::REASSORTS[$magasin][3][$reference])) {
                    $this->vendu[$magasin][$reference] = ($this->vendu[$magasin][$reference] ?? 0) + $quantite;
                }
            }
        }
    }

    /**
     * Le responsable de chaque magasin d'articles rachète, à 18 h, ce qui s'est
     * vendu depuis le dernier réassort, par une facture d'achat validée avec
     * sa pièce.
     */
    private function reassortir(int $jour): void
    {
        foreach (self::REASSORTS as $magasin => [$responsable, $nomDuFournisseur, $prefixe, $prix]) {
            $lignes = [];

            foreach ($prix as $reference => $centimes) {
                $quantite = $this->vendu[$magasin][$reference] ?? 0;

                if ($quantite > 0 && isset($this->gestes[$magasin])) {
                    $lignes[] = ['produit_id' => $this->gestes[$magasin]->produit($reference)->id, 'quantite' => $quantite, 'prix_unitaire_ht' => number_format($centimes / 100, 2, '.', '')];
                }
            }

            if ($lignes === []) {
                continue;
            }

            $numero = sprintf('%s-A%04d', $prefixe, $jour);
            $this->horloge->aller($jour, '18:00');
            $this->gestes[$magasin]->acheter(
                $this->semis->compte($responsable),
                $this->fournisseur($magasin, $nomDuFournisseur),
                $lignes,
                ['date_facture' => $this->horloge->jour($jour), 'numero_fournisseur' => $numero],
                [new PieceDeposee("Facture {$numero}.pdf", PieceDuSemis::pdf($nomDuFournisseur, 'Facture de réassort'))],
            );
            $this->vendu[$magasin] = [];
        }
    }

    /**
     * La ligne de vente de cet article de ce magasin, à la quantité 1.
     *
     * @return array{produit_id?: int, service_id?: int, quantite: int}
     */
    private function ligne(string $magasin, string $reference): array
    {
        return $this->lignes[$magasin.':'.$reference] ??= $this->gestes[$magasin]->ligne($reference, 1);
    }

    private function fournisseur(string $magasin, string $nom): Fournisseur
    {
        return $this->fournisseurs[$magasin] ??= ($this->gestes[$magasin] ?? throw new LogicException("Le magasin {$magasin} n'est pas semé."))->dans(fn (): Fournisseur => Fournisseur::query()->where('nom', $nom)->sole());
    }
}
