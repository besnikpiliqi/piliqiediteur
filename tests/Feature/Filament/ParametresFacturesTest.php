<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\ParametresFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParametresFacturesTest extends TestCase
{
    use RefreshDatabase;

    private const PENALITES_PAR_DEFAUT = 'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.';

    /**
     * Chaque magasin démarre avec un délai de 30 jours et les mentions du
     * logiciel (B7, B9), qu'il règle ici.
     */
    public function test_le_proprietaire_regle_le_delai_et_les_mentions_de_ses_factures(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresFactures::class)
            ->assertFormSet([
                'delai_paiement_jours' => 30,
                'mention_penalites_retard' => self::PENALITES_PAR_DEFAUT,
                'mention_indemnite_recouvrement' => 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
                'mention_escompte' => 'Pas d\'escompte pour paiement anticipé.',
            ])
            ->fillForm([
                'delai_paiement_jours' => '45',
                'mention_penalites_retard' => 'Pénalités de retard : 10 % par an.',
                'mention_indemnite_recouvrement' => 'Indemnité forfaitaire de recouvrement : 40 €.',
                'mention_escompte' => 'Escompte de 1 % pour paiement sous 8 jours.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Paramètres des factures enregistrés');

        $this->assertSame(
            [45, 'Pénalités de retard : 10 % par an.', 'Indemnité forfaitaire de recouvrement : 40 €.', 'Escompte de 1 % pour paiement sous 8 jours.'],
            [$magasin->fresh()->delai_paiement_jours, $magasin->fresh()->mention_penalites_retard, $magasin->fresh()->mention_indemnite_recouvrement, $magasin->fresh()->mention_escompte],
        );
    }

    /**
     * @return array<string, array{string, string}> le délai saisi, et le message attendu
     */
    public static function delaisInvalides(): array
    {
        return [
            'négatif' => ['-1', 'Le délai ne peut pas être négatif.'],
            'plus d\'un an' => ['366', 'Au plus 365 jours.'],
            'pas un nombre entier' => ['1,5', 'Un nombre entier de jours.'],
            'vide' => ['', 'Indiquez le délai de paiement.'],
        ];
    }

    #[DataProvider('delaisInvalides')]
    public function test_un_delai_invalide_est_refuse(string $delai, string $message): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresFactures::class)
            ->fillForm(['delai_paiement_jours' => $delai])
            ->call('save')
            ->assertHasFormErrors(['delai_paiement_jours'])
            ->assertSee($message);

        $this->assertSame(30, $magasin->fresh()->delai_paiement_jours);
    }

    /**
     * Les trois mentions sont obligatoires sur une facture à un
     * professionnel : aucune ne s'enregistre vide.
     */
    public function test_une_mention_vide_est_refusee(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresFactures::class)
            ->fillForm(['mention_penalites_retard' => ''])
            ->call('save')
            ->assertHasFormErrors(['mention_penalites_retard'])
            ->assertSee('Cette mention est obligatoire sur une facture à un professionnel.');

        $this->assertSame(self::PENALITES_PAR_DEFAUT, $magasin->fresh()->mention_penalites_retard);
    }

    public function test_une_mention_de_plus_de_500_caracteres_est_refusee(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresFactures::class)
            ->fillForm(['mention_escompte' => str_repeat('a', 501)])
            ->call('save')
            ->assertHasFormErrors(['mention_escompte'])
            ->assertSee('Au plus 500 caractères.');

        $this->assertSame('Pas d\'escompte pour paiement anticipé.', $magasin->fresh()->mention_escompte);
    }

    public function test_le_proprietaire_ouvre_la_page_des_factures(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->get(ParametresFactures::getUrl(tenant: $magasin))
            ->assertOk()
            ->assertSee('Factures : échéance et mentions');
    }

    /**
     * Ces réglages engagent le magasin envers ses clients : ils se règlent
     * avec « Paramètres du magasin », qu'un vendeur n'a pas.
     */
    public function test_un_vendeur_n_accede_pas_aux_parametres_des_factures(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr']);

        $this->actingAs($vendeur)
            ->get(ParametresFactures::getUrl(tenant: $magasin))
            ->assertForbidden();
    }

    /**
     * Les réglages d'un magasin ne touchent jamais ceux d'un autre.
     */
    public function test_les_reglages_restent_ceux_du_magasin_actif(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $this->connecterLeProprietaire();

        Livewire::test(ParametresFactures::class)
            ->fillForm(['delai_paiement_jours' => '45', 'mention_escompte' => 'Escompte de 1 %.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([30, 'Pas d\'escompte pour paiement anticipé.'], [$autreMagasin->fresh()->delai_paiement_jours, $autreMagasin->fresh()->mention_escompte]);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * La page, ouverte avant qu'une autre personne ne change le délai de
     * paiement, enregistre seulement la mention qu'on y corrige : le délai
     * de l'autre personne reste, et la page le dit et le montre ensuite.
     */
    public function test_une_page_ouverte_avant_la_correction_d_une_autre_personne_ne_l_efface_pas(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresFactures::class);

        Magasin::query()->findOrFail($magasin->id)->update(['delai_paiement_jours' => 45]);
        $page->fillForm(['mention_escompte' => 'Escompte de 1 % pour paiement sous 8 jours.'])
            ->call('save')
            ->assertNotified(ParametresFactures::AUTRES_PARAMETRES_CHANGES)
            ->assertFormSet(['delai_paiement_jours' => 45]);

        $this->assertSame([45, 'Escompte de 1 % pour paiement sous 8 jours.'], [$magasin->fresh()->delai_paiement_jours, $magasin->fresh()->mention_escompte]);
    }

    /**
     * La page corrige le délai qu'elle a lu ; une autre personne l'a
     * changé entre-temps. L'enregistrement est refusé, avec un message qui
     * dit quoi faire, et rien ne s'écrit.
     */
    public function test_une_correction_d_un_parametre_change_entre_temps_est_refusee(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresFactures::class);

        Magasin::query()->findOrFail($magasin->id)->update(['delai_paiement_jours' => 45]);
        $page->fillForm(['delai_paiement_jours' => '60', 'mention_escompte' => 'Escompte de 1 % pour paiement sous 8 jours.'])
            ->call('save')
            ->assertNotified(ModifierLesParametresDuMagasinAction::REFUS_PERIMES);

        $this->assertSame([45, 'Pas d\'escompte pour paiement anticipé.'], [$magasin->fresh()->delai_paiement_jours, $magasin->fresh()->mention_escompte]);
    }
}
