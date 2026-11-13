<?php

namespace Database\Seeders\Semis;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;
use LogicException;

/**
 * Les gestes du semis dans un magasin, chacun par sa vraie action, au nom
 * de la personne qui le fait, dans le magasin actif le temps du geste
 * (voir TenantContext::runAs()) : fiches, achats et inventaire
 * d'ouverture validés (seuls documents qui font naître un produit et son
 * stock), ventes à la caisse, factures, encaissements, contre-passations
 * et avoirs. Chaque geste qu'une personne répète après une réponse perdue
 * porte, comme à l'écran, un jeton unique (R3).
 */
final class GestesDuMagasin
{
    public function __construct(public readonly Magasin $magasin) {}

    /**
     * Exécute $geste dans ce magasin, magasin actif.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    public function dans(Closure $geste): mixed
    {
        return app(TenantContext::class)->runAs($this->magasin, $geste);
    }

    /**
     * Une fiche du magasin (client, fournisseur ou service), créée par
     * cette personne.
     *
     * @template TFiche of Client|Fournisseur|Service
     *
     * @param  class-string<TFiche>  $classe
     * @param  array<string, mixed>  $attributs
     * @return TFiche
     */
    public function fiche(User $auteur, string $classe, array $attributs): Client|Fournisseur|Service
    {
        $fiche = $this->dans(fn (): Client|Fournisseur|Service => app(CreerUneFicheAction::class)->handle($auteur, $classe, $attributs));

        if (! $fiche instanceof $classe) {
            throw new LogicException("La fiche créée n'est pas un {$classe}.");
        }

        return $fiche;
    }

