<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Une société membre d'un assujetti unique (groupe TVA, art. 256 C du
 * CGI ; décision P3.1 de l'onglet « À faire vérifier par un
 * professionnel ») : la case de ses réglages fiscaux communs s'inscrit au
 * journal d'audit et au journal des événements de chaque magasin, comme
 * tout réglage qui touche la conformité ; cochée, PILIQI refuse toute
 * opération commerciale réelle (ticket, facture, avoir, encaissement,
 * devis numéroté) avec son message, le magasin de démonstration restant
 * utilisable ; la page d'inscription le dit. Le temps est figé au 5
 * novembre 2026, à Paris.
 */
class AssujettiUniqueTest extends TestCase
{
    use RefreshDatabase;

    private const REFUS = 'PILIQI ne gère pas encore les sociétés membres d\'un assujetti unique (groupe TVA) : aucune vente, facture, avoir, encaissement ni devis numéroté réel n\'est possible.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
    }

    /**
     * Le propriétaire coche la case dans « Paramètres fiscaux » de
     * l'espace de la société : le changement s'inscrit au journal des
     * événements de chaque magasin réel, avec l'ancienne et la nouvelle
     * valeur, et au journal d'audit ; décochée, de même.
     */
    public function test_la_case_s_inscrit_au_journal_d_audit_et_au_journal_des_evenements_de_chaque_magasin(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirLAdministration($lyon->societe);

        Livewire::test(ParametresFiscauxSociete::class)
            ->assertSee('La société est membre d\'un assujetti unique (groupe TVA, art. 256 C du CGI)')
            ->fillForm(['membre_assujetti_unique' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        Livewire::test(ParametresFiscauxSociete::class)
            ->assertFormSet(['membre_assujetti_unique' => true])
            ->fillForm(['membre_assujetti_unique' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($lyon->societe->fresh()->membre_assujetti_unique);
        foreach ([$lyon, $villeurbanne] as $magasin) {
            $this->assertSame([
                ['parametre' => 'membre_assujetti_unique', 'ancienne_valeur' => false, 'nouvelle_valeur' => true],
                ['parametre' => 'membre_assujetti_unique', 'ancienne_valeur' => true, 'nouvelle_valeur' => false],
            ], $this->parametresJournalises($magasin));
        }
        $this->assertSame(
            [[['membre_assujetti_unique' => false], ['membre_assujetti_unique' => true]], [['membre_assujetti_unique' => true], ['membre_assujetti_unique' => false]]],
            array_map(fn ($entree): array => [$entree->anciennes_valeurs, $entree->nouvelles_valeurs], $this->entreesDAudit($lyon->societe, ActionAuditee::ParametresFiscauxModifies)),
        );
    }

    /**
     * Case cochée, chaque opération commerciale réelle est refusée avec le
     * message, sans rien écrire au registre : un ticket, une facture, un
     * avoir de la facture émise avant, son encaissement, un devis
     * numéroté ; décochée, la vente reprend.
     */
    public function test_case_cochee_chaque_operation_commerciale_reelle_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $client = Client::factory()->create();
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre($client, $cable);
        $this->cocher($magasin, true);
        $enregistrements = EnregistrementFiscal::query()->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->count();

        $this->assertThrows(fn () => self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => $this->emettre($client, $cable), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour'), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => 12, 'recu_le' => '2026-11-05'], [['facture_id' => $facture->id, 'montant' => 12]]), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]], ['date_validite' => '2026-12-05'])), VenteNonAutoriseeException::class, self::REFUS);

        $this->assertSame($enregistrements, EnregistrementFiscal::query()->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->count());
        $this->assertSame([1, 0, 0], [Facture::query()->whereNotNull('numero')->count(), Avoir::query()->count(), Paiement::query()->count()]);
        $this->assertSame(self::REFUS, $magasin->fresh()->refusDeVente());
    }

    /**
     * Case décochée, la vente se fait comme d'habitude.
     */
    public function test_case_decochee_la_vente_se_fait(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->cocher($magasin, true);
        $this->cocher($magasin, false);

        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);

        $this->assertNotNull($ticket->numero);
        $this->assertTrue($magasin->fresh()->peutVendre());
    }

    /**
     * Le magasin de démonstration d'une société qui a coché la case reste
     * utilisable : on s'y entraîne à vendre.
     */
    public function test_le_magasin_de_demonstration_reste_utilisable(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        app(TenantContext::class)->clear();
        app(ModifierParametresFiscauxSocieteAction::class)->handle($societe->proprietaire, $societe->fresh(), ['membre_assujetti_unique' => true]);
        app(TenantContext::class)->set($demonstration);

        $ticket = self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]]);

        $this->assertTrue($societe->fresh()->membre_assujetti_unique);
        $this->assertNotNull($ticket->numero);
    }

    /**
     * Case cochée, les gestes de vente ne se montrent plus : l'« À faire »
     * de chaque compte du magasin dit pourquoi, en rouge ; le propriétaire
     * l'a à faire, avec le lien vers « Paramètres fiscaux », le vendeur à
     * surveiller, sans lien. Décochée, la ligne disparaît.
     */
    public function test_l_a_faire_du_magasin_dit_pourquoi_rien_ne_se_vend(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->cocher($magasin, true);

        $pourLeProprietaire = $this->chose($proprietaire, $magasin);
        $this->assertSame([self::REFUS, UrgenceAFaire::Rouge, false, ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $magasin->societe)], [$pourLeProprietaire?->phrase, $pourLeProprietaire?->urgence, $pourLeProprietaire?->aSurveiller, $pourLeProprietaire?->url]);
        $pourLeVendeur = $this->chose($vendeur, $magasin);
        $this->assertSame([self::REFUS, true, null], [$pourLeVendeur?->phrase, $pourLeVendeur?->aSurveiller, $pourLeVendeur?->url]);

        $this->cocher($magasin, false);
        $this->assertNull($this->chose($vendeur, $magasin));
    }

    /**
     * La page d'inscription dit qu'une société membre d'un assujetti
     * unique ne vend pas encore dans PILIQI, sans champ à remplir.
     */
    public function test_la_page_d_inscription_le_dit_sans_champ(): void
    {
        $page = $this->get(route('filament.app.auth.register'));

        $page->assertOk()->assertSee(InscriptionSociete::INFORMATION_ASSUJETTI_UNIQUE)->assertDontSee('membre_assujetti_unique');
        $this->assertSame('Une société membre d\'un assujetti unique (groupe TVA, art. 256 C du CGI) ne peut pas encore vendre dans PILIQI : elle peut s\'inscrire et essayer le magasin de démonstration, mais aucune vente, facture, avoir, encaissement ni devis numéroté réel n\'y sera possible.', InscriptionSociete::INFORMATION_ASSUJETTI_UNIQUE);
    }

    private function chose(User $compte, Magasin $magasin): ?ChoseAFaire
    {
        $this->dansLeMagasin($magasin->fresh(), $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin->fresh());

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', 'assujetti_unique');
    }

    private function ouvrirLAdministration(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        $this->actingAs($societe->proprietaire()->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);
    }

    private function emettre(Client $client, Produit $produit): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function cocher(Magasin $magasin, bool $membre): void
    {
        app(TenantContext::class)->runAsSociete($magasin->societe, fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe->fresh(), ['membre_assujetti_unique' => $membre]));
        $magasin->unsetRelation('societe');
        app(TenantContext::class)->set($magasin->fresh());
    }

    /**
     * Les paramètres de conformité journalisés au registre du magasin (JET
     * 270), dans leur ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function parametresJournalises(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '270')
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->all());
    }
}
