<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Chaque vente enregistre le magasin où elle est faite, celui de l'adresse
 * de la page, avec son SIRET, et la personne qui l'a faite, affectée et
 * autorisée dans ce magasin au moment de la vente ; une personne
 * n'encaisse que dans un magasin à la fois (voir
 * VerificateurCaissesPersonnelles).
 */
class TracabiliteDesVentesTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $villeurbanne;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->villeurbanne = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Villeurbanne']);
    }

    public function test_une_vente_enregistre_le_magasin_de_l_adresse_et_son_siret_jamais_un_autre_magasin_du_vendeur(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();

        $this->encaisserDepuisLaPage($vendeur, $this->villeurbanne, $this->ouvrirLaCaisse($vendeur, $this->villeurbanne));

        $vente = Facture::withoutGlobalScope(MagasinScope::class)->sole();
        $this->assertSame([$this->villeurbanne->id, $this->villeurbanne->siret, $vendeur->id], [$vente->magasin_id, $this->siretEmetteurDe($vente), $vente->cree_par]);
    }

    public function test_le_contenu_signe_porte_le_siret_du_magasin_et_l_operateur(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();

        $this->encaisserDepuisLaPage($vendeur, $this->villeurbanne, $this->ouvrirLaCaisse($vendeur, $this->villeurbanne));

        $vente = Facture::withoutGlobalScope(MagasinScope::class)->sole();
        $contenu = EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('chaine', ChaineFiscale::Vente->value)->where('document_id', $vente->id)->sole()->contenuSigne();
        $this->assertSame(['uuid' => $this->villeurbanne->uuid, 'siren' => $this->villeurbanne->societe->siren, 'siret' => $this->villeurbanne->siret], $contenu['magasin']);
        $this->assertSame(['id' => $vendeur->id, 'nom' => $vendeur->name], $contenu['operateur']);
    }

    public function test_un_vendeur_de_deux_magasins_n_encaisse_dans_b_que_s_il_y_est_affecte_au_moment_de_la_vente(): void
    {
        $vendeur = $this->membre($this->lyon, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($this->villeurbanne);

        $this->assertThrows(fn () => $this->vendre($vendeur, $this->villeurbanne), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->count());

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($this->lyon), $vendeur, [$this->villeurbanne->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertSame($this->villeurbanne->id, $this->vendre($vendeur, $this->villeurbanne)->magasin_id);
    }

    public function test_retirer_le_vendeur_de_b_pendant_qu_un_onglet_est_ouvert_refuse_la_vente_suivante_dans_b(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();
        $onglet = $this->ouvrirLaCaisse($vendeur, $this->villeurbanne);

        app(TenantContext::class)->runAs($this->villeurbanne, fn () => app(ModifierMembreAction::class)->handle($this->proprietaireDe($this->lyon), $vendeur, [$this->villeurbanne->id => null]));

        $this->actingAs($vendeur)->requeteLivewire($onglet, [], 'encaisser')->assertNotFound();
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * Deux onglets ouverts, l'un sur la caisse de Lyon, l'autre sur celle
     * de Villeurbanne : chaque vente revient au magasin de son onglet. Le
     * vendeur ferme sa caisse de Lyon avant d'encaisser à Villeurbanne.
     */
    public function test_deux_onglets_de_deux_magasins_n_echangent_jamais_leur_magasin(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();
        $ongletDeLyon = $this->ouvrirLaCaisse($vendeur, $this->lyon);
        $ongletDeVilleurbanne = $this->ouvrirLaCaisse($vendeur, $this->villeurbanne);

        $this->encaisserDepuisLaPage($vendeur, $this->lyon, $ongletDeLyon);
        app(TenantContext::class)->runAs($this->lyon, fn () => app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur));
        $this->encaisserDepuisLaPage($vendeur, $this->villeurbanne, $ongletDeVilleurbanne);

        $this->assertSame(
            [[$this->lyon->id, $this->lyon->siret], [$this->villeurbanne->id, $this->villeurbanne->siret]],
            Facture::withoutGlobalScope(MagasinScope::class)->orderBy('id')->get()->map(fn (Facture $vente): array => [$vente->magasin_id, $this->siretEmetteurDe($vente)])->all(),
        );
    }

    public function test_un_vendeur_n_encaisse_dans_b_qu_apres_avoir_ferme_sa_caisse_de_a(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();
        $this->vendre($vendeur, $this->lyon);

        $this->assertThrows(fn () => $this->vendre($vendeur, $this->villeurbanne), LogicException::class, 'Fermez d\'abord votre caisse de Lyon : vous n\'encaissez que dans un magasin à la fois.');
        $this->assertSame(0, Facture::query()->count());

        app(TenantContext::class)->runAs($this->lyon, fn () => app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur));

        $this->assertSame($this->villeurbanne->id, $this->vendre($vendeur, $this->villeurbanne)->magasin_id);
    }

    /**
     * Un responsable des deux magasins a tous les droits dans chacun, mais
     * une seule caisse ouverte à la fois : à Villeurbanne, ni vente, ni
     * émission, ni paiement, ni contre-passation tant que sa caisse de
     * Lyon est ouverte.
     */
    public function test_un_responsable_de_deux_magasins_n_encaisse_que_dans_un_a_la_fois(): void
    {
        $responsable = $this->responsable($this->lyon, $this->villeurbanne);
        app(TenantContext::class)->set($this->villeurbanne);
        $ticketDeVilleurbanne = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), $this->lignes()));
        $paiement = $this->payer($responsable, $ticketDeVilleurbanne);
        $brouillon = app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), $this->lignes());
        app(FermerSaCaisseAction::class)->handle($responsable, $responsable);
        $this->vendre($responsable, $this->lyon);
        $refus = 'Fermez d\'abord votre caisse de Lyon : vous n\'encaissez que dans un magasin à la fois.';
        app(TenantContext::class)->set($this->villeurbanne);

        $this->assertThrows(fn () => $this->vendre($responsable, $this->villeurbanne), LogicException::class, $refus);
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($responsable, $brouillon), LogicException::class, $refus);
        $this->assertThrows(fn () => $this->payer($responsable, $this->vendreParLeProprietaire($this->villeurbanne)), LogicException::class, $refus);
        $this->assertThrows(fn () => app(ContrePasserPaiementAction::class)->handle($responsable, $paiement, 'Erreur de saisie'), LogicException::class, $refus);

        app(TenantContext::class)->runAs($this->lyon, fn () => app(FermerSaCaisseAction::class)->handle($responsable, $responsable));

        $this->assertSame($this->villeurbanne->id, $this->vendre($responsable, $this->villeurbanne)->magasin_id);
    }

    /**
     * Version déterministe, sous les deux moteurs, de
     * TracabiliteDesVentesConcurrenceTest : la première vente du vendeur à
     * Lyon est faite dès que sa vente de Villeurbanne a verrouillé son
     * registre ; sous le verrou de sa ligne, la vente de Villeurbanne la
     * voit et est refusée. (Une seule connexion SQLite : la vente de Lyon,
     * faite dans la transaction de celle de Villeurbanne, s'annule avec
     * elle.)
     */
    public function test_deux_premieres_ventes_simultanees_du_meme_vendeur_dans_a_et_b_n_en_laissent_passer_qu_une(): void
    {
        $vendeur = $this->vendeurDesDeuxMagasins();
        $client = Client::factory()->create();
        $lignes = $this->lignes();
        $this->aLaPremiereRequeteDeLAction(fn () => app(TenantContext::class)->runAs($this->lyon, fn () => $this->vendre($vendeur, $this->lyon)));
        app(TenantContext::class)->set($this->villeurbanne);

        $this->assertThrows(
            fn () => self::vendreAuComptant($vendeur, $client, $lignes, ModePaiement::Especes),
            LogicException::class,
            'Fermez d\'abord votre caisse de Lyon : vous n\'encaissez que dans un magasin à la fois.',
        );
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $this->villeurbanne->id)->count());
    }

    private function vendeurDesDeuxMagasins(): User
    {
        return $this->membreDeCesMagasins([
            $this->lyon->id => ['role' => RoleMagasin::Vendeur],
            $this->villeurbanne->id => ['role' => RoleMagasin::Vendeur],
        ], $this->lyon);
    }

    /**
     * Le vendeur ouvre la caisse du magasin, où l'attend son panier d'un
     * article : l'instantané de la page, pour la requête suivante.
     */
    private function ouvrirLaCaisse(User $vendeur, Magasin $magasin): string
    {
        app(TenantContext::class)->runAs($magasin, fn () => PanierCaisse::factory()->avecLeProduit(Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]), 1)->create(['user_id' => $vendeur->id]));
        $page = $this->actingAs($vendeur)->get(Caisse::getUrl(tenant: $magasin))->assertOk();

        return $this->instantaneLivewire($page->getContent(), 'caisse');
    }

    private function encaisserDepuisLaPage(User $vendeur, Magasin $magasin, string $onglet): void
    {
        $avant = Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->count();

        $this->actingAs($vendeur)->requeteLivewire($onglet, [], 'encaisser')->assertOk();

        $this->assertSame($avant + 1, Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->count());
    }

    /**
     * Une vente de 12,00 € TTC à la caisse du magasin, par ce compte.
     */
    private function vendre(User $vendeur, Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(), $this->lignes(), ModePaiement::Especes));
    }

    /**
     * Un ticket de 12,00 € TTC émis par le propriétaire dans le magasin,
     * non réglé.
     */
    private function vendreParLeProprietaire(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin): Facture {
            $proprietaire = $this->proprietaireDe($magasin);

            return app(EmettreFactureAction::class)->handle($proprietaire, app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()));
        });
    }

    /**
     * Le SIRET que la vente a figé à son émission, lu dans son magasin.
     */
    private function siretEmetteurDe(Facture $vente): ?string
    {
        return app(TenantContext::class)->runAs(Magasin::query()->findOrFail($vente->magasin_id), fn (): ?string => $vente->emetteur()->siret);
    }

    private function payer(User $auteur, Facture $facture): mixed
    {
        return app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client, ['mode_paiement' => ModePaiement::CarteBancaire->value, 'montant' => $facture->total_ttc, 'recu_le' => today()->toDateString()], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);
    }

    /**
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private function aLaPremiereRequeteDeLAction(Closure $changement): void
    {
        $fait = false;
        DB::listen(function (QueryExecuted $requete) use (&$fait, $changement): void {
            if (! $fait) {
                $fait = true;
                $changement();
            }
        });
    }
}
