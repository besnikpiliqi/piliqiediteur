<?php

namespace Tests\Feature\Calendrier;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\LesCaisses;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les pages de l'espace d'une société, qui mêlent ses magasins, ont Paris
 * pour référence : une heure propre à la société ou à un compte de la
 * société s'y lit à l'heure de Paris, sans mention ; une heure d'un
 * magasin d'outre-mer, ou d'un membre de ce magasin, s'y lit à l'heure de
 * son territoire, qu'elle nomme ; un jour s'y lit tel quel. Chaque
 * magasin y compte ses jours dans son calendrier.
 */
class HeuresDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * À 02:00 UTC le 15 juillet (04:00 le 15 à Paris, 22:00 le 14 en
     * Guadeloupe), la page « Les caisses » montre par défaut la journée en
     * cours de chaque magasin : le 15 à Paris, le 14 en Guadeloupe, chacune
     * dans son en-tête ; les heures de la Guadeloupe nomment leur heure,
     * celles de Paris n'ont aucune mention. Le jour choisi, le 14, est le
     * 14 de chaque magasin.
     */
    public function test_les_caisses_de_la_societe_montrent_chaque_magasin_a_son_jour_et_a_son_heure(): void
    {
        $this->travelTo(self::aParis('2026-07-14 12:00:00'));
        $paris = $this->magasin(Territoire::Metropole, 'PAR1', 'Boutique de Paris');
        $guadeloupe = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PTP1']);
        $julie = $this->membre($paris, RoleMagasin::Vendeur, null, ['name' => 'Julie Morel']);
        $paul = $this->membre($guadeloupe, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $this->vendreDans($paris, $julie);
        $this->travelTo(self::aParis('2026-07-14 14:00:00'));
        $this->fermerLaCaisse($paris, $julie);
        $this->travelTo(self::aLHeureDe(Territoire::Guadeloupe, '2026-07-14 19:00'));
        $this->vendreDans($guadeloupe, $paul);
        $this->travelTo(self::aParis('2026-07-15 01:30:00'));
        $this->vendreDans($paris, $julie);
        $this->travelTo(self::aLHeureDe(Territoire::Guadeloupe, '2026-07-14 21:00'));
        $this->fermerLaCaisse($guadeloupe, $paul);
        $this->travelTo(self::aParis('2026-07-15 03:30:00'));
        $this->fermerLaCaisse($paris, $julie);

        $this->travelTo(self::enUtc('2026-07-15 02:00:00'));
        $this->dansLEspaceDeLaSociete($paris->societe, $this->proprietaireDe($paris));

        $page = Livewire::test(LesCaisses::class);
        $this->assertVoitDansLOrdre($page, [
            'Boutique de Paris (PAR1) — journée du 15/07/2026',
            '14/07/2026 14:00 → 15/07/2026 03:30',
            'Boutique de Pointe-à-Pitre (PTP1) — journée du 14/07/2026',
            '14/07/2026 00:00 (heure de la Guadeloupe) → 14/07/2026 21:00 (heure de la Guadeloupe)',
        ]);
        $page->assertDontSee(['→ 14/07/2026 14:00', '(heure de Paris)']);

        $page->set('jour', '2026-07-14');
        $this->assertVoitDansLOrdre($page, [
            'Boutique de Paris (PAR1) — journée du 14/07/2026',
            '14/07/2026 00:00 → 14/07/2026 14:00',
            'Boutique de Pointe-à-Pitre (PTP1) — journée du 14/07/2026',
            '14/07/2026 00:00 (heure de la Guadeloupe) → 14/07/2026 21:00 (heure de la Guadeloupe)',
        ]);
        $page->assertDontSee('→ 15/07/2026 03:30');
    }

    /**
     * Deux factures échues le 14 juillet, l'une à Paris, l'autre en
     * Guadeloupe : à 02:00 UTC le 15, celle de Paris est en retard, pas
     * celle de la Guadeloupe, encore au 14.
     */
    public function test_les_factures_a_encaisser_de_la_societe_comptent_le_retard_au_jour_de_chaque_magasin(): void
    {
        $this->travelTo(self::aParis('2026-06-14 10:00:00'));
        $paris = $this->magasin(Territoire::Metropole, 'PAR1', 'Boutique de Paris');
        $guadeloupe = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PTP1']);
        $echeances = array_map(fn (Magasin $magasin): string => app(TenantContext::class)->runAs($magasin, fn (): Facture => $this->emettreUneFacture())->date_echeance, [$paris, $guadeloupe]);
        $this->assertSame(['2026-07-14', '2026-07-14'], $echeances);

        $this->travelTo(self::enUtc('2026-07-15 02:00:00'));
        app(TenantContext::class)->clear();
        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($this->proprietaireDe($paris));

        $this->assertSame([[1, 1], [1, 0]], [
            [$totaux[$paris->id]['nombre'], $totaux[$paris->id]['nombre_en_retard']],
            [$totaux[$guadeloupe->id]['nombre'], $totaux[$guadeloupe->id]['nombre_en_retard']],
        ]);
    }

    /**
     * À 23:30 UTC le 14 juillet (01:30 le 15 à Paris, 19:30 le 14 en
     * Guadeloupe), la société d'un magasin de la Guadeloupe est validée et
     * deux comptes se connectent : la date de validation et la dernière
     * connexion de l'administrateur s'affichent à l'heure de Paris, sans
     * mention ; celle du vendeur de la Guadeloupe à son heure, nommée. Le
     * courrier du code d'une société dont le siège est en Guadeloupe dit
     * son échéance à l'heure de Paris, et le nomme.
     */
    public function test_les_heures_de_la_societe_restent_a_l_heure_de_paris_et_celles_d_un_magasin_d_outre_mer_sont_nommees(): void
    {
        $this->travelTo(self::enUtc('2026-07-14 23:30:00'));
        $magasin = $this->magasin(Territoire::Guadeloupe, 'PTP1', 'Boutique de Pointe-à-Pitre');
        $administrateur = $this->administrateur($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        foreach ([$administrateur, $vendeur] as $compte) {
            app(SuiviDesConnexions::class)->noterLaConnexion($compte);
        }
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(TableauDeBord::class)->assertSee('Votre société est validée depuis le 15/07/2026.');
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])->assertSee('15/07/2026 à 01:30')->assertDontSee('heure de');
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])->assertSee('14/07/2026 à 19:30 (heure de la Guadeloupe)');

        $siren = SocieteFactory::sirenFictif();
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);
        $repertoire->deplacer(FauxRepertoireSirene::siretDuSiege($siren), ...MagasinFactory::lieuDuTerritoire(Territoire::Guadeloupe));
        $this->inscrireUneSociete($siren);
        $this->assertStringContainsString('valable jusqu\'au 14/08/2026 à 01:30 (heure de Paris)', $this->texteDuDernierCourrier());
    }

    /**
     * Le rapport du vérificateur, lu hors du magasin, dit l'heure d'un
     * magasin de la Guadeloupe et la nomme : un ticket de 2027 dont la date
     * d'émission recule, en base, au 31 décembre 2026 à 21:30, heure
     * locale, n'est plus dans l'année de son numéro.
     */
    public function test_le_verificateur_nomme_l_heure_d_un_magasin_d_outre_mer_dans_son_rapport(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Guadeloupe, '2027-01-02 10:00'));
        $magasin = $this->magasin(Territoire::Guadeloupe, 'PTP1', 'Boutique de Pointe-à-Pitre');
        $ticket = $this->vendreDans($magasin, self::responsableDeTest($magasin));
        $this->assertSame('TK-PTP1-2027-000001', $ticket->numero);

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $ticket->id)->update(['date_emission' => Instant::enBase(self::enUtc('2027-01-01 01:30:00'))]);

        $messages = array_map(static fn ($anomalie): string => $anomalie->message, app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
        $this->assertContains('Le ticket TK-PTP1-2027-000001 est numéroté dans l\'année 2027 mais émis le 31/12/2026 à 21:30:00 (heure de la Guadeloupe).', $messages);
    }

    /**
     * Le magasin de ce code et de ce nom, d'une société validée à
     * l'identité légale complète, sur un établissement que le répertoire
     * Sirene factice situe dans ce territoire, magasin actif.
     */
    private function magasin(Territoire $territoire, string $code, string $nom): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee($territoire, ['nom' => $nom, 'code' => $code]));
    }

    /**
     * Un ticket à un particulier, réglé en espèces à la caisse de ce
     * magasin, par ce vendeur, au taux de TVA par défaut du magasin.
     */
    private function vendreDans(Magasin $magasin, User $vendeur): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(), [[
            'produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => $magasin->tauxTvaParDefaut(), 'suivi_stock' => false])->id,
            'quantite' => 1,
        ]]));
    }

    private function fermerLaCaisse(Magasin $magasin, User $vendeur): ClotureCaissePersonnelle
    {
        return app(TenantContext::class)->runAs($magasin, fn (): ClotureCaissePersonnelle => app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur));
    }

    /**
     * Une facture à un professionnel payable à 30 jours, émise dans le
     * magasin actif par son responsable de test.
     */
    private function emettreUneFacture(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => app(TenantContext::class)->magasin()->tauxTvaParDefaut(), 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['delai_paiement_jours' => 30]), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * Ces textes s'affichent sur la page, dans cet ordre.
     *
     * @param  list<string>  $textes
     */
    private function assertVoitDansLOrdre(Testable $page, array $textes): void
    {
        $html = html_entity_decode(strip_tags($page->html()), ENT_QUOTES | ENT_HTML5);
        $html = (string) preg_replace('/\s+/u', ' ', $html);
        $positions = array_map(static fn (string $texte): int|false => mb_strpos($html, $texte), $textes);

        $this->assertNotContains(false, $positions, implode(' | ', $textes));
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    /**
     * Le texte du dernier courrier recommandé parti, tel que son
     * destinataire le lit, ses morceaux de ligne réunis par des espaces
     * simples.
     */
    private function texteDuDernierCourrier(): string
    {
        $envoi = collect(app(ServiceEnvoiRecommande::class)->envois())->last();
        $textes = array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($envoi['pdf'])));

        return (string) preg_replace('/\s+/u', ' ', implode(' ', $textes));
    }
}
