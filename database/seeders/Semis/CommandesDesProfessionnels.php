<?php

namespace Database\Seeders\Semis;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Models\User;
use Closure;

/**
 * Les commandes des clients professionnels de chaque magasin semé, tout au
 * long de l'histoire (demande de l'éditeur du 15/11/2026, cahier SEED-TOUT
 * § 3.3 et § 3.4 : des centaines de devis, de factures et d'avoirs, chaque
 * compte avec les siens) : au magasin de services tous les trois jours, aux
 * Halles, à la République et à l'Épicerie toutes les semaines, chaque
 * commande au nom d'un vendeur ou d'un responsable du magasin, à tour de
 * rôle, et chacune avec sa suite, qui se fait les jours suivants, à 8 h 40,
 * avant l'ouverture de la caisse :
 * - devis envoyé, accepté deux jours plus tard, transformé en facture,
 *   payée par virement dix jours après ;
 * - devis accepté, facture payée en deux fois (la moitié à dix jours, le
 *   reste à quarante) ;
 * - devis refusé par le magasin trois jours après son envoi ;
 * - devis envoyé sans réponse, que la tâche des devis expire à la fin de
 *   sa validité (quinze jours) ;
 * - devis accepté, facture jamais payée : elle passe en retard à son
 *   échéance, et les relances automatiques des Halles la relancent ;
 * - facture payée à l'émission, puis deux avoirs successifs d'un article de
 *   sa plus grande ligne : le client garde un crédit, imputé d'office sur sa
 *   facture suivante, qui se trouve réglée en tout ou en partie par ce
 *   crédit ;
 * - facture jamais payée, soldée par un avoir de toutes ses lignes quatre
 *   jours plus tard ;
 * - devis accepté, facture payée par chèque le jour même.
 * Aux Halles, tous les trente jours, un article d'un ticket du comptoir est
 * rendu (bon d'avoir), et ce bon règle une partie d'une vente au comptoir
 * cinq jours plus tard. Les quantités restent dans ce que les réassorts
 * apportent (voir ActiviteDesMagasins::joursSuivants()).
 */
final class CommandesDesProfessionnels
{
    private const SUITES = ['payee', 'payee_en_deux_fois', 'refusee', 'sans_reponse', 'impayee', 'avoirs_successifs', 'soldee_par_un_avoir', 'payee_par_cheque'];

    /**
     * Pour chaque magasin : sa période (en jours), son décalage, son
     * responsable, ses vendeurs à tour de rôle, ses clients professionnels
     * et les lignes de ses commandes, à tour de rôle.
     *
     * @var array<string, array{periode: int, decalage: int, responsable: string, vendeurs: list<string>, clients: list<string>, commandes: list<array<string, int>>}>
     */
    private const MAGASINS = [
        'conseil' => [
            'periode' => 3,
            'decalage' => 0,
            'responsable' => 'Claire Dubois',
            'vendeurs' => ['Claire Dubois', 'Julie Mercier', 'Antoine Roux', 'Maxime Henry'],
            'clients' => ['Boulangerie Martin', 'Cabinet dentaire Morel', 'Librairie Voltaire', 'Garage Dumas'],
            'commandes' => [['Heure de conseil' => 4], ['Audit de site web' => 1, 'Forfait déplacement' => 2], ['Heure de conseil' => 6, 'Forfait déplacement' => 1]],
        ],
        'halles' => [
            'periode' => 7,
            'decalage' => 1,
            'responsable' => 'Camille Laurent',
            'vendeurs' => ['Camille Laurent', 'Lucas Moreau'],
            'clients' => ['Hôtel des Arts', 'Agence Horizon', 'Galerie Saint-Martin'],
            'commandes' => [['CARNET-A5' => 3, 'THE-SENCHA' => 2, 'Emballage cadeau' => 3], ['TASSE-GRES' => 2, 'Livraison à domicile' => 1]],
        ],
        'republique' => [
            'periode' => 7,
            'decalage' => 3,
            'responsable' => 'Hugo Lefebvre',
            'vendeurs' => ['Hugo Lefebvre', 'Sarah Petit'],
            'clients' => ['Studio Nord', 'Hôtel Le Marais'],
            'commandes' => [['COUSSIN-LIN' => 3], ['BOUGIE-FIGUE' => 2, 'COUSSIN-LIN' => 1]],
        ],
        'epicerie' => [
            'periode' => 7,
            'decalage' => 5,
            'responsable' => 'Nicolas Lemoine',
            'vendeurs' => ['Nicolas Lemoine', 'Chloé Bernard', 'Bastien Leroy'],
            'clients' => ['Restaurant La Treille', 'Traiteur Les Saveurs'],
            'commandes' => [['HUILE-OLIVE' => 3, 'MIEL-LAVANDE' => 2], ['HUILE-OLIVE' => 4]],
        ],
    ];

