<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'inventaire d'ouverture : le stock existant au démarrage d'un magasin,
 * avec son coût, dont la validation crée les produits et fait entrer leur
 * stock au coût saisi, sans TVA ni fournisseur, jusqu'à la première vente
 * du magasin, par une personne autorisée à valider les achats et à créer
 * des produits (réponses de l'éditeur à Q11-a et Q11-b).
 */
class InventaireOuvertureTest extends TestCase
{
    use RefreshDatabase;

    private const REFUS_APRES_LA_PREMIERE_VENTE = 'Ce magasin a déjà vendu : un inventaire d\'ouverture ne se saisit que jusqu\'à sa première vente. Un produit oublié entre par une facture d\'achat.';

    private const REFUS_VALIDE = 'Cet inventaire d\'ouverture est validé : il ne se modifie ni ne se supprime plus.';

    /**
     * La ligne d'un nouveau carnet : 10 articles à 2,00 € HT.
     *
     * @return array<string, mixed>
     */
    private static function ligneDUnNouveauCarnet(): array
    {
        return ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function saisir(array $lignes, ?User $auteur = null): InventaireOuverture
    {
        return app(CreerInventaireOuvertureAction::class)->handle($auteur ?? self::auteur(), $lignes);
    }

    private function valider(InventaireOuverture $inventaire, ?User $auteur = null, ?string $jeton = null): InventaireOuverture
    {
        return app(ValiderInventaireOuvertureAction::class)->handle($auteur ?? self::auteur(), $inventaire, $jeton);
    }

    private function supprimer(InventaireOuverture $inventaire, ?User $auteur = null): void
    {
        app(SupprimerInventaireOuvertureAction::class)->handle($auteur ?? self::auteur(), $inventaire);
    }

    /**
     * Le brouillon ne crée rien ; sa validation crée chaque nouveau
     * produit, sa référence saisie ou générée, au coût saisi, sans
     * fournisseur, avec son stock, et numérote l'inventaire. 12 x 1,50 € +
     * 50 x 0,30 € = 18,00 € + 15,00 € = 33,00 € HT.
     */
    public function test_un_produit_nait_de_la_validation_d_un_inventaire_d_ouverture_avec_son_cout(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20', 'seuil_alerte' => 3], 'quantite' => 12, 'cout_unitaire_ht' => '1.50'],
            ['produit' => ['nom' => 'Stylo bleu', 'prix_vente_ttc' => '1.20'], 'quantite' => 50, 'cout_unitaire_ht' => '0.30'],
        ]);

        $this->assertSame(0, Produit::query()->count());
        $this->assertSame([StatutInventaireOuverture::Brouillon, null, '33.00'], [$brouillon->statut, $brouillon->numero, $brouillon->valeur_ht]);

        $inventaire = $this->valider($brouillon);

        $this->assertSame(
            [
                ['CARNET-A5', 'Carnet A5', '4.90', '20.00', '1.5000', null, 12, 3, true],
                ['PRD-000001', 'Stylo bleu', '1.20', '20.00', '0.3000', null, 50, 0, true],
            ],
            Produit::query()->orderBy('id')->get()->map(fn (Produit $produit): array => [
                $produit->reference, $produit->nom, $produit->prix_vente_ttc, $produit->taux_tva, $produit->cmup_ht, $produit->fournisseur_id, $produit->stock_quantite, $produit->seuil_alerte, $produit->suivi_stock,
            ])->all(),
        );
        $this->assertSame([StatutInventaireOuverture::Valide, "INV-{$magasin->code}-2026-000001", '33.00', self::auteur()->id], [$inventaire->statut, $inventaire->numero, $inventaire->valeur_ht, (int) $inventaire->valide_par]);
        $this->assertSame(
            [[Produit::query()->where('reference', 'CARNET-A5')->value('id'), 'CARNET-A5', null], [Produit::query()->where('reference', 'PRD-000001')->value('id'), 'PRD-000001', null]],
            $inventaire->lignes()->get()->map(fn ($ligne): array => [$ligne->produit_id, $ligne->reference, $ligne->nouveau_produit])->all(),
        );
    }

    /**
     * Chaque ligne entre en stock à son coût saisi, gardé dans son
     * mouvement, celle d'un nouveau produit comme celle d'un produit du
     * catalogue jamais reçu, dont le coût moyen devient ce coût ; la
     * validation s'inscrit au journal d'audit de la société, dans la
     * chaîne du magasin. 10 x 2,00 € + 4 x 3,25 € = 33,00 € HT.
     */
    public function test_l_inventaire_d_ouverture_entre_le_stock_au_cout_saisi_et_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $tasse = Produit::factory()->create(['reference' => 'TASSE', 'nom' => 'Tasse', 'cmup_ht' => 9, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet(), ['produit_id' => $tasse->id, 'quantite' => 4, 'cout_unitaire_ht' => '3.25']]);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::InventaireOuvertureValide));

        $inventaire = $this->valider($brouillon);

        $carnet = Produit::query()->where('reference', 'CARNET-A5')->sole();
        $note = "Inventaire d'ouverture {$inventaire->numero}";
        $this->assertSame(
            [
                [$carnet->id, 'inventaire_ouverture', 10, '2.0000', 'inventaire_ouverture', $inventaire->id, $note, self::auteur()->id],
                [$tasse->id, 'inventaire_ouverture', 4, '3.2500', 'inventaire_ouverture', $inventaire->id, $note, self::auteur()->id],
            ],
            MouvementStock::query()->orderBy('id')->get()->map(fn (MouvementStock $mouvement): array => [
                $mouvement->produit_id, $mouvement->type->value, $mouvement->quantite, $mouvement->cout_unitaire_ht, $mouvement->source_type, (int) $mouvement->source_id, $mouvement->note, (int) $mouvement->cree_par,
            ])->all(),
        );
        $this->assertSame([['2.0000', 10], ['3.2500', 4]], [[$carnet->cmup_ht, $carnet->stock_quantite], [$tasse->fresh()->cmup_ht, $tasse->fresh()->stock_quantite]]);

        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::InventaireOuvertureValide);
        $this->assertCount(1, $entrees);
        $this->assertSame(
            [(int) $magasin->id, self::auteur()->id, CategorieAudit::Operations, 'inventaire_ouverture', $inventaire->id, null],
            [(int) $entrees[0]->magasin_id, (int) $entrees[0]->auteur_id, $entrees[0]->categorie, $entrees[0]->cible_type, (int) $entrees[0]->cible_id, $entrees[0]->anciennes_valeurs],
        );
        $this->assertSame([
            'inventaire' => $inventaire->numero,
            'lignes' => [
                ['cout_unitaire_ht' => '2.00', 'produit_cree' => true, 'quantite' => 10, 'reference' => 'CARNET-A5', 'suivi_stock' => true],
                ['cout_unitaire_ht' => '3.25', 'produit_cree' => false, 'quantite' => 4, 'reference' => 'TASSE', 'suivi_stock' => true],
            ],
            'valeur_ht' => '33.00',
        ], $entrees[0]->nouvelles_valeurs);
    }

    /**
     * Un produit qui ne suit pas son stock ne reçoit de l'inventaire
     * d'ouverture que son coût de départ : rien n'entre en stock, sa ligne
     * n'a aucune valeur de stock, et le journal d'audit dit qu'elle n'est
     * pas entrée en stock. 10 carnets à 2,00 € qui ne suivent pas leur
     * stock et 50 stylos à 0,30 € : valeur du stock 50 x 0,30 € = 15,00 €
     * HT, et non 35,00 €.
     */
    public function test_un_produit_qui_ne_suit_pas_son_stock_ne_recoit_que_son_cout_sans_valeur_de_stock(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20', 'suivi_stock' => false], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
            ['produit' => ['nom' => 'Stylo bleu', 'reference' => 'STYLO', 'prix_vente_ttc' => '1.20', 'taux_tva' => '20'], 'quantite' => 50, 'cout_unitaire_ht' => '0.30'],
        ]);
        $this->assertSame(['15.00', [[false, '0.00'], [true, '15.00']]], [$brouillon->valeur_ht, $this->suiviEtValeurDesLignes($brouillon)]);

        $inventaire = $this->valider($brouillon);

        $this->assertSame(
            [['CARNET-A5', false, 0, '2.0000'], ['STYLO', true, 50, '0.3000']],
            Produit::query()->orderBy('reference')->get()->map(fn (Produit $produit): array => [$produit->reference, $produit->suivi_stock, $produit->stock_quantite, $produit->cmup_ht])->all(),
        );
        $this->assertSame([Produit::query()->where('reference', 'STYLO')->value('id')], MouvementStock::query()->pluck('produit_id')->all());
        $this->assertSame(['15.00', [[false, '0.00'], [true, '15.00']]], [$inventaire->fresh()->valeur_ht, $this->suiviEtValeurDesLignes($inventaire)]);
        $this->assertSame([
            'inventaire' => $inventaire->numero,
            'lignes' => [
                ['cout_unitaire_ht' => '2.00', 'produit_cree' => true, 'quantite' => 10, 'reference' => 'CARNET-A5', 'suivi_stock' => false],
                ['cout_unitaire_ht' => '0.30', 'produit_cree' => true, 'quantite' => 50, 'reference' => 'STYLO', 'suivi_stock' => true],
            ],
            'valeur_ht' => '15.00',
        ], $this->entreesDAudit($magasin->societe, ActionAuditee::InventaireOuvertureValide)[0]->nouvelles_valeurs);
    }

    /**
     * La validation relit, sous son verrou, le suivi du stock de chaque
     * produit : une tasse du catalogue qui suivait son stock à la saisie
     * (4 x 3,25 € = 13,00 € HT), puis ne le suit plus, ne fait rien entrer
     * en stock, et l'inventaire validé le dit, avec une valeur de stock de
     * 0,00 €.
     */
    public function test_la_validation_relit_le_suivi_du_stock_de_chaque_produit(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $tasse = Produit::factory()->create(['reference' => 'TASSE', 'nom' => 'Tasse', 'suivi_stock' => true, 'stock_quantite' => 0]);
        $brouillon = $this->saisir([['produit_id' => $tasse->id, 'quantite' => 4, 'cout_unitaire_ht' => '3.25']]);
        $this->assertSame(['13.00', [[true, '13.00']]], [$brouillon->valeur_ht, $this->suiviEtValeurDesLignes($brouillon)]);
        $tasse->update(['suivi_stock' => false]);

        $inventaire = $this->valider($brouillon);

        $this->assertSame(['0.00', [[false, '0.00']]], [$inventaire->fresh()->valeur_ht, $this->suiviEtValeurDesLignes($inventaire)]);
        $this->assertSame([0, '3.2500', 0], [$tasse->fresh()->stock_quantite, $tasse->fresh()->cmup_ht, MouvementStock::query()->count()]);
    }

    /**
     * Pour chaque ligne enregistrée, dans l'ordre : si son produit suit son
     * stock, et la valeur du stock qu'elle fait entrer.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function suiviEtValeurDesLignes(InventaireOuverture $inventaire): array
    {
        return $inventaire->lignes()->get()->map(fn (LigneInventaireOuverture $ligne): array => [$ligne->suivi_stock, $ligne->valeur_ht])->all();
    }

    /**
     * Réponse de l'éditeur à Q11-a : l'inventaire d'ouverture se saisit
     * jusqu'à la première vente du magasin. Ensuite, un brouillon ne se
     * valide ni ne se modifie plus, aucun inventaire ne se crée, et rien ne
     * naît ; un produit oublié entre par une facture d'achat.
     */
    public function test_un_inventaire_d_ouverture_n_est_plus_possible_apres_la_premiere_vente(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()]);
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $vendu->id, 'quantite' => 1]]);

        $this->assertThrows(fn () => $this->valider($brouillon), LogicException::class, self::REFUS_APRES_LA_PREMIERE_VENTE);
        $this->assertThrows(fn () => app(ModifierInventaireOuvertureAction::class)->handle(self::auteur(), $brouillon, [self::ligneDUnNouveauCarnet()]), LogicException::class, self::REFUS_APRES_LA_PREMIERE_VENTE);
        $this->assertThrows(fn () => $this->saisir([self::ligneDUnNouveauCarnet()]), LogicException::class, self::REFUS_APRES_LA_PREMIERE_VENTE);
        $this->assertSame([$vendu->id], Produit::query()->pluck('id')->all());
        $this->assertSame([[StatutInventaireOuverture::Brouillon]], InventaireOuverture::query()->get()->map(fn (InventaireOuverture $inventaire): array => [$inventaire->statut])->all());
        $this->assertFalse(self::auteur()->can('create', InventaireOuverture::class));
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function droitsInsuffisants(): array
    {
        return [
            'sans le droit de valider les achats' => [[PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::PRODUIT_CREATE]],
            'sans le droit de créer des produits' => [[PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::FACTURE_ACHAT_VALIDER]],
        ];
    }

    /**
     * Réponse de l'éditeur à Q11-b : l'inventaire d'ouverture se saisit et
     * se valide par une personne autorisée à valider les achats et à créer
     * des produits. À qui n'a pas les deux droits, il est refusé, sans rien
     * créer.
     *
     * @param  list<string>  $autorisations
     */
    #[DataProvider('droitsInsuffisants')]
    public function test_valider_un_inventaire_exige_les_droits_choisis(array $autorisations): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()], $this->proprietaireDe($magasin));
        $membre = $this->membre($magasin, RoleMagasin::Observateur, $autorisations);

        $this->assertThrows(fn () => $this->valider($brouillon, $membre), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertThrows(fn () => $this->saisir([self::ligneDUnNouveauCarnet()], $membre), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame(0, Produit::query()->count());
        $this->assertSame([StatutInventaireOuverture::Brouillon, 1], [$brouillon->fresh()->statut, InventaireOuverture::query()->count()]);
    }

    /**
     * Avec les deux droits, un membre saisit et valide l'inventaire
     * d'ouverture.
     */
    public function test_un_membre_autorise_a_valider_les_achats_et_a_creer_des_produits_saisit_et_valide_un_inventaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::PRODUIT_CREATE]);

        $inventaire = $this->valider($this->saisir([self::ligneDUnNouveauCarnet()], $membre), $membre);

        $this->assertSame([StatutInventaireOuverture::Valide, $membre->id], [$inventaire->statut, (int) $inventaire->valide_par]);
        $this->assertSame(10, Produit::query()->sole()->stock_quantite);
    }

    /**
     * Un expert-comptable, qui a toutes les consultations, voit
     * l'inventaire d'ouverture sans jamais le saisir ni le valider.
     */
    public function test_un_expert_comptable_consulte_un_inventaire_sans_pouvoir_le_saisir_ni_le_valider(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()], $this->proprietaireDe($magasin));
        $expertComptable = $this->expertComptable($magasin);

        $this->assertSame([true, false, false, false], [
            $expertComptable->can('view', $brouillon),
            $expertComptable->can('create', InventaireOuverture::class),
            $expertComptable->can('update', $brouillon),
            $expertComptable->can('valider', $brouillon),
        ]);
        $this->assertThrows(fn () => $this->valider($brouillon, $expertComptable), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Produit::query()->count());
    }

    /**
     * Un inventaire d'ouverture et ses produits restent dans leur magasin :
     * depuis un autre magasin de la même société, l'inventaire est
     * introuvable, et un produit d'un autre magasin ne s'y inventorie pas.
     */
    public function test_un_inventaire_d_un_autre_magasin_est_introuvable(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $tasseDeA = Produit::factory()->create(['nom' => 'Tasse']);
        $inventaireDeA = $this->saisir([self::ligneDUnNouveauCarnet()]);
        $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'Boutique B']);

        $this->assertThrows(fn () => $this->valider($inventaireDeA), LogicException::class, InventaireOuverture::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => app(ModifierInventaireOuvertureAction::class)->handle(self::auteur(), $inventaireDeA, [self::ligneDUnNouveauCarnet()]), LogicException::class, InventaireOuverture::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => $this->saisir([['produit_id' => $tasseDeA->id, 'quantite' => 1, 'cout_unitaire_ht' => '1.00']]), InvalidArgumentException::class, 'Ce produit est introuvable dans le magasin.');
        $this->assertSame([0, 0], [InventaireOuverture::query()->count(), Produit::query()->count()]);

        app(TenantContext::class)->set($magasinA);
        $this->assertSame(StatutInventaireOuverture::Brouillon, $inventaireDeA->fresh()->statut);
        $this->assertSame([$tasseDeA->id], Produit::query()->pluck('id')->all());
    }

    /**
     * Validé, un inventaire d'ouverture ne se modifie, ne se supprime ni ne
     * se valide plus, ses lignes non plus : son stock n'entre qu'une fois.
     */
    public function test_un_inventaire_valide_ne_se_modifie_plus(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $inventaire = $this->valider($this->saisir([self::ligneDUnNouveauCarnet()]));

        $this->assertThrows(fn () => app(ModifierInventaireOuvertureAction::class)->handle(self::auteur(), $inventaire, [self::ligneDUnNouveauCarnet()]), LogicException::class, self::REFUS_VALIDE);
        $this->assertThrows(fn () => $this->valider($inventaire), LogicException::class, 'Cet inventaire d\'ouverture est déjà validé.');
        $this->assertThrows(fn () => $inventaire->fresh()->delete(), LogicException::class, self::REFUS_VALIDE);
        $this->assertThrows(fn () => $inventaire->lignes()->sole()->update(['quantite' => 99]), LogicException::class, self::REFUS_VALIDE);

        $this->assertThrows(fn () => $this->supprimer($inventaire), LogicException::class, self::REFUS_VALIDE);

        $this->assertSame([10, 1, 10], [Produit::query()->sole()->stock_quantite, MouvementStock::query()->count(), $inventaire->lignes()->sole()->quantite]);
        $this->assertFalse(self::auteur()->can('update', $inventaire->fresh()));
        $this->assertFalse(self::auteur()->can('delete', $inventaire->fresh()));
    }

    /**
     * Un brouillon lu avant sa validation, comme par une page restée
     * ouverte, ne se supprime plus une fois validé : la suppression relit
     * l'inventaire sous son verrou, le modèle relit son statut en base, et
     * l'inventaire validé reste, avec ses lignes, son numéro, son produit
     * et son stock.
     */
    public function test_un_brouillon_lu_avant_sa_validation_ne_se_supprime_plus_une_fois_valide(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()]);
        $numero = $this->valider($brouillon)->numero;
        $this->assertSame(StatutInventaireOuverture::Brouillon, $brouillon->statut);

        $this->assertThrows(fn () => $this->supprimer($brouillon), LogicException::class, self::REFUS_VALIDE);
        $this->assertThrows(fn () => $brouillon->delete(), LogicException::class, self::REFUS_VALIDE);

        $this->assertSame([StatutInventaireOuverture::Valide, $numero, 1], [$brouillon->fresh()->statut, $brouillon->fresh()->numero, $brouillon->lignes()->count()]);
        $this->assertSame([10, 1], [Produit::query()->sole()->stock_quantite, MouvementStock::query()->count()]);
    }

    /**
     * Un brouillon se supprime, avec ses lignes, sans rien créer, même
     * après la première vente du magasin, par qui peut saisir l'inventaire
     * d'ouverture ; un membre qui ne peut pas créer de produits ne le
     * supprime pas, ni personne dans un magasin suspendu, en lecture seule.
     */
    public function test_un_brouillon_se_supprime_sans_rien_creer_meme_apres_la_premiere_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()]);
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $vendu->id, 'quantite' => 1]]);
        $membre = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_VIEW]);

        $this->assertThrows(fn () => $this->supprimer($brouillon, $membre), AuthorizationException::class, LecteurDesDroits::REFUS);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->assertThrows(fn () => $this->supprimer($brouillon), LogicException::class, 'Ce magasin est suspendu : seules la consultation et les téléchargements restent possibles.');
        $this->assertSame(1, InventaireOuverture::query()->count());
        $magasin->forceFill(['suspendu_le' => null])->save();

        $this->supprimer($brouillon);

        $this->assertSame([0, 0, [$vendu->id]], [InventaireOuverture::query()->count(), LigneInventaireOuverture::query()->count(), Produit::query()->pluck('id')->all()]);
    }

    /**
     * Seul un produit jamais reçu entre dans un inventaire d'ouverture : un
     * produit déjà reçu par un achat est refusé à la saisie, et un produit
     * reçu entre la saisie et la validation fait refuser toute la
     * validation, sans créer les nouveaux produits des autres lignes.
     */
    public function test_un_produit_deja_recu_n_est_pas_inventorie_de_nouveau(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $fournisseur = Fournisseur::factory()->create();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20]);
        $tasse = Produit::factory()->create(['nom' => 'Tasse', 'taux_tva' => 20]);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $cable->id, 'quantite' => 2, 'prix_unitaire_ht' => 1]]));

        $this->assertThrows(
            fn () => $this->saisir([['produit_id' => $cable->id, 'quantite' => 1, 'cout_unitaire_ht' => '1.00']]),
            LogicException::class,
            'Le produit « Câble USB-C » a déjà été reçu : seul un produit jamais reçu entre dans un inventaire d\'ouverture.',
        );

        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet(), ['produit_id' => $tasse->id, 'quantite' => 3, 'cout_unitaire_ht' => '2.00']]);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $tasse->id, 'quantite' => 5, 'prix_unitaire_ht' => 2]]));

        $this->assertThrows(
            fn () => $this->valider($brouillon),
            LogicException::class,
            'Le produit « Tasse » a déjà été reçu : seul un produit jamais reçu entre dans un inventaire d\'ouverture.',
        );
        $this->assertSame([StatutInventaireOuverture::Brouillon, 0, 5], [$brouillon->fresh()->statut, Produit::query()->where('nom', 'Carnet A5')->count(), $tasse->fresh()->stock_quantite]);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function lignesRefusees(): array
    {
        return [
            'coût négatif' => [
                [['produit' => ['nom' => 'Carnet A5', 'prix_vente_ttc' => '4.90'], 'quantite' => 1, 'cout_unitaire_ht' => '-0.01']],
                'Le coût unitaire d\'un produit ne peut pas être négatif.',
            ],
            'nouveau produit sans prix de vente' => [
                [['produit' => ['nom' => 'Carnet A5'], 'quantite' => 1, 'cout_unitaire_ht' => '1.00']],
                'Indiquez le prix de vente TTC du nouveau produit « Carnet A5 ».',
            ],
            'deux nouveaux produits de même référence, sans tenir compte de la casse' => [
                [
                    ['produit' => ['nom' => 'Carnet A5', 'reference' => 'ABC', 'prix_vente_ttc' => '4.90'], 'quantite' => 1, 'cout_unitaire_ht' => '1.00'],
                    ['produit' => ['nom' => 'Carnet A4', 'reference' => 'abc', 'prix_vente_ttc' => '5.90'], 'quantite' => 1, 'cout_unitaire_ht' => '1.00'],
                ],
                'Deux nouveaux produits de cet inventaire d\'ouverture ont la même référence « abc ».',
            ],
            'quantité nulle' => [
                [['produit' => ['nom' => 'Carnet A5', 'prix_vente_ttc' => '4.90'], 'quantite' => 0, 'cout_unitaire_ht' => '1.00']],
                'La quantité doit être un nombre entier d\'articles, au moins 1.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    #[DataProvider('lignesRefusees')]
    public function test_une_ligne_invalide_est_refusee_et_rien_n_est_enregistre(array $lignes, string $message): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->assertThrows(fn () => $this->saisir($lignes), InvalidArgumentException::class, $message);

        $this->assertSame(0, InventaireOuverture::query()->count());
    }

    /**
     * Un même produit ne figure que sur une ligne, et la référence d'un
     * nouveau produit n'est jamais celle d'un produit du magasin.
     */
    public function test_un_produit_ou_une_reference_deja_pris_est_refuse(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $tasse = Produit::factory()->create(['nom' => 'Tasse', 'reference' => 'TASSE']);

        $this->assertThrows(
            fn () => $this->saisir([['produit_id' => $tasse->id, 'quantite' => 1, 'cout_unitaire_ht' => '1.00'], ['produit_id' => $tasse->id, 'quantite' => 2, 'cout_unitaire_ht' => '1.00']]),
            LogicException::class,
            'Le produit « Tasse » figure déjà sur une autre ligne de cet inventaire d\'ouverture.',
        );
        $this->assertThrows(
            fn () => $this->saisir([['produit' => ['nom' => 'Autre tasse', 'reference' => 'tasse', 'prix_vente_ttc' => '9.90'], 'quantite' => 1, 'cout_unitaire_ht' => '1.00']]),
            InvalidArgumentException::class,
            'La référence « tasse » est déjà celle d\'un autre produit.',
        );
        $this->assertSame(0, InventaireOuverture::query()->count());
    }

    /**
     * Répétée après une réponse perdue, avec le même jeton, la validation
     * rend l'inventaire déjà validé : ses produits ne naissent et son stock
     * n'entre qu'une fois.
     */
    public function test_une_validation_repetee_avec_le_meme_jeton_rend_l_inventaire_deja_valide(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->saisir([self::ligneDUnNouveauCarnet()]);
        $jeton = (string) Str::uuid();

        $premiere = $this->valider($brouillon, null, $jeton);
        $seconde = $this->valider($brouillon, null, $jeton);

        $this->assertSame([$premiere->id, $premiere->numero], [$seconde->id, $seconde->numero]);
        $this->assertSame([1, 10, 1], [Produit::query()->count(), Produit::query()->sole()->stock_quantite, MouvementStock::query()->count()]);
    }

    /**
     * Une réception corrigée rejoue le grand livre du produit : l'entrée de
     * l'inventaire d'ouverture y entre à son coût saisi. 10 inventoriés à
     * 2,00 €, puis 10 reçus à 4,00 € : coût moyen 3,00 € ; la réception
     * corrigée à 6,00 € : (10 x 2,00 + 10 x 6,00) / 20 = 4,00 €.
     */
    public function test_une_reception_corrigee_rejoue_l_entree_de_l_inventaire_a_son_cout(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->valider($this->saisir([self::ligneDUnNouveauCarnet()]));
        $carnet = Produit::query()->sole();
        $fournisseur = Fournisseur::factory()->create();
        $achat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $carnet->id, 'quantite' => 10, 'prix_unitaire_ht' => 4]]));
        $this->assertSame('3.0000', $carnet->fresh()->cmup_ht);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat, $fournisseur, [['produit_id' => $carnet->id, 'quantite' => 10, 'prix_unitaire_ht' => 6]]);

        $this->assertSame(['4.0000', 20], [$carnet->fresh()->cmup_ht, $carnet->fresh()->stock_quantite]);
    }
}
