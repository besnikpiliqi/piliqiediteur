<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\DepannageADomicile;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\DroitDeRetractation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le devis de dépannage, de réparation ou d'entretien à domicile
 * (arrêté du 24 janvier 2017, décision de Claude T51.6) : un réglage
 * facultatif du magasin, décoché au départ, qui ne vaut que pour un devis
 * à un particulier. Coché, le devis imprime le lieu de l'intervention,
 * prérempli avec l'adresse du client, « Devis gratuit » ou « Devis
 * payant : X € TTC », et le droit de garder les pièces remplacées ; signé
 * chez le client, il imprime aussi le droit de rétractation de quatorze
 * jours et son formulaire (art. L. 221-5 et L. 221-18 du code de la
 * consommation). Le réglage et le prix se figent à l'envoi du devis.
 */
class DevisDeDepannageADomicileTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Serrurerie Martin', 'code' => 'SERRU']));
        app(TenantContext::class)->set($this->magasin);
    }

    public function test_sans_le_reglage_le_devis_d_un_particulier_n_imprime_aucune_mention_de_depannage(): void
    {
        $devis = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas', 'signe_chez_le_client' => true]));

        $html = $this->html($devis);

        $this->assertSame([null, false, false, null], [$devis->lieu_d_intervention, $devis->signe_chez_le_client, $devis->depannage_a_domicile, $devis->prix_du_devis_de_depannage]);
        foreach (['Dépannage, réparation ou entretien à domicile', 'Lieu de l\'intervention', 'Devis gratuit', 'Devis payant', DepannageADomicile::PIECES_REMPLACEES, DroitDeRetractation::TITRE] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    /**
     * Le serrurier change un cylindre chez un particulier : son devis dit
     * où il intervient, qu'il est gratuit, et que le client peut garder
     * l'ancien cylindre ; le déplacement est une ligne à part.
     */
    public function test_le_devis_d_un_particulier_imprime_le_lieu_le_devis_gratuit_et_les_pieces_remplacees(): void
    {
        $this->regler(true);
        $devis = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => "  12 rue des Lilas\n75012 Paris  "]));

        $html = $this->html($devis);

        $this->assertSame(["12 rue des Lilas\n75012 Paris", false, true, null], [$devis->lieu_d_intervention, $devis->signe_chez_le_client, $devis->depannage_a_domicile, $devis->prix_du_devis_de_depannage]);
        foreach (['Dépannage, réparation ou entretien à domicile', 'Lieu de l\'intervention : 12 rue des Lilas', 'Devis gratuit', DepannageADomicile::PIECES_REMPLACEES, 'Changement de cylindre', 'Déplacement'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringNotContainsString(DroitDeRetractation::TITRE, $html);
        $this->assertStringNotContainsString('Devis payant', $html);
    }

    public function test_un_devis_payant_imprime_son_prix_toutes_taxes_comprises(): void
    {
        $this->regler(true, '35.50');

        $html = $this->html($this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas'])));

        $this->assertStringContainsString('Devis payant : 35,50 € TTC', $html);
        $this->assertStringNotContainsString('Devis gratuit', $html);
    }

    /**
     * L'arrêté ne vise que les consommateurs : le devis d'un professionnel
     * ne porte rien, même quand le magasin a coché le réglage, et n'en
     * garde aucun lieu ni aucune signature chez le client.
     */
    public function test_le_devis_d_un_professionnel_n_imprime_rien_meme_quand_le_magasin_a_coche_le_reglage(): void
    {
        $this->regler(true, '35.00');

        $devis = $this->emettre($this->creer(Client::factory()->professionnel()->create(), ['lieu_d_intervention' => '12 rue des Lilas', 'signe_chez_le_client' => true]));
        $html = $this->html($devis);

        $this->assertSame([null, false, false, null], [$devis->lieu_d_intervention, $devis->signe_chez_le_client, $devis->depannage_a_domicile, $devis->prix_du_devis_de_depannage]);
        foreach (['Dépannage, réparation ou entretien à domicile', 'Devis gratuit', 'Devis payant', DepannageADomicile::PIECES_REMPLACEES, DroitDeRetractation::TITRE] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    /**
     * Un brouillon suit le réglage du magasin d'aujourd'hui ; un devis
     * envoyé imprime celui qu'il a figé à son envoi, jusqu'à sa prochaine
     * modification, qui le fait suivre de nouveau le magasin, et à son
     * prochain envoi, qui fige le nouveau.
     */
    public function test_le_reglage_et_le_prix_du_devis_se_figent_a_l_envoi(): void
    {
        $this->regler(true);
        $envoye = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas']));
        $this->regler(true, '50.00');
        $brouillon = $this->creer(self::particulier(), ['lieu_d_intervention' => '3 rue Lepic']);

        $this->assertStringContainsString('Devis gratuit', $this->html($envoye->fresh()));
        $this->assertStringContainsString('Devis payant : 50,00 € TTC', $this->html($brouillon));

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $envoye->fresh(), $envoye->client, self::lignes(), ['lieu_d_intervention' => '12 rue des Lilas']);
        $this->assertSame([StatutDevis::Brouillon, null, null], [$modifie->statut, $modifie->depannage_a_domicile, $modifie->prix_du_devis_de_depannage]);
        $this->assertStringContainsString('Devis payant : 50,00 € TTC', $this->html($modifie));

        $renvoye = $this->emettre($modifie);
        $this->regler(false);

        $this->assertSame([true, '50.00'], [$renvoye->fresh()->depannage_a_domicile, $renvoye->fresh()->prix_du_devis_de_depannage]);
        $this->assertStringContainsString('Devis payant : 50,00 € TTC', $this->html($renvoye->fresh()));
    }

    public function test_un_devis_de_depannage_sans_lieu_d_intervention_est_refuse(): void
    {
        $this->regler(true);

        foreach ([[], ['lieu_d_intervention' => '   '], ['lieu_d_intervention' => null]] as $attributs) {
            $this->assertRefus(DepannageADomicile::REFUS_SANS_LIEU, fn () => $this->creer(self::particulier(), $attributs));
        }

        $this->assertRefus('Le lieu de l\'intervention ne doit pas dépasser 500 caractères.', fn () => $this->creer(self::particulier(), ['lieu_d_intervention' => str_repeat('a', 501)]));
        $this->assertSame(0, Devis::query()->count());
    }

    /**
     * Un brouillon fait avant que le magasin coche le réglage n'a pas de
     * lieu : il ne part pas avant d'avoir été modifié pour l'indiquer.
     */
    public function test_un_brouillon_devenu_un_devis_de_depannage_ne_part_pas_sans_lieu(): void
    {
        $devis = $this->creer(self::particulier());
        $this->regler(true);

        $this->assertRefus(DepannageADomicile::REFUS_D_ENVOI_SANS_LIEU, fn () => $this->emettre($devis->fresh()));

        $this->assertSame([StatutDevis::Brouillon, null, null], [$devis->fresh()->statut, $devis->fresh()->numero, $devis->fresh()->depannage_a_domicile]);
        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis->fresh(), $devis->client, self::lignes(), ['lieu_d_intervention' => '12 rue des Lilas']);
        $this->assertSame(StatutDevis::Envoye, $this->emettre($modifie)->statut);
    }

    /**
     * Un brouillon de dépannage enregistré avec un lieu et « Devis signé
     * chez le client », puis le magasin décoche le réglage : la fiche ne
     * dit plus rien que le PDF n'imprime pas, et le devis qui part perd le
     * lieu et la case, ni imprimés, ni gardés dans son empreinte. Le
     * magasin ne croit jamais avoir remis au client l'information sur la
     * rétractation qu'il n'a pas reçue.
     */
    public function test_un_devis_qui_part_apres_que_le_magasin_a_decoche_le_reglage_perd_son_lieu_et_sa_signature(): void
    {
        $this->actingAs($this->proprietaireDe($this->magasin));
        Filament::setTenant($this->magasin);
        $this->regler(true);
        $devis = $this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas', 'signe_chez_le_client' => true]);
        $this->regler(false);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertDontSeeText('Lieu de l\'intervention')
            ->assertDontSeeText('Oui : le devis imprime le droit de rétractation et son formulaire');

        $envoye = $this->emettre($devis->fresh());

        $this->assertSame([null, false, false], [$envoye->fresh()->lieu_d_intervention, $envoye->fresh()->signe_chez_le_client, $envoye->fresh()->depannage_a_domicile]);
        $this->assertSame($envoye->fresh()->empreinteDeSaVersion(), (clone $envoye->fresh())->forceFill(['lieu_d_intervention' => null, 'signe_chez_le_client' => false])->empreinteDeSaVersion());
        $html = $this->html($envoye);
        foreach (['Lieu de l\'intervention', 'Devis signé chez le client', DroitDeRetractation::TITRE] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertDontSeeText('Lieu de l\'intervention')
            ->assertDontSeeText('Devis signé chez le client');
    }

    /**
     * Le lieu de l'intervention saisi sur plusieurs lignes, comme le
     * formulaire le préremplit, s'imprime une ligne par ligne saisie dans
     * le PDF, et se lit de même sur la fiche.
     */
    public function test_le_lieu_de_l_intervention_s_imprime_une_ligne_par_ligne_saisie(): void
    {
        $this->actingAs($this->proprietaireDe($this->magasin));
        Filament::setTenant($this->magasin);
        $this->regler(true);
        $devis = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => "12 rue des Lilas\nBâtiment B\n75012 Paris"]));

        $textes = array_merge(...array_map(fn (array $page): array => $page['textes'], $this->pagesDuPdf(app(DevisPdfGenerator::class)->generer($devis->fresh())->output())));
        // Chaque ligne saisie s'imprime juste sous la précédente (la hauteur
        // se compte depuis le bas de la page), à la même marge ; le client,
        // plus haut, imprime aussi son adresse.
        $premiere = collect($textes)->first(fn (array $morceau): bool => trim($morceau['texte']) === 'Lieu de l\'intervention : 12 rue des Lilas') ?? $this->fail('Le PDF n\'imprime pas la première ligne du lieu.');
        $precedente = $premiere;

        foreach (['Bâtiment B', '75012 Paris'] as $texte) {
            $suivante = collect($textes)->first(fn (array $morceau): bool => trim($morceau['texte']) === $texte && $morceau['y'] < $precedente['y'] && $precedente['y'] - $morceau['y'] < 15)
                ?? $this->fail("Le PDF n'imprime pas « {$texte} » sur la ligne qui suit la précédente du lieu.");
            $this->assertEqualsWithDelta($premiere['x'], $suivante['x'], 0.01);
            $precedente = $suivante;
        }

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSeeHtml('12 rue des Lilas<br>'.PHP_EOL.'Bâtiment B<br>'.PHP_EOL.'75012 Paris');
    }

    /**
     * Un brouillon pour un particulier fait avant que le magasin coche le
     * réglage n'a pas de lieu, et son envoi le refuse en demandant de le
     * modifier : sa modification préremplit le lieu avec l'adresse du
     * client, comme à la création, et l'enregistre.
     */
    public function test_la_modification_d_un_brouillon_fait_avant_le_reglage_preremplit_le_lieu(): void
    {
        $this->actingAs($this->proprietaireDe($this->magasin));
        Filament::setTenant($this->magasin);
        $devis = $this->creer(self::particulier());
        $this->regler(true);

        Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()])
            ->assertFormFieldIsVisible('lieu_d_intervention')
            ->assertFormSet(['lieu_d_intervention' => "12 rue des Lilas\nBâtiment B\n75012 Paris"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame("12 rue des Lilas\nBâtiment B\n75012 Paris", $devis->fresh()->lieu_d_intervention);

        $devisDuProfessionnel = $this->creer(Client::factory()->professionnel()->create());
        Livewire::test(EditDevis::class, ['record' => $devisDuProfessionnel->getRouteKey()])
            ->assertFormFieldIsHidden('lieu_d_intervention')
            ->assertFormSet(['lieu_d_intervention' => null]);
    }

    /**
     * Le réglage se lit sur la ligne du magasin relue sous verrou, jamais sur
     * le magasin du contexte, lu au début de la requête : coché entre-temps,
     * il vaut déjà, à l'enregistrement comme à l'envoi.
     */
    public function test_le_reglage_coche_entre_temps_vaut_des_l_enregistrement_du_devis(): void
    {
        Magasin::query()->whereKey($this->magasin->id)->update(['depannage_a_domicile' => true]);
        $this->assertFalse((bool) app(TenantContext::class)->magasin()->depannage_a_domicile);

        $devis = $this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas']);
        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $devis->client, self::lignes(), ['lieu_d_intervention' => '14 rue des Lilas', 'signe_chez_le_client' => true]);

        $this->assertSame(['14 rue des Lilas', true], [$modifie->lieu_d_intervention, $modifie->signe_chez_le_client]);
        $this->assertSame([StatutDevis::Envoye, true], [$this->emettre($modifie)->statut, $modifie->fresh()->depannage_a_domicile]);
    }

    /**
     * Signé au domicile du client, le contrat est conclu hors établissement :
     * le devis imprime, sur une page à part, l'information sur le droit de
     * rétractation de quatorze jours et le formulaire type, adressé au
     * magasin qui envoie le devis et qui nomme ce devis.
     */
    public function test_signe_chez_le_client_le_devis_imprime_le_droit_de_retractation_et_son_formulaire(): void
    {
        $this->regler(true);
        $signe = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas', 'signe_chez_le_client' => true]));
        $nonSigne = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas']));

        $html = $this->html($signe);

        foreach ([
            '<div class="retractation">', DroitDeRetractation::TITRE, 'dans un délai de quatorze jours', 'Serrurerie Martin',
            DroitDeRetractation::TITRE_DU_FORMULAIRE, "(devis n° {$signe->numero})", '(*) Rayez la mention inutile.',
            'Devis signé chez le client : votre droit de rétractation et son formulaire figurent à la fin de ce devis.',
            'art. L. 221-28 du code de la consommation',
        ] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertTrue($signe->signe_chez_le_client);
        $this->assertStringNotContainsString(DroitDeRetractation::TITRE, $this->html($nonSigne));
        $this->assertStringNotContainsString('Devis signé chez le client', $this->html($nonSigne));
    }

    /**
     * Le lieu et la signature chez le client font partie de la version du
     * devis que son client reçoit : son empreinte change avec eux.
     */
    public function test_le_lieu_et_la_signature_chez_le_client_changent_l_empreinte_du_devis(): void
    {
        $this->regler(true);
        $devis = $this->emettre($this->creer(self::particulier(), ['lieu_d_intervention' => '12 rue des Lilas']));
        $empreinte = $devis->empreinteDeSaVersion();

        $this->assertNotSame($empreinte, (clone $devis)->forceFill(['lieu_d_intervention' => '14 rue des Lilas'])->empreinteDeSaVersion());
        $this->assertNotSame($empreinte, (clone $devis)->forceFill(['signe_chez_le_client' => true])->empreinteDeSaVersion());
        $this->assertSame($empreinte, $devis->fresh()->empreinteDeSaVersion());
    }

    /**
     * La base refuse un lieu d'intervention ou une signature chez le client
     * sur le devis d'un professionnel, un prix de devis nul, et un prix figé
     * sur un devis qui n'est pas un devis de dépannage.
     */
    public function test_la_base_refuse_un_depannage_sur_un_devis_de_professionnel_et_un_prix_de_devis_nul(): void
    {
        $professionnel = $this->creer(Client::factory()->professionnel()->create());
        $particulier = $this->creer(self::particulier());

        foreach ([
            fn () => DB::table('devis')->where('id', $professionnel->id)->update(['lieu_d_intervention' => '12 rue des Lilas']),
            fn () => DB::table('devis')->where('id', $professionnel->id)->update(['signe_chez_le_client' => true]),
            fn () => DB::table('devis')->where('id', $particulier->id)->update(['depannage_a_domicile' => false, 'prix_du_devis_de_depannage' => '35.00']),
            fn () => DB::table('devis')->where('id', $particulier->id)->update(['depannage_a_domicile' => true, 'prix_du_devis_de_depannage' => '0.00']),
            fn () => DB::table('magasins')->where('id', $this->magasin->id)->update(['prix_du_devis_de_depannage' => '0.00']),
        ] as $ecriture) {
            DB::beginTransaction();

            try {
                $ecriture();
                $this->fail('La base aurait dû refuser cette écriture.');
            } catch (QueryException $refus) {
                $this->assertStringContainsString('check', strtolower($refus->getMessage()));
            } finally {
                DB::rollBack();
            }
        }
    }

    /**
     * Le formulaire du devis montre le lieu de l'intervention et la case
     * « Devis signé chez le client » quand le magasin a coché le réglage et
     * que le client est un particulier, avec le lieu prérempli de son
     * adresse ; jamais pour un professionnel ; le devis enregistré les garde.
     */
    public function test_le_formulaire_preremplit_le_lieu_avec_l_adresse_du_particulier_et_le_cache_pour_un_professionnel(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $this->actingAs($proprietaire);
        Filament::setTenant($this->magasin);
        $this->regler(true);
        $particulier = self::particulier();
        $professionnel = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['nom' => 'Changement de cylindre', 'prix_vente_ttc' => 45, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $professionnel->id])
            ->assertFormFieldIsHidden('lieu_d_intervention')
            ->assertFormFieldIsHidden('signe_chez_le_client')
            ->fillForm(['client_id' => $particulier->id])
            ->assertFormFieldIsVisible('lieu_d_intervention')
            ->assertFormFieldIsVisible('signe_chez_le_client')
            ->assertFormSet(['lieu_d_intervention' => "12 rue des Lilas\nBâtiment B\n75012 Paris"])
            ->fillForm(['lieu_d_intervention' => '', 'signe_chez_le_client' => true])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $produit->id, 'quantite' => 1]])
            ->call('create')
            ->assertHasFormErrors(['lieu_d_intervention' => 'required'])
            ->fillForm(['lieu_d_intervention' => "12 rue des Lilas\n75012 Paris"])
            ->call('create')
            ->assertHasNoFormErrors();

        $devis = Devis::query()->sole();
        $this->assertSame(["12 rue des Lilas\n75012 Paris", true], [$devis->lieu_d_intervention, $devis->signe_chez_le_client]);
        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSeeText('Lieu de l\'intervention')
            ->assertSeeText('12 rue des Lilas')
            ->assertSeeText('Oui : le devis imprime le droit de rétractation et son formulaire');
    }

    /**
     * Coche le réglage du magasin et règle le prix du devis (null pour un
     * devis gratuit), comme la page « Factures et devis ».
     */
    private function regler(bool $depannage, ?string $prix = null): void
    {
        Magasin::query()->whereKey($this->magasin->id)->update(['depannage_a_domicile' => $depannage, 'prix_du_devis_de_depannage' => $prix]);
        $this->magasin->refresh();
        app(TenantContext::class)->set($this->magasin);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function creer(Client $client, array $attributs = []): Devis
    {
        return app(CreerDevisAction::class)->handle(self::auteur(), $client, self::lignes(), ['date_validite' => '2026-12-31', ...$attributs]);
    }

    private function emettre(Devis $devis): Devis
    {
        return app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
    }

    private function html(Devis $devis): string
    {
        return app(DevisPdfGenerator::class)->html($devis->fresh() ?? $devis);
    }

    /**
     * Un cylindre et le déplacement, sur une ligne à part.
     *
     * @return list<array<string, int>>
     */
    private static function lignes(): array
    {
        $cylindre = Produit::query()->where('nom', 'Changement de cylindre')->first() ?? Produit::factory()->create(['nom' => 'Changement de cylindre', 'prix_vente_ttc' => 45, 'taux_tva' => 20, 'suivi_stock' => false]);
        $deplacement = Service::query()->where('nom', 'Déplacement')->first() ?? Service::factory()->create(['nom' => 'Déplacement', 'prix_ttc' => 30, 'taux_tva' => 20]);

        return [
            ['produit_id' => $cylindre->id, 'quantite' => 1],
            ['service_id' => $deplacement->id, 'quantite' => 1],
        ];
    }

    private static function particulier(): Client
    {
        return Client::factory()->create(['nom' => 'Paul Durand', 'adresse' => '12 rue des Lilas', 'complement_adresse' => 'Bâtiment B', 'code_postal' => '75012', 'ville' => 'Paris']);
    }

    private function assertRefus(string $message, callable $geste): void
    {
        try {
            $geste();
            $this->fail("Le geste aurait dû être refusé : {$message}");
        } catch (LogicException $refus) {
            $this->assertSame($message, $refus->getMessage());
        }
    }
}