    /**
     * Les fiches des clients professionnels que ces commandes ajoutent à
     * ceux que le magasin a déjà.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const NOUVEAUX_CLIENTS = [
        'conseil' => [
            ['nom' => 'Librairie Voltaire', 'siren' => '00000030', 'adresse' => '18 boulevard Voltaire', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'contact@librairie-voltaire.exemple.test', 'delai_paiement_jours' => 45],
            ['nom' => 'Garage Dumas', 'siren' => '00000031', 'adresse' => '7 avenue Jean Jaurès', 'code_postal' => '93100', 'ville' => 'Montreuil', 'email' => 'garage.dumas@exemple.test'],
        ],
        'halles' => [
            ['nom' => 'Galerie Saint-Martin', 'siren' => '00000032', 'adresse' => '60 rue Saint-Martin', 'code_postal' => '75003', 'ville' => 'Paris', 'email' => 'galerie@saint-martin.exemple.test'],
        ],
        'republique' => [
            ['nom' => 'Hôtel Le Marais', 'siren' => '00000033', 'adresse' => '22 rue des Archives', 'code_postal' => '75004', 'ville' => 'Paris', 'email' => 'reception@hotel-le-marais.exemple.test'],
        ],
        'epicerie' => [
            ['nom' => 'Restaurant La Treille', 'siren' => '00000034', 'adresse' => '9 place des Prêcheurs', 'code_postal' => '13100', 'ville' => 'Aix-en-Provence', 'email' => 'cuisine@la-treille.exemple.test'],
            ['nom' => 'Traiteur Les Saveurs', 'siren' => '00000035', 'adresse' => '31 cours Mirabeau', 'code_postal' => '13100', 'ville' => 'Aix-en-Provence', 'email' => 'commandes@les-saveurs.exemple.test'],
        ],
    ];

    /** Le premier jour des commandes, une fois les fiches et les réassorts en place. */
    private const PREMIER_JOUR = 20;

    /**
     * Les gestes de la suite de chaque commande, par jour de l'histoire.
     *
     * @var array<int, list<Closure(): mixed>>
     */
    private array $suites = [];

    /** @var array<string, int> */
    private array $nombreDeCommandes = [];

    /** @var array<string, GestesDuMagasin> */
    private array $gestes = [];

