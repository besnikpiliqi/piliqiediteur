<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un geste commencé avant que le magasin ne passe en lecture seule, ou
 * avant que son auteur ne perde son droit : l'écran, le contrôle des
 * droits (voir ControleDesDroits) et la policy ont lu le magasin et
 * l'auteur au début de la requête, encore ouvert et encore autorisé, et
 * le magasin actif (voir TenantContext) garde cette lecture. L'action du
 * domaine relit pourtant le magasin et son auteur en base, sous verrou, au
 * moment où elle écrit : le geste est refusé, et rien ne s'écrit. Les
 * tests de concurrence (voir GestesPendantLaFermetureConcurrenceTest)
 * montrent la même chose quand la fermeture se fait au même instant.
 */
class GesteCommenceAvantLaFermetureTest extends TestCase
{
    use RefreshDatabase;

    private const MAGASIN_FERME = 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.';

    /**
     * @return array<string, array{0: string}>
     */
    public static function gestes(): array
    {
        return [
            'supprimer un brouillon de facture d\'achat' => ['supprimerUnBrouillonDAchat'],
            'supprimer un brouillon de facture' => ['supprimerUnBrouillonDeFacture'],
            'modifier un brouillon de facture' => ['modifierUnBrouillonDeFacture'],
            'créer une facture' => ['creerUneFacture'],
            'supprimer un devis' => ['supprimerUnDevis'],
            'modifier un devis' => ['modifierUnDevis'],
            'accepter un devis' => ['accepterUnDevis'],
            'refuser un devis' => ['refuserUnDevis'],
            'convertir un devis' => ['convertirUnDevis'],
            'créer un devis' => ['creerUnDevis'],
            'archiver un client' => ['archiverUnClient'],
            'restaurer un fournisseur' => ['restaurerUnFournisseur'],
            'enregistrer le panier de la caisse' => ['enregistrerLePanier'],
            'abandonner la vente de la caisse' => ['abandonnerLaVente'],
            'numéroter un devis' => ['numeroterUnDevis'],
            'envoyer un devis' => ['envoyerUnDevis'],
            'noter un bordereau de détaxe' => ['noterUnBordereau'],
            'noter une preuve d\'exonération' => ['noterUnePreuve'],
            'saisir un inventaire d\'ouverture' => ['saisirUnInventaire'],
            'modifier un inventaire d\'ouverture' => ['modifierUnInventaire'],
            'supprimer un inventaire d\'ouverture' => ['supprimerUnInventaire'],
        ];
    }

