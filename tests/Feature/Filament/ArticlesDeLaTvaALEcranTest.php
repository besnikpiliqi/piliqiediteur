<?php

namespace Tests\Feature\Filament;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Exceptions\NatureOperationRefuseeException;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\AvertissementsDuCodeDActivite;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les écrans, les aides, les refus et les notifications citent l'article
 * de la loi en vigueur le jour où ils s'affichent (décisions de Claude
 * T40.1 et T40.2 du 22/11/2026, D76, voir TableDesMentionsDeTva) : au
 * calendrier du magasin dans son panneau, à celui de Paris dans l'espace
 * de la société et sur la page d'inscription ; le code général des impôts
 * (CGI) jusqu'au 31 décembre 2026, le code des impositions sur les biens
 * et services (CIBS) à partir du 1er janvier 2027. Une notification se
 * fige à son envoi.
 */
class ArticlesDeLaTvaALEcranTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'aide du choix de la nature dit la mention qu'imprimerait le
     * document émis aujourd'hui : le 31 décembre 2026 à 23 h 58 à Paris,
     * celle du CGI ; le 1er janvier 2027, trente secondes après minuit,
     * celle du CIBS.
     */
    public function test_l_aide_de_la_nature_dit_la_mention_qu_imprimerait_le_document_emis_aujourd_hui(): void
    {
        $this->travelTo(self::aParis('2026-12-31 23:58:00'));
        $this->entrerDansLeMagasin($this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique'])));
        $client = $this->clientAmericain();

        $this->formulaireDExport($client)->assertSee('Mention : « Exonération de TVA, article 262 I du CGI »');

        $this->travelTo(self::aParis('2027-01-01 00:00:30'));
        $this->formulaireDExport($client)
            ->assertSee('Mention : « Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS »')
            ->assertDontSee('262 I');
    }

    /**
     * Le 31 décembre 2026 à 20 h en Martinique, Paris est déjà le
     * 1er janvier 2027 : le panneau du magasin, à son calendrier, cite
     * encore le CGI, l'espace de la société, à celui de Paris, déjà le
     * CIBS.
     */
    public function test_le_panneau_d_un_magasin_de_la_martinique_suit_son_calendrier_et_l_espace_de_la_societe_celui_de_paris(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-12-31 20:00:00'));
        $martinique = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee(Territoire::Martinique, ['nom' => 'Fort-de-France']));
        $this->entrerDansLeMagasin($martinique);

        $this->assertSame(['2026-12-31', '2027-01-01'], [$martinique->calendrier()->aujourdhui(), Calendrier::deParis()->aujourdhui()]);
        $this->formulaireDExport($this->clientAmericain())->assertSee('Mention : « Exonération de TVA, article 262 I du CGI »');

        $this->entrerDansLaSociete($martinique->societe()->firstOrFail());
        Livewire::test(ParametresFiscauxSociete::class)
            ->assertSee('En franchise, vos ventes sont à 0 % avec la mention « TVA non applicable, art. L. 233-3 du CIBS »')
            ->assertSee('La société est membre d\'un assujetti unique (groupe TVA, art. L. 234-2 du CIBS)')
            ->assertDontSee('du CGI');
    }

    /**
     * Les paramètres fiscaux de la société, à Paris : le 31 décembre 2026
     * à 23 h 59, la franchise et l'assujetti unique citent le CGI ; à la
     * première seconde de 2027, le CIBS.
     */
    public function test_les_parametres_fiscaux_de_la_societe_citent_l_article_du_jour_a_paris(): void
    {
        $this->travelTo(self::aParis('2026-12-31 23:59:00'));
        $societe = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::FranchiseEnBase])->societe()->firstOrFail();
        $this->entrerDansLaSociete($societe);

        Livewire::test(ParametresFiscauxSociete::class)
            ->assertSee('« TVA non applicable, art. 293 B du CGI »')
            ->assertSee('La société est membre d\'un assujetti unique (groupe TVA, art. 256 C du CGI)')
            ->assertDontSee('CIBS');

        $this->travelTo(self::aParis('2027-01-01 00:00:00'));
        Livewire::test(ParametresFiscauxSociete::class)
            ->assertSee('« TVA non applicable, art. L. 233-3 du CIBS »')
            ->assertSee('La société est membre d\'un assujetti unique (groupe TVA, art. L. 234-2 du CIBS)')
            ->assertDontSee('du CGI');
    }

    /**
     * Le refus d'une prestation hors de l'Union qui vend un service au lieu
     * fixé par la loi cite les articles du jour de l'émission, ceux du CIBS
     * en 2027 ; l'aide de la fiche d'un service, ceux du jour, au calendrier
     * du magasin.
     */
    public function test_le_refus_et_l_aide_du_lieu_fixe_par_la_loi_citent_les_articles_du_jour(): void
    {
        $this->travelTo(self::aParis('2026-12-31 23:59:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->entrerDansLeMagasin($magasin);

        Livewire::test(ManageServices::class)->mountAction('create')
            ->assertSee('Jamais facturé sans TVA française à un professionnel établi à l\'étranger (art. 259 A du CGI).');

        $this->travelTo(self::aParis('2027-01-05 10:00:00'));
        Livewire::test(ManageServices::class)->mountAction('create')
            ->assertSee('Jamais facturé sans TVA française à un professionnel établi à l\'étranger (art. L. 211-75, L. 211-81, L. 211-85, L. 211-87 et L. 211-89 du CIBS).');
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $this->clientAmericain(), [
            ['service_id' => Service::factory()->lieuSpecifique()->create(['nom' => 'Location de salle'])->id, 'prix_unitaire_ttc' => '200.00', 'quantite' => 1],
        ], ['nature_operation' => NatureOperation::PrestationHorsUe]);

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            NatureOperationRefuseeException::class,
            'le lieu de « Location de salle » est fixé par la loi (art. L. 211-75, L. 211-81, L. 211-85, L. 211-87 et L. 211-89 du CIBS) : la TVA française s\'applique',
        );
    }

    /**
     * La page d'inscription, son rappel du choix définitif et les
     * avertissements du code d'activité citent l'article du jour à Paris :
     * le CGI le 31 décembre 2026 à 23 h 59, le CIBS à la première seconde
     * de 2027.
     */
    public function test_l_inscription_et_les_avertissements_du_code_d_activite_citent_l_article_du_jour_a_paris(): void
    {
        $this->travelTo(self::aParis('2026-12-31 23:59:00'));

        Livewire::test(InscriptionSociete::class)
            ->assertSeeText('Une société membre d\'un assujetti unique (groupe TVA, art. 256 C du CGI) ne peut pas encore vendre')
            ->assertSeeText('Les micro-entrepreneurs en franchise de TVA (art. 293 B du CGI) sont pris en charge.');
        $this->assertSame(
            ['La location de logements est en général exonérée de TVA (art. 261 D du CGI)', 'la TVA due est celle du pays de l\'acheteur (art. 259 D du CGI)'],
            [$this->avertissement('68.20A', 'La location de logements'), $this->avertissement('47.91A', 'la TVA due est celle du pays de l\'acheteur')],
        );

        $this->travelTo(self::aParis('2027-01-01 00:00:00'));

        Livewire::test(InscriptionSociete::class)
            ->assertSeeText('Une société membre d\'un assujetti unique (groupe TVA, art. L. 234-2 du CIBS) ne peut pas encore vendre')
            ->assertSeeText('Les micro-entrepreneurs en franchise de TVA (art. L. 233-3 du CIBS) sont pris en charge.')
            ->assertDontSeeText('du CGI');
        $this->assertStringContainsString('(art. L. 233-3 du CIBS)', TypeDActivite::rappelDuChoixDefinitif());
        $this->assertSame(
            ['La location de logements est en général exonérée de TVA (art. L. 231-21 du CIBS)', 'la TVA due est celle du pays de l\'acheteur (art. L. 232-16 et L. 232-18 du CIBS)'],
            [$this->avertissement('68.20A', 'La location de logements'), $this->avertissement('47.91A', 'la TVA due est celle du pays de l\'acheteur')],
        );
    }

    /**
     * L'avis de la cession d'un magasin cite les articles du jour de son
     * envoi et se fige alors : envoyé le 31 décembre 2026, il cite le CGI,
     * et le dit encore lu en 2027 ; envoyé le 1er janvier 2027, le CIBS.
     */
    public function test_l_avis_de_la_cession_cite_les_articles_du_jour_de_son_envoi_et_s_y_fige(): void
    {
        $this->travelTo(self::aParis('2026-12-31 18:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $magasin->societe()->firstOrFail()->proprietaire()->firstOrFail();

        $proprietaire->notify(new StockDuMagasinCede($magasin, '28.75', []));
        $this->travelTo(self::aParis('2027-01-01 09:00:00'));
        $proprietaire->notify(new StockDuMagasinCede($magasin, '12.40', []));
        $this->travelTo(self::aParis('2027-03-15 10:00:00'));

        $this->assertSame(
            [
                'Valeur du stock au coût moyen : 28,75 € HT. À rapprocher du prix de cession prévu à l\'acte et à faire vérifier par votre expert-comptable (transmission d\'universalité, art. 257 bis du CGI, ou vente du stock à facturer).',
                'Valeur du stock au coût moyen : 12,40 € HT. À rapprocher du prix de cession prévu à l\'acte et à faire vérifier par votre expert-comptable (transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS, ou vente du stock à facturer).',
            ],
            $proprietaire->notifications()->reorder('created_at')->get()->map(fn ($notification): mixed => $notification->data['body'] ?? null)->all(),
        );
    }

    /**
     * Le responsable de test de ce magasin, connecté à son panneau.
     */
    private function entrerDansLeMagasin(Magasin $magasin): void
    {
        $this->actingAs(self::responsableDeTest($magasin));
        Filament::setTenant($magasin);
        app(TenantContext::class)->set($magasin);
    }

    /**
     * Le propriétaire de cette société, connecté à l'espace de la société.
     */
    private function entrerDansLaSociete(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->assertInstanceOf(User::class, $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);
    }

    private function clientAmericain(): Client
    {
        return Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
    }

    /**
     * Le formulaire d'une nouvelle facture à ce client, en « Export hors
     * UE expédié par le magasin ».
     */
    private function formulaireDExport(Client $client): Testable
    {
        return Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id, 'nature_operation' => NatureOperation::ExportHorsUe->value]);
    }

    /**
     * L'avertissement de ce code d'activité (nomenclature NAF rév. 2) qui
     * commence par ce début, jusqu'à la parenthèse qui cite son article.
     */
    private function avertissement(string $code, string $debut): string
    {
        foreach (AvertissementsDuCodeDActivite::pour($code, AvertissementsDuCodeDActivite::NOMENCLATURE) as $avertissement) {
            $position = mb_strpos($avertissement['texte'], $debut);

            if ($position !== false) {
                return mb_substr($avertissement['texte'], $position, (int) mb_strpos($avertissement['texte'], ')', $position) - $position + 1);
            }
        }

        return '';
    }
}
