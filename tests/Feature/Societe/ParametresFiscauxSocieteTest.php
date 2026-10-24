<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Les réglages fiscaux de la société (voir ParametresFiscauxSociete et
 * ModifierParametresFiscauxSocieteAction) : un seul régime de TVA, une
 * seule option pour les débits, un seul mois de fin d'exercice, pour tous
 * ses magasins ; chaque changement s'inscrit au journal des événements de
 * chacun (JET 270).
 */
class ParametresFiscauxSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire règle le régime de la société depuis son
     * administration : tous ses magasins vendent aussitôt selon lui, par
     * leur équipe, la franchise en base à 0 % avec sa mention.
     */
    public function test_le_regime_de_la_societe_s_applique_a_tous_ses_magasins(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirLAdministration($lyon->societe);

        Livewire::test(ParametresFiscauxSociete::class)
            ->fillForm(['regime_tva' => RegimeTva::FranchiseEnBase->value, 'option_debits_tva' => false, 'mois_fin_exercice' => 12])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Paramètres fiscaux enregistrés');

        $this->assertSame(RegimeTva::FranchiseEnBase, $lyon->societe->fresh()->regime_tva);
        foreach ([$lyon, $villeurbanne] as $magasin) {
            $ticket = app(TenantContext::class)->runAs($magasin->fresh(), fn (): Facture => self::vendreAuComptant(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes));
            $this->assertSame([true, '0.00', '12.00'], [$ticket->franchise_en_base, $ticket->total_tva, $ticket->total_ttc]);
        }
    }

    /**
     * Le régime et l'option touchent la conformité de chaque caisse : leur
     * changement s'inscrit au journal des événements de chaque magasin de
     * la société, celui d'un magasin fermé compris, avec l'ancienne et la
     * nouvelle valeur.
     */
    public function test_un_changement_de_regime_s_inscrit_au_journal_de_chaque_magasin_avec_l_ancienne_et_la_nouvelle_valeur(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $ferme = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Bron']);
        $ferme->forceFill(['statut' => StatutMagasin::Ferme])->save();
        $this->ouvrirLAdministration($lyon->societe);

        Livewire::test(ParametresFiscauxSociete::class)
            ->fillForm(['regime_tva' => RegimeTva::ReelNormalMensuel->value, 'option_debits_tva' => true, 'mois_fin_exercice' => 12])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach ([$lyon, $villeurbanne, $ferme] as $magasin) {
            $this->assertSame([
                ['parametre' => 'regime_tva', 'ancienne_valeur' => 'reel_simplifie', 'nouvelle_valeur' => 'reel_normal_mensuel'],
                ['parametre' => 'option_debits_tva', 'ancienne_valeur' => false, 'nouvelle_valeur' => true],
            ], $this->parametresJournalises($magasin));
        }
    }

    public function test_un_parametre_inchange_n_est_pas_journalise(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $this->ouvrirLAdministration($lyon->societe);

        Livewire::test(ParametresFiscauxSociete::class)
            ->fillForm(['regime_tva' => RegimeTva::ReelSimplifie->value, 'option_debits_tva' => true, 'mois_fin_exercice' => 12])
            ->call('save');

        $this->assertSame([
            ['parametre' => 'option_debits_tva', 'ancienne_valeur' => false, 'nouvelle_valeur' => true],
        ], $this->parametresJournalises($lyon));
    }

    /**
     * Fermé par défaut : sans clé de signature, le changement ne peut
     * s'inscrire au journal d'aucun magasin, donc ne s'enregistre pas, et
     * l'écran dit pourquoi.
     */
    public function test_sans_cle_de_signature_rien_n_est_enregistre_dans_aucun_magasin(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirLAdministration($lyon->societe);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(ParametresFiscauxSociete::class)
            ->fillForm(['regime_tva' => RegimeTva::ReelNormalMensuel->value, 'option_debits_tva' => false, 'mois_fin_exercice' => 12])
            ->call('save')
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertSame(RegimeTva::ReelSimplifie, $lyon->societe->fresh()->regime_tva);
        $this->assertSame([[], []], [$this->parametresJournalises($lyon), $this->parametresJournalises($villeurbanne)]);
    }

    /**
     * Le mois de fin d'exercice se règle tant qu'aucun magasin de la
     * société n'a vendu ; dès la première vente de l'un d'eux, il se
     * verrouille, à l'écran comme dans le domaine.
     */
    public function test_le_mois_de_fin_d_exercice_se_verrouille_des_la_premiere_vente_d_un_magasin(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $proprietaire = $this->proprietaireDe($lyon);
        app(TenantContext::class)->clear();
        app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $lyon->societe, ['mois_fin_exercice' => 6]);
        app(TenantContext::class)->runAs($villeurbanne, fn (): Facture => self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes));
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $lyon->societe->fresh(), ['mois_fin_exercice' => 3]),
            LogicException::class,
            'Le mois de fin d\'exercice est verrouillé depuis la première vente de l\'un de vos magasins',
        );

        $this->assertSame(6, $lyon->societe->fresh()->mois_fin_exercice);
        $this->ouvrirLAdministration($lyon->societe);
        Livewire::test(ParametresFiscauxSociete::class)
            ->assertFormFieldIsDisabled('mois_fin_exercice')
            ->assertSee('Verrouillé depuis la première vente de l\'un de vos magasins.');
    }

    /**
     * Une vente lit le régime de la société une fois le registre de son
     * magasin verrouillé, celui sous lequel le régime change : le passage
     * en franchise validé pendant qu'elle attendait le verrou est vu, et le
     * brouillon enregistré au réel ne s'émet pas.
     */
    public function test_une_emission_lit_le_regime_de_la_societe_apres_son_verrou(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $regimeChange = false;
        DB::listen(function (QueryExecuted $requete) use (&$regimeChange, $magasin): void {
            if (! $regimeChange && str_contains($requete->sql, 'from "tetes_registre_fiscal"')) {
                $regimeChange = true;
                DB::table('societes')->where('id', $magasin->societe_id)->update(['regime_tva' => RegimeTva::FranchiseEnBase->value]);
            }
        });

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            LogicException::class,
            'Le régime de TVA de la société a changé depuis l\'enregistrement de ce brouillon',
        );

        $this->assertTrue($regimeChange);
        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
    }

    /**
     * Le code postal du magasin donne son territoire de TVA : à la
     * Martinique, une ligne de service propose 8,5 % (B2), quel que soit
     * le siège de la société.
     */
    public function test_un_magasin_de_la_martinique_propose_8_5_pour_cent_sur_un_service(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $nic = substr(IdentifiantsInsee::avecSaCle($lyon->societe->siren.'0002'), 9);
        app(RepertoireSirene::class)->deplacer($lyon->societe->siren.$nic, '97200', 'FORT-DE-FRANCE');
        $martinique = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Fort-de-France', 'nic' => $nic]);
        $service = Service::factory()->create(['nom' => 'Pose écran']);
        $this->actingAs($this->responsableDeTest($martinique));
        Filament::setTenant($martinique);

        $component = Livewire::test(CreateFacture::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]]);

        $this->assertSame('97200', $martinique->code_postal);
        $this->assertSame('8.5', collect($component->get('data.lignes'))->firstWhere('service_id', $service->id)['taux_tva']);
    }

    /**
     * La nature d'une opération se choisit selon le régime de la société :
     * en franchise en base, aucun de ses magasins ne vend hors de France,
     * sa franchise couvrant aussi ces ventes.
     */
    public function test_la_nature_d_operation_lit_la_franchise_de_la_societe(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->reglerLaSociete($villeurbanne, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], [
                'nature_operation' => NatureOperation::ExportHorsUe,
                'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
            ]),
            LogicException::class,
            'votre société est en franchise en base de TVA, qui couvre aussi ses ventes hors de France',
        );

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * L'administration de la société, ouverte par son propriétaire : la
     * société est la société active, comme le pose le panneau à chaque
     * requête (voir BindTenantContextFromFilamentTenant).
     */
    private function ouvrirLAdministration(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        $this->actingAs($societe->proprietaire()->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);
    }

    /**
     * Les paramètres de conformité journalisés dans le magasin (JET 270),
     * dans l'ordre de sa chaîne.
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