    #[DataProvider('gestes')]
    public function test_un_geste_commence_avant_la_fermeture_du_magasin_est_refuse_sans_rien_ecrire(string $geste): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$faire, $rienNEstEcrit] = $this->{$geste}();
        $this->fermerSansQueLeMagasinActifLeSache($magasin);
        $evenements = $this->evenements();

        $this->assertThrows($faire, LogicException::class, self::MAGASIN_FERME);

        $rienNEstEcrit();
        $this->assertSame($evenements, $this->evenements());
    }

    /**
     * L'auteur suspendu après avoir ouvert son écran (la caisse comprise) :
     * le geste le relit en base sous le verrou de sa ligne, et le refuse,
     * sans rien écrire.
     */
    #[DataProvider('gestes')]
    public function test_un_compte_suspendu_depuis_l_ouverture_de_son_ecran_ne_fait_plus_rien(string $geste): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$faire, $rienNEstEcrit] = $this->{$geste}();
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), self::responsableDeTest($magasin));
        $evenements = $this->evenements();

        $this->assertThrows($faire, AuthorizationException::class, LecteurDesDroits::REFUS);

        $rienNEstEcrit();
        $this->assertSame($evenements, $this->evenements());
    }

    /**
     * La fermeture se fait par une autre requête : le magasin actif garde
     * l'instance lue avant elle, encore ouverte, comme celle qu'a chargée
     * la requête du geste.
     */
    private function fermerSansQueLeMagasinActifLeSache(Magasin $magasin): void
    {
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');

        $this->assertSame($magasin, app(TenantContext::class)->magasin());
        $this->assertFalse(app(TenantContext::class)->magasin()->estFerme());
    }

    /**
     * Les codes des événements du journal du magasin, dans leur ordre.
     *
     * @return list<string>
     */
    private function evenements(): array
    {
        return EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->pluck('type')->all();
    }

    /**
     * Un produit vendu 12,00 € TTC, sans suivi de stock.
     */
    private function produit(): Produit
    {
        return Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function supprimerUnBrouillonDAchat(): array
    {
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
        ]);

        return [
            fn () => app(SupprimerFactureAchatAction::class)->handle(self::auteur(), $brouillon),
            fn () => $this->assertSame(1, FactureAchat::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function supprimerUnBrouillonDeFacture(): array
    {
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 2]]);

        return [
            fn () => app(SupprimerFactureAction::class)->handle(self::auteur(), $brouillon),
            fn () => $this->assertSame([1, 1], [Facture::query()->count(), $brouillon->lignes()->count()]),
        ];
    }

    /**
     * Retirer un article du brouillon s'inscrirait au journal des
     * événements (JET 323).
     *
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function modifierUnBrouillonDeFacture(): array
    {
        $produit = $this->produit();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);

        return [
            fn () => app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $brouillon->client, [['produit_id' => $produit->id, 'quantite' => 1]]),
            fn () => $this->assertSame(2, $brouillon->lignes()->sole()->quantite),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function creerUneFacture(): array
    {
        $produit = $this->produit();
        $client = Client::factory()->create();

        return [
            fn () => app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]),
            fn () => $this->assertSame(0, Facture::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function supprimerUnDevis(): array
    {
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return [
            fn () => app(SupprimerDevisAction::class)->handle(self::auteur(), $devis),
            fn () => $this->assertNotSoftDeleted($devis),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function modifierUnDevis(): array
    {
        $produit = $this->produit();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        return [
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $devis->client, [['produit_id' => $produit->id, 'quantite' => 3]]),
            fn () => $this->assertSame(1, $devis->lignes()->sole()->quantite),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function accepterUnDevis(): array
    {
        $devis = $this->devisEnvoye();

        return [
            fn () => app(AccepterDevisAction::class)->handle(self::auteur(), $devis),
            fn () => $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function refuserUnDevis(): array
    {
        $devis = $this->devisEnvoye();

        return [
            fn () => app(RefuserDevisAction::class)->handle(self::auteur(), $devis),
            fn () => $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function convertirUnDevis(): array
    {
        $devis = app(AccepterDevisAction::class)->handle(self::auteur(), $this->devisEnvoye());

        return [
            fn () => app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis),
            fn () => $this->assertSame(0, Facture::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function creerUnDevis(): array
    {
        $produit = $this->produit();
        $client = Client::factory()->create();

        return [
            fn () => app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]),
            fn () => $this->assertSame(0, Devis::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function archiverUnClient(): array
    {
        $client = Client::factory()->create();

        return [
            fn () => app(ArchiverUneFicheAction::class)->archiver(self::auteur(), $client),
            fn () => $this->assertNotSoftDeleted($client),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function restaurerUnFournisseur(): array
    {
        $fournisseur = Fournisseur::factory()->create();
        app(ArchiverUneFicheAction::class)->archiver(self::auteur(), $fournisseur);

        return [
            fn () => app(ArchiverUneFicheAction::class)->restaurer(self::auteur(), $fournisseur),
            fn () => $this->assertSoftDeleted($fournisseur),
        ];
    }

    /**
     * Le vendeur ajoute un second article à son panier, que la caisse
     * enregistre à chaque requête : aucun panier ne garde ce second
     * article.
     *
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function enregistrerLePanier(): array
    {
        $vendeur = self::auteur();
        $jeton = (string) Str::uuid();
        $ligne = fn (Produit $produit): array => ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ttc' => '12.00', 'taux_tva' => '20', 'remise_pourcentage' => '0'];
        $coque = $this->produit();
        $housse = $this->produit();
        app(PanierDeCaisse::class)->enregistrer($vendeur, $jeton, null, ['coque' => $ligne($coque)]);

        return [
            fn () => app(PanierDeCaisse::class)->enregistrer($vendeur, $jeton, null, ['coque' => $ligne($coque), 'housse' => $ligne($housse)]),
            fn () => $this->assertTrue(PanierCaisse::query()->get()->every(fn (PanierCaisse $panier): bool => array_keys($panier->lignes) === ['coque'])),
        ];
    }

    /**
     * La vente commencée à la caisse, que son abandon enregistrerait puis
     * inscrirait au journal des événements (JET 320) : aucun panier ne
     * reste.
     *
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function abandonnerLaVente(): array
    {
        $vendeur = self::auteur();
        $jeton = (string) Str::uuid();
        $lignes = ['ligne' => ['produit_id' => $this->produit()->id, 'quantite' => 2, 'prix_unitaire_ttc' => '12.00', 'taux_tva' => '20', 'remise_pourcentage' => '0']];

        return [
            fn () => app(PanierDeCaisse::class)->abandonner($vendeur, $jeton, null, $lignes),
            fn () => $this->assertSame(0, PanierCaisse::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function numeroterUnDevis(): array
    {
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return [
            fn () => app(EmettreDevisAction::class)->handle(self::auteur(), $devis),
            fn () => $this->assertNull($devis->fresh()->numero),
        ];
    }

    /**
     * L'envoi numéroterait le devis brouillon et le confierait à la file
     * d'envoi.
     *
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function envoyerUnDevis(): array
    {
        Queue::fake();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return [
            fn () => app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null),
            function () use ($devis): void {
                $this->assertSame([null, 0], [$devis->fresh()->numero, EnvoiDocument::query()->count()]);
                Queue::assertNothingPushed();
            },
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function noterUnBordereau(): array
    {
        $ticket = self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $this->produit()->id, 'quantite' => 10]], ModePaiement::Especes);

        return [
            fn () => app(NoterBordereauDetaxeAction::class)->handle(self::auteur(), $ticket, '2026FR000123456'),
            fn () => $this->assertSame(0, BordereauDetaxe::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function noterUnePreuve(): array
    {
        $export = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));

        return [
            fn () => app(AjouterPreuveOperationAction::class)->handle(self::auteur(), $export, TypePreuve::Mrn, '26FR000000123456E1'),
            fn () => $this->assertSame(0, PreuveOperation::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function saisirUnInventaire(): array
    {
        return [
            fn () => app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [self::carnets(10)]),
            fn () => $this->assertSame(0, InventaireOuverture::query()->count()),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function modifierUnInventaire(): array
    {
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [self::carnets(10)]);

        return [
            fn () => app(ModifierInventaireOuvertureAction::class)->handle(self::auteur(), $inventaire, [self::carnets(12)]),
            fn () => $this->assertSame(10, $inventaire->lignes()->sole()->quantite),
        ];
    }

    /**
     * @return array{0: Closure(): mixed, 1: Closure(): void}
     */
    private function supprimerUnInventaire(): array
    {
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [self::carnets(10)]);

        return [
            fn () => app(SupprimerInventaireOuvertureAction::class)->handle(self::auteur(), $inventaire),
            fn () => $this->assertSame([1, 1], [InventaireOuverture::query()->count(), $inventaire->lignes()->count()]),
        ];
    }

    /**
     * Une ligne d'inventaire d'ouverture de carnets, en cette quantité.
     *
     * @return array<string, mixed>
     */
    private static function carnets(int $quantite): array
    {
        return ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => $quantite, 'cout_unitaire_ht' => '2.00'];
    }

    /**
     * Un devis de 12,00 € TTC envoyé sans e-mail, numéroté.
     */
    private function devisEnvoye(): Devis
    {
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
    }
}