    /**
     * Une facture d'achat saisie puis validée par cette personne : ses
     * lignes font naître leurs produits et entrer leur stock.
     *
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     * @param  list<PieceDeposee>  $pieces
     */
    public function acheter(User $auteur, Fournisseur $fournisseur, array $lignes, array $attributs, array $pieces = []): FactureAchat
    {
        return $this->dans(fn (): FactureAchat => app(ValiderFactureAchatAction::class)->handle(
            $auteur,
            app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, $lignes, $attributs, null, $pieces),
            self::jeton(),
        ));
    }

    /**
     * Un retour au fournisseur sur une facture d'achat validée.
     *
     * @param  list<array{ligne_achat_id: int, quantite: int}>  $lignes
     */
    public function retournerAuFournisseur(User $auteur, FactureAchat $facture, array $lignes, string $motif, string $numeroFournisseur): AvoirFournisseur
    {
        return $this->dans(fn (): AvoirFournisseur => app(EnregistrerAvoirFournisseurAction::class)->handle($auteur, $facture, $lignes, $motif, $numeroFournisseur, self::jeton()));
    }

    /**
     * L'inventaire d'ouverture du magasin, saisi puis validé par cette
     * personne : ses lignes font naître leurs produits au coût saisi.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    public function ouvrirLeStock(User $auteur, array $lignes): InventaireOuverture
    {
        return $this->dans(fn (): InventaireOuverture => app(ValiderInventaireOuvertureAction::class)->handle(
            $auteur,
            app(CreerInventaireOuvertureAction::class)->handle($auteur, $lignes),
            self::jeton(),
        ));
    }

    public function produit(string $reference): Produit
    {
        return $this->dans(fn (): Produit => Produit::query()->where('reference', $reference)->sole());
    }

    public function service(string $nom): Service
    {
        return $this->dans(fn (): Service => Service::query()->where('nom', $nom)->sole());
    }

    public function comptoir(): Client
    {
        return $this->dans(fn (): Client => Client::query()->where('est_comptoir', true)->sole());
    }

    /**
     * Une vente à la caisse, réglée en entier : en un seul mode, ou en
     * plusieurs, chaque part donnée en centimes, la dernière part prenant
     * ce qui reste à payer après le crédit du client et les bons d'avoir.
     *
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneDocument
     * @param  list<array{0: ModePaiement, 1: int}>  $partsDonnees  les premières parts, en centimes
     * @param  list<string>  $bonsDAvoir  les numéros des bons d'avoir présentés
     */
    public function vendreALaCaisse(User $vendeur, Client $client, array $lignes, ModePaiement $dernierMode = ModePaiement::Especes, array $partsDonnees = [], array $bonsDAvoir = []): Facture
    {
        return $this->dans(function () use ($vendeur, $client, $lignes, $dernierMode, $partsDonnees, $bonsDAvoir): Facture {
            $totalTtc = app(ConstructeurLigneDocument::class)->apercu($lignes, ContexteDeVente::pour($client, $this->magasin))->totaux['total_ttc'];
            $reste = ApercuDuReglement::aLaCaisse($client, app(CalculateurLigne::class)->enCentiemes($totalTtc), $bonsDAvoir)->resteARepartir;
            $parts = array_map(static fn (array $part): PartDeReglement => new PartDeReglement($part[0], $part[1]), $partsDonnees);
            $dernierePart = $reste - array_sum(array_column($partsDonnees, 1));

            if ($dernierePart < 0) {
                throw new LogicException('Les parts données dépassent ce qui reste à payer.');
            }

            if ($dernierePart > 0) {
                $parts[] = new PartDeReglement($dernierMode, $dernierePart);
            }

            return app(EnregistrerVenteCaisseAction::class)->handle($vendeur, $client, $lignes, new ReglementComptant($parts, $bonsDAvoir), null, self::jeton());
        });
    }

    /**
     * Une facture émise par cette personne, sans règlement à l'émission,
     * ou réglée à l'émission par ces parts (« Émettre et encaisser »).
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  list<PartDeReglement>  $parts
     * @param  array<string, mixed>  $attributs
     */
    public function facturer(User $vendeur, Client $client, array $lignes, array $parts = [], array $attributs = []): Facture
    {
        return $this->dans(fn (): Facture => app(EmettreFactureAction::class)->handle(
            $vendeur,
            app(CreerFactureAction::class)->handle($vendeur, $client, $lignes, $attributs),
            $parts === [] ? null : ReglementComptant::horsDeLaCaisse($parts),
            null,
            self::jeton(),
        ));
    }

    /**
     * Un encaissement sur une facture émise, en un ou plusieurs modes, reçu
     * ce jour.
     *
     * @param  list<PartDeReglement>  $parts
     */
    public function encaisser(User $vendeur, Facture $facture, array $parts, string $recuLe): Facture
    {
        return $this->dans(fn (): Facture => app(EncaisserEnPlusieursModesAction::class)->handle($vendeur, $facture, $parts, [], $recuLe, self::jeton()));
    }

    /**
     * Un paiement d'un seul mode imputé sur une facture, reçu ce jour.
     */
    public function payer(User $vendeur, Facture $facture, ModePaiement $mode, string $montant, string $recuLe, ?string $reference = null): Paiement
    {
        return $this->dans(fn (): Paiement => app(EnregistrerPaiementAction::class)->handle(
            $vendeur,
            $facture->client()->firstOrFail(),
            ['mode_paiement' => $mode->value, 'montant' => $montant, 'recu_le' => $recuLe, 'reference' => $reference],
            [['facture_id' => $facture->getKey(), 'montant' => $montant]],
            self::jeton(),
        ));
    }

    /**
     * La contre-passation d'un paiement saisi par erreur.
     */
    public function contrePasser(User $auteur, Paiement $paiement, string $motif): Paiement
    {
        return $this->dans(fn (): Paiement => app(ContrePasserPaiementAction::class)->handle($auteur, $paiement, $motif, self::jeton()));
    }

    /**
     * Un avoir émis sur des lignes vendues.
     *
     * @param  list<array{ligne_facture_id: int, quantite: int}>  $lignes
     */
    public function rendre(User $auteur, Client $client, array $lignes, string $motif): Avoir
    {
        return $this->dans(fn (): Avoir => app(EnregistrerAvoirAction::class)->handle($auteur, $client, $lignes, $motif, self::jeton()));
    }

    /**
     * Le jeton unique d'une demande (R3), comme celui que porte un écran.
     */
    public static function jeton(): string
    {
        return (string) Str::uuid();
    }
}
