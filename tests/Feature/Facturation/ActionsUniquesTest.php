<?php

namespace Tests\Feature\Facturation;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\ActionTraitee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le jeton unique des demandes (R3) : une demande répétée sous le même
 * jeton, après une réponse perdue, rend le résultat déjà enregistré sans
 * rien refaire ; le même jeton pour une autre demande est refusé ; seule
 * l'action la plus externe le consomme ; une action qui échoue ne
 * l'inscrit pas ; il vaut dans son magasin et s'efface après 30 jours.
 * Deux demandes simultanées du même jeton sont dans
 * ActionsUniquesConcurrenceTest.
 */
class ActionsUniquesTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_emission_repetee_avec_le_meme_jeton_renvoie_la_meme_facture_sans_second_numero(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->brouillon();
        $jeton = (string) Str::uuid();

        $premiere = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon, jeton: $jeton);
        $repetee = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon, jeton: $jeton);

        $this->assertTrue($repetee->is($premiere));
        $this->assertSame($premiere->numero, $repetee->numero);
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count());
        $this->assertSame([[ActionsUniques::EMETTRE_FACTURE, 'facture', $premiere->id]], $this->jetonsInscrits());
    }

    public function test_un_paiement_repete_n_est_enregistre_qu_une_fois(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon());
        $jeton = (string) Str::uuid();

        $premier = $this->payer($facture, '12.00', $jeton);
        $repete = $this->payer($facture, '12.00', $jeton);

        $this->assertTrue($repete->is($premier));
        $this->assertSame(1, Paiement::query()->count());
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->count());
    }

    public function test_un_avoir_repete_n_est_emis_qu_une_fois(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 5]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client = Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 2]]));
        $retour = [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]];
        $jeton = (string) Str::uuid();

        $premier = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, $retour, 'Retour', $jeton);
        $repete = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, $retour, 'Retour', $jeton);

        $this->assertTrue($repete->is($premier));
        $this->assertSame(1, Avoir::query()->count());
        $this->assertSame([4, 1], [$cable->fresh()->stock_quantite, MouvementStock::query()->where('type', 'retour_client')->count()]);
    }

    public function test_un_envoi_repete_ne_part_qu_une_fois(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon());
        $jeton = (string) Str::uuid();
        Queue::fake([EnvoyerDocumentJob::class]);

        $premier = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'client@exemple.fr', null, $jeton);
        $repete = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'client@exemple.fr', null, $jeton);

        $this->assertTrue($repete->is($premier));
        $this->assertSame(1, EnvoiDocument::query()->count());
        Queue::assertPushed(EnvoyerDocumentJob::class, 1);
    }

    public function test_une_vente_a_la_caisse_repetee_ne_cree_qu_un_ticket_et_un_paiement(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $jeton = (string) Str::uuid();

        $premiere = $this->vendreAuComptoir(self::auteur(), $jeton);
        $repetee = $this->vendreAuComptoir(self::auteur(), $jeton);

        $this->assertTrue($repetee->is($premiere));
        $this->assertSame([1, 1], [Facture::query()->count(), Paiement::query()->count()]);
    }

    /**
     * L'encaissement a été fait, mais sa réponse s'est perdue : la caisse,
     * restée telle qu'elle était, renvoie la même vente sous le même
     * jeton, et retrouve le ticket déjà enregistré ; rechargée, elle
     * s'ouvre vide et rappelle la dernière vente enregistrée.
     */
    public function test_recharger_la_caisse_apres_une_reponse_perdue_ne_cree_pas_un_second_ticket(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $caisse = Livewire::test(Caisse::class)->fillForm([
            'client_id' => $comptoir->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1]],
            'mode_paiement' => 'especes',
        ]);
        $this->assertSame(PanierCaisse::query()->sole()->jeton, $caisse->get('jetonDeLaVente'));
        $ticket = self::vendreAuComptant($vendeur, $comptoir, $caisse->instance()->form->getState()['lignes'], ModePaiement::Especes, jeton: $caisse->get('jetonDeLaVente'));

        $caisse->call('encaisser')
            ->assertNotified("Vente encaissée : facture {$ticket->numero}")
            ->assertFormSet(['lignes' => []]);

        $this->assertSame([1, 1, 0], [Facture::query()->count(), Paiement::query()->count(), PanierCaisse::query()->count()]);
        Livewire::test(Caisse::class)
            ->assertFormSet(['lignes' => []])
            ->assertSee("Dernière vente enregistrée : ticket N° {$ticket->numero}");
    }

    public function test_le_meme_jeton_avec_un_autre_montant_est_refuse(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon());
        $jeton = (string) Str::uuid();
        $this->payer($facture, '5.00', $jeton);

        $this->assertThrows(fn () => $this->payer($facture, '7.00', $jeton), LogicException::class, ActionsUniques::REFUS);

        $this->assertSame(['5.00'], Paiement::query()->pluck('montant')->all());
    }

    public function test_une_vente_a_la_caisse_et_son_emission_n_utilisent_qu_un_jeton(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $ticket = $this->vendreAuComptoir(self::auteur(), (string) Str::uuid());

        $this->assertSame([[ActionsUniques::VENDRE_A_LA_CAISSE, 'facture', $ticket->id]], $this->jetonsInscrits());
    }

    public function test_un_jeton_reutilise_pour_une_autre_action_ou_par_un_autre_compte_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon());
        $jeton = (string) Str::uuid();
        $this->payer($facture, '5.00', $jeton);
        $responsable = $this->responsable($magasin);

        $this->assertThrows(fn () => app(EnregistrerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour', $jeton), LogicException::class, ActionsUniques::REFUS);
        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle($responsable, $facture->client, $this->paiementDe('5.00'), [['facture_id' => $facture->id, 'montant' => '5.00']], $jeton), LogicException::class, ActionsUniques::REFUS);

        $this->assertSame([1, 0], [Paiement::query()->count(), Avoir::query()->count()]);
    }

    public function test_une_action_echouee_n_inscrit_pas_son_jeton(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon());
        $jeton = (string) Str::uuid();

        $this->assertThrows(fn () => $this->payer($facture, '50.00', $jeton), LogicException::class);
        $this->assertSame(0, ActionTraitee::query()->count());

        $paiement = $this->payer($facture, '12.00', $jeton);

        $this->assertSame([[ActionsUniques::ENREGISTRER_PAIEMENT, 'paiement', $paiement->id]], $this->jetonsInscrits());
    }

    public function test_les_jetons_sont_isoles_par_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $jeton = (string) Str::uuid();
        $dansA = $this->vendreAuComptoir(self::auteur(), $jeton);
        app(FermerSaCaisseAction::class)->handle(self::auteur(), self::auteur());
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);

        $dansB = $this->vendreAuComptoir(self::auteur(), $jeton);

        $this->assertFalse($dansB->is($dansA));
        $this->assertSame([(int) $autre->id], [(int) $dansB->magasin_id]);
        $this->assertSame([[ActionsUniques::VENDRE_A_LA_CAISSE, 'facture', $dansB->id]], $this->jetonsInscrits());
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertSame([[ActionsUniques::VENDRE_A_LA_CAISSE, 'facture', $dansA->id]], $this->jetonsInscrits());
    }

    public function test_les_jetons_de_plus_de_30_jours_sont_effaces(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ancienDeA = $this->vendreAuComptoir(self::auteur(), (string) Str::uuid());
        app(FermerSaCaisseAction::class)->handle(self::auteur(), self::auteur());
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $ancienDeB = $this->vendreAuComptoir(self::auteur(), (string) Str::uuid());
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $recentDeB = $this->vendreAuComptoir(self::auteur(), (string) Str::uuid());
        $this->travelTo(self::aParis('2026-11-01 10:00:01'));
        app(TenantContext::class)->clear();

        $this->artisan('app:purger-actions-traitees')->assertSuccessful();

        app(TenantContext::class)->set($autre->fresh());
        $this->assertSame([[ActionsUniques::VENDRE_A_LA_CAISSE, 'facture', $recentDeB->id]], $this->jetonsInscrits());
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertSame([], $this->jetonsInscrits());
        $this->assertSame(3, Facture::query()->withoutGlobalScopes()->whereKey([$ancienDeA->id, $ancienDeB->id, $recentDeB->id])->count());
    }

    public function test_une_cloture_manuelle_repetee_ne_cree_qu_une_cloture(): void
    {
        $this->travelTo(self::aParis('2026-10-09 18:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendreAuComptoir(self::auteur(), null);
        $jeton = (string) Str::uuid();

        $premiere = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), $jeton);
        $repetee = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), $jeton);

        $this->assertSame(1, ClotureCaisse::query()->count());
        $this->assertSame([$premiere['clotures']->sole()->id], $repetee['clotures']->map(fn (ClotureCaisse $cloture): int => $cloture->id)->all());
        $this->assertTrue($repetee['caisses_fermees']->isEmpty());
    }

    public function test_valider_deux_fois_une_facture_d_achat_n_entre_le_stock_qu_une_fois(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);
        $achat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 5, 'prix_unitaire_ht' => '4.00', 'taux_tva' => 20]]);
        $jeton = (string) Str::uuid();

        $premiere = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $achat, $jeton);
        $repetee = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $achat, $jeton);

        $this->assertTrue($repetee->is($premiere));
        $this->assertSame([5, 1], [$cable->fresh()->stock_quantite, MouvementStock::query()->where('type', 'reception_achat')->count()]);
    }

    /**
     * Les gestes qui ne portent pas de jeton se protègent par leur état :
     * un magasin déjà fermé le dit, sans rien refaire.
     */
    public function test_fermer_deux_fois_un_magasin_dit_deja_fait_sans_rien_refaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        app(FermerMagasinAction::class)->handle($proprietaire, $magasin->fresh(), MotifFermeture::Temporaire, 'password');

        $this->assertThrows(fn () => app(FermerMagasinAction::class)->handle($proprietaire, $magasin->fresh(), MotifFermeture::Temporaire, 'password'), LogicException::class, FermerMagasinAction::MESSAGE_DEJA_FERME);

        $this->assertSame(1, $magasin->fresh()->periodes()->whereNotNull('fin')->count());
    }

    /**
     * Un ticket de 12,00 € TTC au client particulier, en brouillon.
     */
    private function brouillon(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
    }

    /**
     * Un câble de 12,00 € TTC du magasin actif, vendu au comptoir.
     */
    private function vendreAuComptoir(User $vendeur, ?string $jeton): Facture
    {
        $cable = Produit::query()->firstWhere('reference', 'CABLE-USB')
            ?? Produit::factory()->create(['nom' => 'Câble USB', 'reference' => 'CABLE-USB', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant($vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $cable->id, 'quantite' => 1]], ModePaiement::Especes, jeton: $jeton);
    }

    private function payer(Facture $facture, string $montant, string $jeton): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, $this->paiementDe($montant), [['facture_id' => $facture->id, 'montant' => $montant]], $jeton);
    }

    /**
     * @return array{mode_paiement: string, montant: string, recu_le: string}
     */
    private function paiementDe(string $montant): array
    {
        return ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => Calendrier::deParis()->aujourdhui()];
    }

    /**
     * Les jetons inscrits dans le magasin actif : action, résultat.
     *
     * @return list<array{0: string, 1: string|null, 2: int|null}>
     */
    private function jetonsInscrits(): array
    {
        return ActionTraitee::query()->orderBy('id')->get()->map(fn (ActionTraitee $traitee): array => [$traitee->action, $traitee->resultat_type, $traitee->resultat_id])->all();
    }
}
