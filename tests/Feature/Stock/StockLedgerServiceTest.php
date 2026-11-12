<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\App\Resources\ProduitResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class StockLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_reception_dachat_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);
        $achat = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 10);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10, $achat));

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    public function test_une_vente_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 3);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3, $vente));

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_un_retour_fournisseur_diminue_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10, 'cmup_ht' => 5]);
        $avoir = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $produit, 4, '5');

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->retournerAuFournisseur($produit, 4, 50000, $avoir, 'Retour'));

        $this->assertSame(6, $produit->fresh()->stock_quantite);
    }

    /**
     * Un retour au fournisseur sort au coût de sa ligne d'achat, gardé dans
     * son mouvement, et recalcule le CMUP du stock restant : 10 reçus à
     * 2 €, puis 10 à 10 € (CMUP 6 €) ; les 10 à 10 € retournés laissent
     * 10 articles à 2 €, 4 seulement en laissent 16 à (120 - 40) / 16 =
     * 5 €.
     */
    public function test_un_retour_fournisseur_sort_au_cout_de_sa_ligne_d_achat(): void
    {
        $tousRetournes = $this->produitRecuDeuxFois();
        $quatreRetournes = $this->produitRecuDeuxFois();
        $stock = app(StockLedgerService::class);

        $avoirDeTous = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $tousRetournes, 10, '10');
        $avoirDeQuatre = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $quatreRetournes, 4, '10');

        $mouvement = $this->commeAuteurDuDocument(fn () => $stock->retournerAuFournisseur($tousRetournes, 10, 100000, $avoirDeTous, 'Retour'));
        $this->commeAuteurDuDocument(fn () => $stock->retournerAuFournisseur($quatreRetournes, 4, 100000, $avoirDeQuatre, 'Retour'));

        $this->assertSame([10, '2.0000'], [$tousRetournes->fresh()->stock_quantite, $tousRetournes->fresh()->cmup_ht]);
        $this->assertSame([TypeMouvementStock::RetourFournisseur, 10, '10.0000'], [$mouvement->type, $mouvement->quantite, $mouvement->fresh()->cout_unitaire_ht]);
        $this->assertSame([16, '5.0000'], [$quatreRetournes->fresh()->stock_quantite, $quatreRetournes->fresh()->cmup_ht]);
    }

    /**
     * Le CMUP du stock restant s'arrondit une seule fois au dix-millième :
     * 3 reçus à 1 €, puis 3 à 2 € et 1 à 2 € (CMUP 1,5714 €) ; un article
     * retourné à 2 € laisse 6 articles valant 7 x 1,5714 - 2 = 8,9998 €,
     * soit 1,5000 € chacun.
     */
    public function test_le_cmup_apres_un_retour_s_arrondit_une_seule_fois(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0, 'cmup_ht' => 1]);
        $stock = app(StockLedgerService::class);
        $premiereReception = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 3, '1');
        $reception = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 4, '2');
        $this->commeAuteurDuDocument(function () use ($stock, $produit, $premiereReception, $reception): void {
            $stock->receptionner($produit, 3, 10000, $premiereReception, 'Réception');
            $stock->receptionner($produit, 3, 20000, $reception, 'Réception');
            $stock->receptionner($produit, 1, 20000, $reception, 'Réception');
        });
        $this->assertSame('1.5714', $produit->fresh()->cmup_ht);
        $avoir = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $produit, 1, '2');

        $this->commeAuteurDuDocument(fn () => $stock->retournerAuFournisseur($produit, 1, 20000, $avoir, 'Retour'));

        $this->assertSame([6, '1.5000'], [$produit->fresh()->stock_quantite, $produit->fresh()->cmup_ht]);
    }

    /**
     * Sans stock restant, il n'y a plus de coût moyen à recalculer : 5
     * reçus à 3 €, tous retournés, et le CMUP reste 3 €.
     */
    public function test_un_retour_de_tout_le_stock_garde_le_cmup(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0, 'cmup_ht' => 3]);
        $reception = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 5, '3');
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->receptionner($produit, 5, 30000, $reception, 'Réception'));
        $avoir = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $produit, 5, '3');

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->retournerAuFournisseur($produit, 5, 30000, $avoir, 'Retour'));

        $this->assertSame([0, '3.0000'], [$produit->fresh()->stock_quantite, $produit->fresh()->cmup_ht]);
    }

    /**
     * Un stock ne vaut jamais moins que rien : 10 reçus à 2 €, 10 à 10 €
     * (CMUP 6 €), 15 vendus ; les 5 restants valent 30 €. En retourner 4 à
     * 10 € (40 €) laisserait le dernier article à -10 € : il vaut 0 €.
     */
    public function test_un_retour_qui_rendrait_la_valeur_du_stock_negative_la_ramene_a_zero(): void
    {
        $produit = $this->produitRecuDeuxFois();
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 15);
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 15, $vente));
        $avoir = $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $produit, 4, '10');

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->retournerAuFournisseur($produit, 4, 100000, $avoir, 'Retour'));

        $this->assertSame([1, '0.0000'], [$produit->fresh()->stock_quantite, $produit->fresh()->cmup_ht]);
    }

    /**
     * Un retour fournisseur sort à son propre coût : il ne s'enregistre pas
     * comme un mouvement ordinaire, au CMUP du moment.
     */
    public function test_un_retour_fournisseur_ne_s_enregistre_pas_comme_un_mouvement_ordinaire(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 10]);
        $mouvementsAvant = $produit->mouvementsStock()->count();

        $this->assertThrows(
            fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourFournisseur, 4, $this->justificatifDuStock(TypeMouvementStock::RetourFournisseur, $produit, 4)),
            InvalidArgumentException::class,
            'Un retour fournisseur passe par retournerAuFournisseur(), avec le coût de sa ligne d\'achat.',
        );

        $this->assertSame([10, $mouvementsAvant], [$produit->fresh()->stock_quantite, $produit->mouvementsStock()->count()]);
    }

    /**
     * 7 en stock, 2 vendus : le retour de ces 2 articles par un avoir les
     * fait rentrer.
     */
    public function test_un_retour_client_augmente_le_stock(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 7]);
        $ligneDAvoir = $this->justificatifDuStock(TypeMouvementStock::RetourClient, $produit, 2);
        $this->assertSame(5, $produit->fresh()->stock_quantite);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::RetourClient, 2, $ligneDAvoir));

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    public function test_chaque_mouvement_est_trace_dans_le_ledger(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);

        $achat = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 5);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 5, $achat));
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente));

        $this->assertSame(2, $produit->mouvementsStock()->count());
    }

    /**
     * Un ajustement, seul celui qui corrige une réception validée existe :
     * 10 reçus, la facture d'achat corrigée à 7 ; l'écart part de la
     * quantité cible.
     */
    public function test_un_ajustement_calcule_le_delta_depuis_la_quantite_cible(): void
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0]);
        $achat = $this->receptionValidee($produit, 10);

        $this->corrigerLaReception($achat, $produit, 7);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    /**
     * Le type d'un ajustement dit son sens : le stock se recalcule depuis
     * le seul grand livre, sans lire la note des mouvements.
     */
    public function test_un_ajustement_enregistre_son_sens_dans_le_grand_livre(): void
    {
        $produit = Produit::factory()->create();
        $achat = $this->receptionValidee($produit, 10);

        $this->corrigerLaReception($achat, $produit, 7);
        $this->corrigerLaReception($achat, $produit, 12);
        $sortie = MouvementStock::query()->where('produit_id', $produit->id)->where('type', TypeMouvementStock::AjustementSortie->value)->sole();
        $entree = MouvementStock::query()->where('produit_id', $produit->id)->where('type', TypeMouvementStock::AjustementEntree->value)->sole();

        $this->assertSame([TypeMouvementStock::AjustementSortie, 3], [$sortie->type, $sortie->quantite]);
        $this->assertSame([TypeMouvementStock::AjustementEntree, 5], [$entree->type, $entree->quantite]);
        $this->assertSame(12, $produit->mouvementsStock()->get()->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite)));
        $this->assertSame(12, $produit->fresh()->stock_quantite);
    }

    public function test_une_quantite_negative_ou_nulle_est_refusee(): void
    {
        $produit = Produit::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 0, $produit);
    }

    /**
     * Le passage au seuil d'alerte prévient ceux qui gèrent le stock du
     * magasin (permission de gérer le stock : le propriétaire par défaut),
     * pas les vendeurs, ni le propriétaire d'un autre magasin.
     */
    public function test_le_passage_au_seuil_d_alerte_previent_ceux_qui_gerent_le_stock(): void
    {
        $proprietaireAutreMagasin = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Autre boutique'], $proprietaireAutreMagasin);
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr']);
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'reference' => 'CAB-USBC', 'stock_quantite' => 7, 'seuil_alerte' => 5]);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2, auteur: $vendeur);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente), $vendeur);

        $notification = $proprietaire->notifications()->sole();
        $this->assertSame(StockSousLeSeuilDAlerte::class, $notification->type);
        $this->assertSame('Ma Boutique : Stock bas : Câble USB-C', $notification->data['title']);
        $this->assertSame('Plus que 5 article(s) en stock (référence CAB-USBC, seuil d\'alerte : 5).', $notification->data['body']);
        $this->assertSame(ProduitResource::getUrl('edit', ['record' => $produit], panel: 'app', tenant: $magasin), $notification->data['actions'][0]['url']);
        $this->assertSame($magasin->id, $notification->data['magasin_id']);
        $this->assertSame(0, $vendeur->notifications()->count());
        $this->assertSame(0, $proprietaireAutreMagasin->notifications()->count());
    }

    /**
     * Les destinataires suivent les autorisations cochées : un membre aux
     * cases du modèle de l'administrateur, qui comprend la gestion du stock,
     * et le vendeur à qui le propriétaire l'a cochée sont prévenus ; un
     * vendeur sans cette case ne l'est pas.
     */
    public function test_l_alerte_previent_les_membres_a_qui_la_gestion_du_stock_est_cochee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce));
        $vendeurQuiGereLeStock = $this->membre($magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce),
            PermissionRegistry::PRODUIT_AJUSTER_STOCK,
        ]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $produit = Produit::factory()->create(['stock_quantite' => 7, 'seuil_alerte' => 5]);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2, auteur: $vendeur);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente), $vendeur);

        $this->assertSame(1, $administrateur->notifications()->count());
        $this->assertSame(1, $vendeurQuiGereLeStock->notifications()->count());
        $this->assertSame(0, $vendeur->notifications()->count());
    }

    /**
     * L'alerte va au propriétaire, sans case, et à toute personne qui a
     * accès au magasin et y est autorisée à gérer le stock : un
     * administrateur du magasin qui en a la case, un vendeur à qui on l'a
     * cochée ; ni à un vendeur sans la case, ni à une autre société.
     */
    public function test_l_alerte_de_stock_va_au_proprietaire_et_aux_personnes_autorisees_du_magasin(): void
    {
        $proprietaireDAilleurs = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ailleurs']));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $vendeurQuiGereLeStock = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::PRODUIT_AJUSTER_STOCK]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $produit = Produit::factory()->create(['stock_quantite' => 7, 'seuil_alerte' => 5]);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2, auteur: $vendeur);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente), $vendeur);

        $this->assertSame(
            [1, 1, 1, 0, 0],
            [$this->proprietaireDe($magasin)->notifications()->count(), $administrateur->notifications()->count(), $vendeurQuiGereLeStock->notifications()->count(), $vendeur->notifications()->count(), $proprietaireDAilleurs->notifications()->count()],
        );
    }

    /**
     * Un responsable a tous les droits de son magasin, sans case : il est
     * prévenu ; celui d'un autre magasin de la société ne l'est pas.
     */
    public function test_l_alerte_de_stock_va_aux_responsables_du_magasin_sans_case(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $responsable = $this->responsable($magasin);
        $coResponsable = $this->responsable($magasin);
        $responsableDAilleurs = $this->responsable($autreMagasin);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['stock_quantite' => 7, 'seuil_alerte' => 5]);
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2, auteur: $responsable);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente), $responsable);

        $this->assertSame([1, 1, 0], [$responsable->notifications()->count(), $coResponsable->notifications()->count(), $responsableDAilleurs->notifications()->count()]);
    }

    public function test_l_alerte_de_stock_ne_va_ni_a_un_compte_suspendu_ni_retire_ni_a_un_administrateur_hors_de_sa_selection_ni_a_un_compte_du_cabinet_lie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->responsable($magasin);
        $retire = $this->responsable($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);
        $administrateurDAilleurs = $this->administrateur($magasin, [$autreMagasin->id]);
        $this->collaborateurDuCabinetLie($magasin);
        app(TenantContext::class)->set($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $produit = Produit::factory()->create(['stock_quantite' => 7, 'seuil_alerte' => 5]);
        $avant = DB::table('notifications')->pluck('id')->all();
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2, auteur: $vendeur);

        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente), $vendeur);

        $destinataires = DB::table('notifications')->whereNotIn('id', $avant)->pluck('notifiable_id')->map(intval(...))->all();
        $this->assertSame([(int) $proprietaire->id], $destinataires);
        $this->assertSame([0, 0, 0], [$suspendu->notifications()->count(), $retire->notifications()->count(), $administrateurDAilleurs->notifications()->count()]);
    }

    /**
     * Une alerte au passage du seuil, pas une à chaque vente tant que le
     * stock reste bas ; une nouvelle après un réapprovisionnement.
     */
    public function test_un_stock_deja_bas_ne_previent_pas_a_chaque_mouvement(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 5, 'seuil_alerte' => 5]);
        $stock = app(StockLedgerService::class);

        $ventes = [$this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 1), $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 2)];
        $achat = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 10);
        $derniereVente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 12);

        $this->commeAuteurDuDocument(fn () => $stock->enregistrer($produit, TypeMouvementStock::Vente, 1, $ventes[0]));
        $this->commeAuteurDuDocument(fn () => $stock->enregistrer($produit, TypeMouvementStock::Vente, 2, $ventes[1]));
        $this->assertSame(0, $proprietaire->notifications()->count());

        $this->commeAuteurDuDocument(fn () => $stock->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10, $achat));
        $this->commeAuteurDuDocument(fn () => $stock->enregistrer($produit, TypeMouvementStock::Vente, 12, $derniereVente));

        $this->assertSame('Plus aucun article en stock (référence '.$produit->reference.', seuil d\'alerte : 5).', $proprietaire->notifications()->sole()->data['body']);
    }

    /**
     * Une facture à deux lignes du même produit fait deux mouvements dans
     * la même transaction : l'alerte dit le stock qui reste vraiment, pas
     * celui du premier mouvement.
     */
    public function test_l_alerte_donne_le_stock_restant_apres_toute_la_transaction(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['reference' => 'CAB-USBC', 'stock_quantite' => 7, 'seuil_alerte' => 5]);

        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 6);

        $this->commeAuteurDuDocument(fn () => DB::transaction(function () use ($produit, $vente) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 2, $vente);
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4, $vente);
        }));

        $this->assertSame('Plus que 1 article(s) en stock (référence CAB-USBC, seuil d\'alerte : 5).', $proprietaire->notifications()->sole()->data['body']);
    }

    /**
     * Repassé au-dessus du seuil avant la fin de la transaction (une
     * réception corrigée, par exemple) : il n'y a plus rien à signaler.
     */
    public function test_un_stock_remonte_au_dessus_du_seuil_dans_la_transaction_ne_previent_personne(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 6, 'seuil_alerte' => 5]);

        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 3);
        $achat = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 10);

        $this->commeAuteurDuDocument(fn () => DB::transaction(function () use ($produit, $vente, $achat) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3, $vente);
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::ReceptionAchat, 10, $achat);
        }));

        $this->assertSame(13, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $proprietaire->notifications()->count());
    }

    public function test_un_mouvement_annule_par_sa_transaction_ne_previent_personne(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['stock_quantite' => 6, 'seuil_alerte' => 5]);

        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $produit, 3);

        $this->assertThrows(fn () => $this->commeAuteurDuDocument(fn () => DB::transaction(function () use ($produit, $vente) {
            app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 3, $vente);

            throw new RuntimeException('Vente abandonnée');
        })), RuntimeException::class);

        $this->assertSame(6, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $proprietaire->notifications()->count());
    }

    public function test_un_produit_sans_suivi_de_stock_refuse_tout_mouvement(): void
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->expectException(\LogicException::class);
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 1, $produit);
    }

    /**
     * 10 articles reçus à 2 €, puis 10 à 10 € : 20 en stock à 6 €.
     */
    private function produitRecuDeuxFois(): Produit
    {
        $produit = Produit::factory()->create(['stock_quantite' => 0, 'cmup_ht' => 2]);
        $premiere = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 10, '2');
        $seconde = $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, $produit, 10, '10');
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->receptionner($produit, 10, 20000, $premiere, 'Réception'));
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->receptionner($produit, 10, 100000, $seconde, 'Réception'));

        return $produit;
    }

    /**
     * Une facture d'achat de $quantite articles à 2,00 € HT, validée par son
     * action : sa réception fait entrer le produit en stock.
     */
    private function receptionValidee(Produit $produit, int $quantite): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => 2],
        ], ['date_facture' => Calendrier::deParis()->aujourdhui()]));
    }

    /**
     * La facture d'achat validée corrigée par son action à $quantite
     * articles : l'ajustement du stock en suit l'écart.
     */
    private function corrigerLaReception(FactureAchat $achat, Produit $produit, int $quantite): void
    {
        $achat = $achat->fresh() ?? $achat;

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat, ValeurRequise::de($achat->fournisseur, 'le fournisseur de l\'achat'), [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => 2],
        ]);
    }
}
