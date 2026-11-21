<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les avoirs non utilisés, dans « À surveiller » du tableau de bord du
 * magasin (décision de l'éditeur du 01/11/2026, 22 h 50) : les bons
 * d'avoir du client comptoir dont il reste un crédit, chacun par son
 * numéro, sans nom de client, et les crédits des autres clients, chacun
 * par son nom ; jamais un crédit entièrement imputé ; visibles de qui voit
 * les avoirs du magasin, jamais d'un compte d'un cabinet lié ; et, dans
 * l'espace de la société, leur nombre et leur montant par magasin, sans
 * nom ni numéro, isolés entre magasins et entre sociétés. Montants en
 * centimes, depuis les montants et les imputations enregistrés.
 */
class AvoirsNonUtilisesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un bon d'avoir de 30,00 € du comptoir, dont une vente de 12,00 € a
     * utilisé une partie : il en reste 18,00 €, à surveiller, avec « Voir »
     * vers l'avoir, sans aucun nom de client.
     */
    public function test_un_bon_d_avoir_en_partie_utilise_montre_ce_qui_reste(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $bon = $this->bonDAvoir($responsable, 30);
        self::vendreAuComptant($responsable, $this->comptoir(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]], bonsDAvoir: [$bon->numero]);

        $chose = $this->chose($responsable, $magasin, 'bons_d_avoir');

        $this->assertSame([UrgenceAFaire::Gris, true, 'Voir', 1], [$chose?->urgence, $chose?->aSurveiller, $chose?->bouton, $chose?->nombre]);
        $this->assertSame('1 bon d\'avoir du comptoir n\'est pas encore utilisé : 18,00 € restant.', $chose?->phrase);
        $this->assertSame([['libelle' => (string) $bon->numero, 'url' => AvoirResource::getUrl('view', ['record' => $bon]), 'description' => null, 'montant' => '18,00 €']], array_map(fn (array $ligne): array => array_intersect_key($ligne, array_flip(['libelle', 'url', 'description', 'montant'])), $chose?->lignes ?? []));
    }

    /**
     * Le crédit d'un client professionnel, d'un avoir sur une facture déjà
     * payée, se montre par son nom et son montant, avec sa fiche ; un
     * avoir sur une facture encore due s'y impute en entier, d'office, et
     * n'y figure jamais.
     */
    public function test_le_credit_d_un_client_professionnel_se_montre_et_un_credit_entierement_impute_jamais(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $atelier = Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']);
        $payee = $this->facture($responsable, $atelier, 50);
        app(EnregistrerPaiementAction::class)->handle($responsable, $atelier, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '50.00', 'recu_le' => $magasin->calendrier()->aujourdhui()], [['facture_id' => $payee->id, 'montant' => '50.00']]);
        $avoir = $this->avoirSur($responsable, $payee);
        $due = $this->facture($responsable, Client::factory()->professionnel()->create(['nom' => 'Garage Dupont']), 40);
        $this->avoirSur($responsable, $due);

        $chose = $this->chose($responsable, $magasin, 'credits_des_clients');

        $this->assertSame([1, '1 client a un crédit d\'avoir non utilisé : 50,00 € restant.'], [$chose?->nombre, $chose?->phrase]);
        $ligne = $chose?->lignes[0] ?? [];
        $this->assertSame(['Atelier Martin', ClientResource::getUrl('view', ['record' => $atelier]), 'Avoirs '.$avoir->numero, '50,00 €', null], [$ligne['libelle'] ?? null, $ligne['url'] ?? null, $ligne['description'] ?? null, $ligne['montant'] ?? null, $ligne['badge'] ?? null]);
        $this->assertNull($this->chose($responsable, $magasin, 'bons_d_avoir'));
    }

    /**
     * Visibilité : le propriétaire, un responsable et un vendeur autorisé à
     * voir les avoirs les voient ; un vendeur sans cette autorisation n'en
     * voit rien, ni un compte d'un cabinet lié, même s'il voit les avoirs.
     */
    public function test_les_avoirs_non_utilises_se_montrent_a_qui_voit_les_avoirs_et_jamais_au_cabinet(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $this->bonDAvoir($responsable, 30);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $avecLesAvoirs = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_VIEW, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $sansLesAvoirs = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);

        $this->assertNotNull($this->chose($this->proprietaireDe($magasin), $magasin, 'bons_d_avoir'));
        $this->assertNotNull($this->chose($responsable, $magasin, 'bons_d_avoir'));
        $this->assertNotNull($this->chose($avecLesAvoirs, $magasin, 'bons_d_avoir'));
        $this->assertNull($this->chose($sansLesAvoirs, $magasin, 'bons_d_avoir'));
        $this->assertNull($this->chose($collaborateur, $magasin, 'bons_d_avoir'));
    }

    /**
     * Dans l'espace de la société, chaque magasin montre le nombre et le
     * montant restant de ses avoirs non utilisés, sans nom ni numéro ;
     * ceux d'un autre magasin ou d'une autre société n'y entrent jamais.
     */
    public function test_l_espace_de_la_societe_montre_leur_nombre_et_leur_montant_par_magasin_isoles(): void
    {
        $lyon = $this->magasin();
        $paris = $this->completerIdentiteLegale($this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']));
        $ailleurs = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ailleurs']));
        app(TenantContext::class)->runAs($lyon, fn (): Avoir => $this->bonDAvoir(self::responsableDeTest($lyon), 30));
        app(TenantContext::class)->runAs($lyon, fn (): Avoir => $this->bonDAvoir(self::responsableDeTest($lyon), 12));
        app(TenantContext::class)->runAs($ailleurs, fn (): Avoir => $this->bonDAvoir(self::responsableDeTest($ailleurs), 99));
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $nombres = app(ChosesAFaireDeLaSociete::class)->parMagasin($proprietaire);

        $this->assertSame([['groupe' => 'avoirs non utilisés', 'nombre' => 2, 'montant' => '42,00 €']], array_values(array_filter($nombres[$lyon->id], fn (array $groupe): bool => str_starts_with($groupe['groupe'], 'avoir'))));
        $this->assertSame([], $nombres[$paris->id]);
        $this->assertArrayNotHasKey($ailleurs->id, $nombres);
    }

    private function magasin(): Magasin
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:00'));

        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon']));
    }

    private function produit(int $prixTtc): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    /**
     * Un bon d'avoir de ce montant : un ticket du comptoir réglé en espèces,
     * puis rendu en entier, dans le magasin actif.
     */
    private function bonDAvoir(User $auteur, int $montant): Avoir
    {
        $ticket = self::vendreAuComptant($auteur, $this->comptoir(), [['produit_id' => $this->produit($montant)->id, 'quantite' => 1]]);

        return app(EnregistrerAvoirAction::class)->handle($auteur, $this->comptoir(), [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Article rendu');
    }

    private function facture(User $auteur, Client $client, int $montant): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $this->produit($montant)->id, 'quantite' => 1]]));
    }

    private function avoirSur(User $auteur, Facture $facture): Avoir
    {
        return app(EnregistrerAvoirAction::class)->handle($auteur, $facture->client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');
    }

    /**
     * La chose de cette clé dans l'« À faire » de ce compte, dans ce
     * magasin, qui devient le magasin actif.
     */
    private function chose(User $compte, Magasin $magasin, string $cle): ?ChoseAFaire
    {
        $compte = $compte->fresh() ?? $compte;
        $this->dansLeMagasin($magasin->fresh() ?? $magasin, $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin->fresh() ?? $magasin);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', $cle);
    }
}
