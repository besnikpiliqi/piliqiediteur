<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La date de la livraison ou de la prestation (T49, art. 242 nonies A de
 * l'annexe II au CGI ; décision de Claude, D76) : une facture note un jour
 * ou, pour une prestation de plusieurs jours, une période, qu'elle signe à
 * son émission et n'imprime que quand elle n'est pas le jour de son
 * émission, au calendrier de son magasin ; un devis note la date prévue,
 * qu'il imprime toujours et que la facture tirée du devis reprend. La
 * rectificative reprend celle de la facture qu'elle rectifie (voir
 * RegularisationDUneFactureTest), et le contenu signé est essayé dans
 * EmissionSigneeTest.
 */
class DateDeLOperationTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(Territoire $territoire = Territoire::Metropole): Magasin
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee($territoire, ['nom' => 'Boutique', 'code' => 'BTQ1']));
        app(TenantContext::class)->set($magasin->fresh());

        return $magasin->fresh();
    }

    /**
     * Un brouillon de facture d'un article de 30,00 € TTC à un
     * professionnel, avec ces attributs.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function brouillon(array $attributs = []): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => app(TenantContext::class)->magasin()->tauxTvaParDefaut(), 'suivi_stock' => false]);

        return app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], $attributs);
    }

    private function emettre(Facture $brouillon): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);
    }

    /**
     * @return list<string>
     */
    private function mentions(Facture|Devis $document): array
    {
        return app(MentionsFiscales::class)->pour($document, $document->emetteur());
    }

    /**
     * Un jour, une période, un premier jour égal au dernier (un seul
     * jour), des champs vides (aucune date) : chacun se note tel quel, sans
     * espaces autour.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array{0: string|null, 1: string|null}, 2: string|null}>
     */
    public static function saisies(): array
    {
        return [
            'un jour' => [['date_operation_fin' => '2026-10-07'], [null, '2026-10-07'], '07/10/2026'],
            'une période' => [['date_operation_debut' => ' 2026-10-05 ', 'date_operation_fin' => '2026-10-07'], ['2026-10-05', '2026-10-07'], 'du 05/10/2026 au 07/10/2026'],
            'un premier jour égal au dernier' => [['date_operation_debut' => '2026-10-07', 'date_operation_fin' => '2026-10-07'], [null, '2026-10-07'], '07/10/2026'],
            'des champs vides' => [['date_operation_debut' => '', 'date_operation_fin' => null], [null, null], null],
        ];
    }

    /**
     * @param  array<string, mixed>  $saisie
     * @param  array{0: string|null, 1: string|null}  $notee
     */
    #[DataProvider('saisies')]
    public function test_une_facture_note_un_jour_ou_une_periode(array $saisie, array $notee, ?string $imprimee): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();

        $facture = $this->brouillon($saisie);

        $this->assertSame($notee, [$facture->date_operation_debut, $facture->date_operation_fin]);
        $this->assertSame($imprimee, $facture->dateDeLOperation());
    }

    /**
     * Un premier jour sans dernier jour, un premier jour après le dernier
     * ou un jour illisible sont refusés à l'enregistrement, en français, et
     * rien ne s'enregistre ; modifier le brouillon sans toucher à sa date
     * la garde.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function saisiesRefusees(): array
    {
        return [
            'un premier jour sans dernier jour' => [['date_operation_debut' => '2026-10-05'], Facture::REFUS_DEBUT_SANS_FIN],
            'un premier jour après le dernier' => [['date_operation_debut' => '2026-10-08', 'date_operation_fin' => '2026-10-07'], Facture::REFUS_DEBUT_APRES_FIN],
            'un jour illisible' => [['date_operation_fin' => '07/10/2026'], Facture::REFUS_JOUR_ILLISIBLE],
            'un jour qui n\'existe pas' => [['date_operation_fin' => '2026-02-30'], Facture::REFUS_JOUR_ILLISIBLE],
        ];
    }

    /**
     * @param  array<string, mixed>  $saisie
     */
    #[DataProvider('saisiesRefusees')]
    public function test_une_date_de_l_operation_incoherente_est_refusee_a_l_enregistrement(array $saisie, string $refus): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();

        try {
            $this->brouillon($saisie);
            $this->fail('La date de l\'opération aurait dû être refusée.');
        } catch (LogicException $refuse) {
            $this->assertSame($refus, $refuse->getMessage());
        }

        $this->assertSame(0, Facture::query()->count());
    }

    public function test_modifier_un_brouillon_sans_sa_date_la_garde_et_la_changer_la_remplace(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $brouillon = $this->brouillon(['date_operation_debut' => '2026-10-05', 'date_operation_fin' => '2026-10-07']);
        $lignes = [['produit_id' => $brouillon->lignes->sole()->produit_id, 'quantite' => 2]];

        $garde = app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $brouillon->client, $lignes, ['notes' => 'Deux articles']);
        $this->assertSame(['2026-10-05', '2026-10-07'], [$garde->date_operation_debut, $garde->date_operation_fin]);

        $remplacee = app(ModifierFactureAction::class)->handle(self::auteur(), $garde, $garde->client, $lignes, ['date_operation_debut' => null, 'date_operation_fin' => '2026-10-08']);
        $this->assertSame([null, '2026-10-08'], [$remplacee->date_operation_debut, $remplacee->date_operation_fin]);
    }

    /**
     * La base refuse, comme l'enregistrement, un premier jour sans dernier
     * jour ou qui ne le précède pas, sur une facture comme sur un devis,
     * même écrit sans passer par les actions (chaque essai dans sa propre
     * transaction, que le refus annule).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function periodesRefuseesParLaBase(): array
    {
        return [
            'sans dernier jour' => ['2026-10-05', null],
            'égal au dernier' => ['2026-10-07', '2026-10-07'],
            'après le dernier' => ['2026-10-08', '2026-10-07'],
        ];
    }

    #[DataProvider('periodesRefuseesParLaBase')]
    public function test_la_base_refuse_une_periode_dont_le_premier_jour_ne_precede_pas_le_dernier(string $debut, ?string $fin): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $facture = $this->brouillon();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $facture->client, [['produit_id' => $facture->lignes->sole()->produit_id, 'quantite' => 1]], ['date_validite' => '2026-11-09']);

        foreach (['factures_periode_de_l_operation' => $facture, 'devis_periode_de_l_operation' => $devis] as $contrainte => $document) {
            try {
                DB::transaction(fn (): bool => $document->forceFill(['date_operation_debut' => $debut, 'date_operation_fin' => $fin])->save());
                $this->fail("La base aurait dû refuser la période ({$contrainte}).");
            } catch (QueryException $refus) {
                $this->assertStringContainsString($contrainte, $refus->getMessage());
            }
        }
    }

    /**
     * La facture imprime la date de l'opération quand elle n'est pas le
     * jour de son émission : la veille, ou une période ; pas le jour même,
     * ni quand aucune n'est notée. Le brouillon s'imprime comme il
     * s'émettrait aujourd'hui, et la fiche du PDF en est la preuve.
     */
    public function test_la_facture_imprime_sa_date_seulement_quand_elle_n_est_pas_le_jour_de_son_emission(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $veille = $this->brouillon(['date_operation_fin' => '2026-10-08']);
        $periode = $this->brouillon(['date_operation_debut' => '2026-10-01', 'date_operation_fin' => '2026-10-31']);
        $memeJour = $this->brouillon(['date_operation_fin' => '2026-10-09']);
        $sansDate = $this->brouillon();

        $this->assertContains('Date de la livraison ou de la prestation : 08/10/2026', $this->mentions($veille));
        $this->assertContains('Date de la livraison ou de la prestation : du 01/10/2026 au 31/10/2026', $this->mentions($periode));

        foreach ([$veille, $periode, $memeJour, $sansDate] as $brouillon) {
            $emise = $this->emettre($brouillon);
            $html = app(FacturePdfGenerator::class)->html($emise->load('client', 'lignes', 'magasin'));
            $pied = PiedDePage::pour($emise, $emise->emetteur())->lignes;

            $attendue = match ($emise->is($memeJour) || $emise->is($sansDate)) {
                true => null,
                false => 'Date de la livraison ou de la prestation : '.$emise->dateDeLOperation(),
            };

            if ($attendue === null) {
                $this->assertSame([], array_values(array_filter($pied, fn (string $ligne): bool => Str::startsWith($ligne, 'Date de la livraison'))));
                $this->assertStringNotContainsString('Date de la livraison', $html);
            } else {
                $this->assertContains($attendue, $pied);
                $this->assertStringContainsString(e($attendue), $html);
            }
        }
    }

    /**
     * Le jour de l'émission est celui du calendrier du magasin : en
     * Martinique, une facture émise le 16 novembre à 20 h (le 17 à Paris
     * et en UTC) pour une livraison du 16 n'imprime rien, et une livraison
     * du 17 s'imprime.
     */
    public function test_le_jour_de_l_emission_est_celui_du_calendrier_du_magasin(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $this->magasin(Territoire::Martinique);

        $duJour = $this->emettre($this->brouillon(['date_operation_fin' => '2026-11-16']));
        $duLendemain = $this->emettre($this->brouillon(['date_operation_fin' => '2026-11-17']));

        $this->assertNotContains('Date de la livraison ou de la prestation : 16/11/2026', $this->mentions($duJour));
        $this->assertContains('Date de la livraison ou de la prestation : 17/11/2026', $this->mentions($duLendemain));
    }

    /**
     * Un devis imprime la date prévue de l'opération, qu'elle soit le jour
     * de son envoi ou non ; la facture tirée du devis accepté la reprend,
     * et son brouillon permet de la corriger avant l'émission.
     */
    public function test_le_devis_imprime_la_date_prevue_et_la_facture_tiree_du_devis_la_reprend(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->magasin();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], [
            'date_validite' => '2026-11-09',
            'date_operation_debut' => '2026-11-02',
            'date_operation_fin' => '2026-11-06',
        ]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), $devis, (string) Str::uuid());

        $this->assertContains('Date prévue de la livraison ou de la prestation : du 02/11/2026 au 06/11/2026', $this->mentions($devis));
        $this->assertStringContainsString('Date prévue de la livraison ou de la prestation : du 02/11/2026 au 06/11/2026', app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin')));

        app(AccepterDevisAction::class)->handle(self::auteur(), $devis);
        $facture = app(CreerFactureDepuisDevisAction::class)->handle(self::responsableDeTest($magasin), $devis->fresh());

        $this->assertSame(['2026-11-02', '2026-11-06'], [$facture->date_operation_debut, $facture->date_operation_fin]);
    }
}
