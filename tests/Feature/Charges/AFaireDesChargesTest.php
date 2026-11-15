<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Models\Charge;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Pages\ChargesDuMagasin;
use App\Filament\App\Pages\TableauDeBordDeLaSociete;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\Charges;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ce que les charges de la société laissent à faire (décisions de
 * l'éditeur du 14/11/2026, lot CHARGES, voir ChargesAFaire) : « N charges
 * à valider » pour qui les valide (le propriétaire sans comptable, le
 * cabinet lié par la colonne « Charges » de son tableau de bord,
 * l'expert-comptable déclaré sur sa page), « N charges renvoyées par votre
 * comptable » pour qui les ajoute sans les valider (le propriétaire,
 * l'administrateur et le responsable cochés), chacun seulement parmi les
 * charges qu'il voit.
 */
class AFaireDesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $paris;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->paris = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Paris']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        app(TenantContext::class)->clear();
    }

    public function test_sans_comptable_le_proprietaire_a_ses_charges_a_valider_menant_a_la_liste_filtree(): void
    {
        $this->deposer($this->proprietaire, $this->societe);
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2', 'magasin' => (string) $this->paris->uuid]));

        $chose = $this->choseDeLaSociete($this->proprietaire, 'charges_a_valider');

        $this->assertNotNull($chose);
        $this->assertSame([2, UrgenceAFaire::Orange, 'Valider'], [$chose->nombre, $chose->urgence, $chose->bouton]);
        $this->assertStringStartsWith('2 charges à valider', $chose->phrase);
        $this->assertSame(Charges::getUrl(['vue' => 'a_valider'], panel: 'societe', tenant: $this->societe), $chose->url);
        $this->assertNull($this->choseDeLaSociete($this->proprietaire, 'charges_renvoyees'));
    }

    public function test_avec_un_cabinet_lie_le_proprietaire_et_l_administrateur_coche_n_ont_que_les_charges_renvoyees(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $administrateur = $this->choisirSonMotDePasse($this->administrateur($this->lyon, [$this->lyon->id], [], [PermissionRegistry::CHARGES_AJOUTER]));
        $this->deposer($this->proprietaire, $this->societe);
        $aRenvoyer = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2']));

        $this->assertNull($this->choseDeLaSociete($this->proprietaire, 'charges_a_valider'));
        $this->assertNull($this->choseDeLaSociete($this->proprietaire, 'charges_renvoyees'));

        $this->renvoyer($collaborateur, $aRenvoyer);

        foreach ([$this->proprietaire, $administrateur] as $compte) {
            $chose = $this->choseDeLaSociete($compte, 'charges_renvoyees');
            $this->assertNotNull($chose, $compte->name);
            $this->assertSame([1, UrgenceAFaire::Rouge, 'Corriger'], [$chose->nombre, $chose->urgence, $chose->bouton]);
            $this->assertStringStartsWith('1 charge renvoyée par votre comptable', $chose->phrase);
            $this->assertSame(Charges::getUrl(['vue' => 'renvoyees'], panel: 'societe', tenant: $this->societe), $chose->url);
            $this->assertNull($this->choseDeLaSociete($compte, 'charges_a_valider'), $compte->name);
        }
        $this->assertSame([], app(ChosesAFaireDeLaSociete::class)->delaSociete($collaborateur, $this->societe));
    }

    public function test_le_responsable_coche_n_a_que_les_charges_renvoyees_de_son_magasin_et_le_responsable_non_coche_rien(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $coche = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]);
        $nonCoche = $this->responsable($this->lyon);
        $deLyon = $this->deposer($coche, $this->societe, $this->saisie(champs: ['magasin' => (string) $this->lyon->uuid]));
        $deParis = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'P-1', 'magasin' => (string) $this->paris->uuid]));
        $deLaSociete = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'S-1']));
        foreach ([$deLyon, $deParis, $deLaSociete] as $charge) {
            $this->renvoyer($collaborateur, $charge);
        }

        $this->dansLeMagasin($this->lyon, $coche);
        $chose = $this->choseDuMagasin($coche, 'charges_renvoyees');

        $this->assertNotNull($chose);
        $this->assertSame([1, UrgenceAFaire::Rouge], [$chose->nombre, $chose->urgence]);
        $this->assertSame(ChargesDuMagasin::getUrl(['vue' => 'renvoyees'], panel: 'app', tenant: $this->lyon), $chose->url);
        $this->assertNull($this->choseDuMagasin($coche, 'charges_a_valider'));

        $this->dansLeMagasin($this->lyon, $nonCoche);
        $this->assertNull($this->choseDuMagasin($nonCoche, 'charges_renvoyees'));
    }

    public function test_la_colonne_charges_du_tableau_de_bord_du_cabinet_ne_compte_que_les_clients_qui_les_lui_accordent(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $cabinet = $collaborateur->societe()->firstOrFail();
        $sansLesCharges = $this->ouvrirUneSociete(['nom' => 'Sans', 'raison_sociale' => 'CLIENTE SANS CHARGES']);
        $this->lierLeCabinet($cabinet, $sansLesCharges, array_values(array_diff(PermissionRegistry::parDefautDuCabinet($sansLesCharges->societe->type_activite), [PermissionRegistry::CHARGES_VALIDER])));
        $this->deposer($this->proprietaire, $this->societe);
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'L-1', 'magasin' => (string) $this->lyon->uuid]));
        $this->renvoyer($collaborateur, $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'R-1'])));
        $this->deposer($this->proprietaireDe($sansLesCharges), $sansLesCharges->societe);

        $lignes = collect(app(TableauDeBordDesClients::class)->pour($this->proprietaireDuCabinet($cabinet)))->keyBy(fn (array $ligne): int => (int) $ligne['client']->id);

        $this->assertSame(2, $lignes[$this->societe->getKey()]['charges_a_valider']);
        $this->assertSame(0, $lignes[$sansLesCharges->societe_id]['charges_a_valider']);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(TableauDeBordDuCabinet::class)
            ->assertSeeHtml('data-charges-a-valider="2"')
            ->assertSeeHtml('data-charges-a-valider="0"')
            ->assertSeeText('2 charges à valider');
    }

    public function test_l_expert_comptable_declare_voit_ses_charges_a_valider_sur_sa_page(): void
    {
        $expert = $this->expertComptable($this->lyon);
        $this->deposer($this->proprietaire, $this->societe);
        $this->dansLeMagasin($this->lyon, $expert);

        $this->get(TableauDeBordDeLaSociete::getUrl(panel: 'app', tenant: $this->lyon))
            ->assertOk()
            ->assertSee('data-charges-a-valider="1"', false)
            ->assertSeeText('1 charge à valider');
        $this->assertNull($this->choseDuMagasin($expert, 'charges_a_valider'));
    }

    private function renvoyer(User $comptable, Charge $charge): void
    {
        app(TenantContext::class)->clear();
        app(RenvoyerUneChargeAction::class)->handle($comptable, $this->societe, (string) $charge->uuid, 'Pièce illisible');
        app(TenantContext::class)->clear();
    }

    private function choseDeLaSociete(User $compte, string $cle): ?ChoseAFaire
    {
        $compte = $compte->fresh() ?? $compte;
        $this->dansLEspaceDeLaSociete($this->societe, $compte);

        return collect(app(ChosesAFaireDeLaSociete::class)->delaSociete($compte, $this->societe))->first(fn (ChoseAFaire $chose): bool => $chose->cle === $cle);
    }

    private function choseDuMagasin(User $compte, string $cle): ?ChoseAFaire
    {
        $choses = ChosesAFaireDuMagasin::pour($compte->fresh() ?? $compte, $this->lyon);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->first(fn (ChoseAFaire $chose): bool => $chose->cle === $cle);
    }
}