    private ?string $bonDAvoirARegler = null;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
    ) {}

    /**
     * Les fiches des nouveaux clients professionnels, créées par le
     * responsable de chaque magasin.
     */
    public function preparer(): void
    {
        foreach (self::NOUVEAUX_CLIENTS as $magasin => $clients) {
            if (! $this->semis->semeLeMagasin($magasin)) {
                continue;
            }

            foreach ($clients as $client) {
                $this->gestes($magasin)->fiche($this->semis->compte(self::MAGASINS[$magasin]['responsable']), Client::class, [
                    ...$client,
                    'type_client' => TypeClient::Professionnel,
                    'siren' => IdentifiantsInsee::avecSaCle($client['siren']),
                    'pays' => 'FR',
                ]);
            }
        }
    }

    /**
     * Le matin de ce jour : à 8 h 40 les suites des commandes passées qui
     * tombent ce jour, à 8 h 50 les commandes nouvelles, à 8 h 55 le bon
     * d'avoir des Halles.
     */
    public function auJour(int $rang): void
    {
        if (isset($this->suites[$rang])) {
            $this->horloge->aller($rang, '08:40');

            foreach ($this->suites[$rang] as $geste) {
                $geste();
            }

            unset($this->suites[$rang]);
        }

        if ($rang < self::PREMIER_JOUR || $rang > SemisComplet::JOURS - 12) {
            return;
        }

        foreach (self::MAGASINS as $magasin => $reglages) {
            if ($this->semis->semeLeMagasin($magasin) && $rang % $reglages['periode'] === $reglages['decalage']) {
                $this->horloge->aller($rang, '08:50');
                $this->commander($magasin, $rang);
            }
        }

        if ($this->semis->semeLeMagasin('halles')) {
            $this->bonDAvoirDesHalles($rang);
        }
    }

    /**
     * La commande suivante du magasin, et sa suite, selon son rang.
     */
    private function commander(string $magasin, int $rang): void
    {
        $reglages = self::MAGASINS[$magasin];
        $numero = $this->nombreDeCommandes[$magasin] = ($this->nombreDeCommandes[$magasin] ?? -1) + 1;
        $gestes = $this->gestes($magasin);
        $vendeur = $this->semis->compte($reglages['vendeurs'][$numero % count($reglages['vendeurs'])]);
        $responsable = $this->semis->compte($reglages['responsable']);
        $client = $this->client($gestes, $reglages['clients'][intdiv($numero, count(self::SUITES)) % count($reglages['clients'])]);
        $lignes = $this->lignes($gestes, $reglages['commandes'][$numero % count($reglages['commandes'])]);

        match (self::SUITES[$numero % count(self::SUITES)]) {
            'payee' => $this->apresUnDevisAccepte($gestes, $vendeur, $responsable, $client, $lignes, $rang, function (Facture $facture) use ($gestes, $vendeur, $rang): void {
                $this->plusTard($rang + 12, fn () => $this->encaisser($gestes, $vendeur, $facture, ModePaiement::Virement, $rang + 12));
            }),
            'payee_en_deux_fois' => $this->apresUnDevisAccepte($gestes, $vendeur, $responsable, $client, $lignes, $rang, function (Facture $facture) use ($gestes, $vendeur, $rang): void {
                $this->plusTard($rang + 12, fn () => $this->encaisser($gestes, $vendeur, $facture, ModePaiement::Virement, $rang + 12, 2));
                $this->plusTard($rang + 42, fn () => $this->encaisser($gestes, $vendeur, $facture, ModePaiement::Virement, $rang + 42));
            }),
            'refusee' => $this->refuseeTroisJoursPlusTard($gestes, $vendeur, $responsable, $client, $lignes, $rang),
            'sans_reponse' => $this->devisEnvoye($gestes, $vendeur, $client, $lignes, $rang, 15),
            'impayee' => $this->apresUnDevisAccepte($gestes, $vendeur, $responsable, $client, $lignes, $rang, fn (Facture $facture): Facture => $facture),
            'avoirs_successifs' => $this->avoirsSuccessifs($gestes, $vendeur, $responsable, $client, $lignes, $rang),
            'soldee_par_un_avoir' => $this->soldeeParUnAvoir($gestes, $vendeur, $responsable, $client, $lignes, $rang),
            'payee_par_cheque' => $this->apresUnDevisAccepte($gestes, $vendeur, $responsable, $client, $lignes, $rang, function (Facture $facture) use ($gestes, $vendeur, $rang): void {
                $this->plusTard($rang + 2, fn () => $this->encaisser($gestes, $vendeur, $facture, ModePaiement::Cheque, $rang + 2));
            }),
        };
    }

    /**
     * Un devis envoyé par le vendeur (émis, sans e-mail : le client l'a
     * reçu en main propre), valable $validite jours.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function devisEnvoye(GestesDuMagasin $gestes, User $vendeur, Client $client, array $lignes, int $rang, int $validite = 30): Devis
    {
        return $gestes->dans(fn (): Devis => app(EmettreDevisAction::class)->handle(
            $vendeur,
            app(CreerDevisAction::class)->handle($vendeur, $client, $lignes, ['date_validite' => $this->horloge->jour(min($rang + $validite, SemisComplet::JOURS - 1))]),
            GestesDuMagasin::jeton(),
        ));
    }

    /**
     * Un devis envoyé ce jour, que le responsable enregistre comme refusé
     * trois jours plus tard (le client a dit non au téléphone).
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function refuseeTroisJoursPlusTard(GestesDuMagasin $gestes, User $vendeur, User $responsable, Client $client, array $lignes, int $rang): void
    {
        $devis = $this->devisEnvoye($gestes, $vendeur, $client, $lignes, $rang);
        $this->plusTard($rang + 3, fn (): Devis => $gestes->dans(fn (): Devis => app(RefuserDevisAction::class)->handle($responsable, $devis->refresh())));
    }

    /**
     * Le devis envoyé ce jour, accepté par le responsable deux jours plus
     * tard (le client a signé « Bon pour accord » au magasin), puis
     * transformé en facture par son vendeur le même jour ; $ensuite reçoit
     * la facture émise.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  Closure(Facture): mixed  $ensuite
     */
    private function apresUnDevisAccepte(GestesDuMagasin $gestes, User $vendeur, User $responsable, Client $client, array $lignes, int $rang, Closure $ensuite): void
    {
        $devis = $this->devisEnvoye($gestes, $vendeur, $client, $lignes, $rang);

        $this->plusTard($rang + 2, function () use ($gestes, $vendeur, $responsable, $devis, $ensuite): void {
            $accepte = $gestes->dans(fn (): Devis => app(AccepterDevisAction::class)->handle($responsable, $devis->refresh()));
            $ensuite($gestes->dans(fn (): Facture => app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureDepuisDevisAction::class)->handle($vendeur, $accepte), null, null, GestesDuMagasin::jeton())));
        });
    }

    /**
     * Une facture payée à l'émission, puis, à trois et six jours, un avoir
     * d'un article chacun, sur sa ligne de plus grande quantité (au moins
     * deux articles dans chaque commande semée) : le client garde un
     * crédit, que sa facture suivante reçoit d'office.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function avoirsSuccessifs(GestesDuMagasin $gestes, User $vendeur, User $responsable, Client $client, array $lignes, int $rang): void
    {
        $facture = $gestes->facturer($vendeur, $client, $lignes);
        $this->encaisser($gestes, $vendeur, $facture, ModePaiement::Virement, $rang);
        $ligne = $gestes->dans(fn (): int => $facture->lignes()->reorder()->orderByDesc('quantite')->orderBy('ordre')->firstOrFail()->id);

        foreach ([3 => 'Un article arrivé abîmé : avoir d\'un article.', 6 => 'Un second article de la même ligne rendu : nouvel avoir.'] as $apres => $motif) {
            $this->plusTard($rang + $apres, fn (): Avoir => $gestes->rendre($responsable, $client, [['ligne_facture_id' => $ligne, 'quantite' => 1]], $motif));
        }
    }

    /**
     * Une facture jamais payée, soldée quatre jours plus tard par un avoir
     * de toutes ses lignes (commande annulée par le client).
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function soldeeParUnAvoir(GestesDuMagasin $gestes, User $vendeur, User $responsable, Client $client, array $lignes, int $rang): void
    {
        $facture = $gestes->facturer($vendeur, $client, $lignes);

        $this->plusTard($rang + 4, fn (): Avoir => $gestes->rendre($responsable, $client, $gestes->dans(fn (): array => array_values($facture->lignes()->orderBy('ordre')->get()->map(fn ($ligne): array => ['ligne_facture_id' => $ligne->id, 'quantite' => (int) $ligne->quantite])->all())), 'Commande annulée par le client : facture soldée par cet avoir.'));
    }

    /**
     * Aux Halles, tous les trente jours, la responsable rend un article
     * du dernier ticket du comptoir de Lucas Moreau (bon d'avoir), et ce
     * bon règle une partie d'une vente au comptoir cinq jours plus tard.
     */
    private function bonDAvoirDesHalles(int $rang): void
    {
        $halles = $this->gestes('halles');
        $camille = $this->semis->compte('Camille Laurent');

        if ($rang % 30 === 25 && $this->bonDAvoirARegler !== null) {
            $this->horloge->aller($rang, '08:55');
            $halles->vendreALaCaisse($camille, $halles->comptoir(), $this->lignes($halles, ['CARNET-A5' => 3, 'THE-SENCHA' => 1]), ModePaiement::CarteBancaire, [], [$this->bonDAvoirARegler]);
            $this->bonDAvoirARegler = null;
        }

        if ($rang % 30 === 20) {
            $this->horloge->aller($rang, '08:55');
            $lucas = $this->semis->compte('Lucas Moreau');
            $ligne = $halles->dans(fn (): int => Facture::query()
                ->where('client_id', $halles->comptoir()->id)
                ->where('cree_par', $lucas->id)
                ->whereNotNull('numero')
                ->latest('id')
                ->firstOrFail()
                ->lignes()->orderBy('ordre')->firstOrFail()->id);
            $avoir = $halles->rendre($camille, $halles->comptoir(), [['ligne_facture_id' => $ligne, 'quantite' => 1]], 'Article rendu au comptoir : la cliente garde un bon d\'avoir.');
            $this->bonDAvoirARegler = (string) $avoir->numero;
        }
    }

    /**
     * Le vendeur encaisse ce jour ce qui reste dû sur la facture (ou la
     * moitié quand $fraction vaut 2), s'il en reste : le crédit d'avoir du
     * client a pu la régler à son émission.
     */
    private function encaisser(GestesDuMagasin $gestes, User $vendeur, Facture $facture, ModePaiement $mode, int $rang, int $fraction = 1): void
    {
        $montant = intdiv($gestes->dans(fn (): int => $facture->refresh()->resteDuEnCentimes()), $fraction);

        if ($montant > 0) {
            $gestes->encaisser($vendeur, $facture, [new PartDeReglement($mode, $montant, $mode === ModePaiement::Cheque ? 'Chèque n° '.(4_400_000 + $rang) : null)], $this->horloge->jour($rang));
        }
    }

    /**
     * Le geste $geste se fera ce jour de l'histoire, s'il est encore dans
     * l'histoire (avant l'avant-veille du semis).
     *
     * @param  Closure(): mixed  $geste
     */
    private function plusTard(int $rang, Closure $geste): void
    {
        if ($rang <= SemisComplet::JOURS - 2) {
            $this->suites[$rang][] = $geste;
        }
    }

    /**
     * @param  array<string, int>  $quantites
     * @return list<array<string, mixed>>
     */
    private function lignes(GestesDuMagasin $gestes, array $quantites): array
    {
        return array_map(fn (string $article, int $quantite): array => $gestes->ligne($article, $quantite), array_keys($quantites), $quantites);
    }

    private function client(GestesDuMagasin $gestes, string $nom): Client
    {
        return $gestes->dans(fn (): Client => Client::query()->where('nom', $nom)->sole());
    }

    private function gestes(string $magasin): GestesDuMagasin
    {
        return $this->gestes[$magasin] ??= new GestesDuMagasin($this->semis->magasin($magasin));
    }
}
