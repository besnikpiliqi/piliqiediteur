<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\NoterLeVisaDuBordereauAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\VisaDetaxe;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La date du visa d'un bordereau de détaxe (décision de l'éditeur du
 * 01/11/2026, Q-AF13) : un ticket détaxé attend le visa de son bordereau
 * dans l'« À faire » du magasin jusqu'à ce que qui note les bordereaux en
 * note la date ; le visa s'écrit une fois par bordereau, s'inscrit au
 * journal d'audit, et se note encore dans un magasin fermé (Q-AF12). Le
 * magasin de Lyon vend au comptoir le 9 octobre 2026.
 */
class VisaDuBordereauTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_visa_se_note_une_fois_sur_le_bordereau_qui_fait_foi_et_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $ticket = $this->ticketDetaxe($responsable, '2026FR000123456');

        $this->travelTo(self::aParis('2026-10-14 09:00:00'));
        $visa = app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $ticket, '2026-10-12');

        $this->assertSame(['2026-10-12', $responsable->name, '2026FR000123456'], [$visa->date_visa, $visa->note_par_nom, $visa->bordereau->numero]);
        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::VisaDeDetaxeNote);
        $this->assertCount(1, $entrees);
        $this->assertEqualsCanonicalizing(['ticket' => $ticket->numero, 'bordereau' => '2026FR000123456', 'date_visa' => '2026-10-12'], $entrees[0]->nouvelles_valeurs);
        $this->assertFalse(Facture::query()->whereKey($ticket->id)->visaANoter()->exists());
        $this->assertThrows(fn () => app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $ticket, '2026-10-13'), LogicException::class, 'Le visa du bordereau n° 2026FR000123456 est déjà noté, au 12/10/2026.');

        app(NoterBordereauDetaxeAction::class)->handle($responsable, $ticket, '2026FR000654321');

        $this->assertTrue(Facture::query()->whereKey($ticket->id)->visaANoter()->exists());
        $this->assertSame(1, VisaDetaxe::query()->count());
    }

    /**
     * Le jour du visa n'est ni avant le jour du ticket ni après aujourd'hui,
     * au calendrier du magasin ; sans bordereau, aucun visa ne se note.
     */
    public function test_la_date_du_visa_est_bornee_et_un_ticket_sans_bordereau_n_en_recoit_pas(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $sansBordereau = $this->ticket($responsable);
        $ticket = $this->ticketDetaxe($responsable, '2026FR000123456');
        $noter = fn (Facture $vente, string $jour) => app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $vente, $jour);

        $this->assertThrows(fn () => $noter($sansBordereau, '2026-10-09'), LogicException::class, 'Le visa se note sur un ticket détaxé : notez d\'abord le n° de son bordereau de détaxe.');
        $this->assertThrows(fn () => $noter($ticket, '2026-10-08'), InvalidArgumentException::class, 'Le visa ne peut pas précéder le ticket, du 09/10/2026.');
        $this->assertThrows(fn () => $noter($ticket, '2026-10-10'), InvalidArgumentException::class, 'La date du visa ne peut pas être dans le futur.');
        $this->assertThrows(fn () => $noter($ticket, '12/10/2026'), InvalidArgumentException::class, 'La date du visa n\'est pas une date valide.');

        $this->assertSame(0, VisaDetaxe::query()->count());
    }

    /**
     * Le ticket détaxé attend son visa dans l'« À faire » : à faire pour
     * qui note les bordereaux (le responsable, le propriétaire, qui le fait
     * même sans vendre), avec « Noter le visa » et la fiche du ticket ; deux
     * tickets mènent à la liste filtrée « Visa à noter », qui montre
     * exactement ces tickets avec leur raison ; rien pour un observateur qui
     * ne voit pas ces ventes. Le visa noté, la ligne disparaît.
     */
    public function test_le_ticket_detaxe_attend_son_visa_dans_l_a_faire_jusqu_a_ce_qu_il_soit_note(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $premier = $this->ticketDetaxe($responsable, '2026FR000000001');
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        $chose = $this->chose($responsable, $magasin, 'visas_detaxe');
        $this->assertSame([UrgenceAFaire::Orange, false, 'Noter le visa', FactureResource::getUrl('view', ['record' => $premier]), '2026-10-09'], [$chose?->urgence, $chose?->aSurveiller, $chose?->bouton, $chose?->url, $chose?->date]);
        $this->assertSame([false, 'Noter le visa'], [$this->chose($this->proprietaireDe($magasin), $magasin, 'visas_detaxe')?->aSurveiller, $this->chose($this->proprietaireDe($magasin), $magasin, 'visas_detaxe')?->bouton]);
        $this->assertNull($this->chose($observateur, $magasin, 'visas_detaxe'));

        $second = $this->ticketDetaxe($responsable, '2026FR000000002');
        $sansBordereau = $this->ticket($responsable);
        $chose = $this->chose($responsable, $magasin, 'visas_detaxe');
        $this->assertSame([2, FactureResource::urlDesVisasANoter()], [$chose?->nombre, $chose?->url]);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_VISA_A_NOTER)
            ->assertCanSeeTableRecords([$premier, $second])
            ->assertCanNotSeeTableRecords([$sansBordereau])
            ->assertTableColumnStateSet('a_faire', ['À encaisser', 'Visa à noter'], $premier);

        app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $premier, '2026-10-09');
        app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $second, '2026-10-09');
        $this->assertNull($this->chose($responsable, $magasin, 'visas_detaxe'));
    }

    /**
     * Depuis la fiche du ticket, « Noter le visa du bordereau » enregistre
     * la date, puis disparaît ; la section « Détaxe » dit le visa et qui
     * l'a noté. Le propriétaire le fait, même dans un magasin fermé, où les
     * preuves continuent jusqu'au bout (Q-AF12) ; un observateur sans
     * l'autorisation ne voit pas le bouton.
     */
    public function test_la_fiche_note_le_visa_meme_dans_un_magasin_ferme(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $ticket = $this->ticketDetaxe($responsable, '2026FR000123456');
        $proprietaire = $this->proprietaireDe($magasin);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);
        app(FermerMagasinAction::class)->handle($proprietaire, Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');

        $this->dansLeMagasin($magasin->fresh(), $observateur);
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('noter_visa_detaxe');

        $this->dansLeMagasin($magasin->fresh(), $proprietaire);
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('À noter dès qu\'il est connu')
            ->callAction('noter_visa_detaxe', ['date_visa' => '2026-10-09'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('noter_visa_detaxe')
            ->assertSee('le 09/10/2026, noté par '.$proprietaire->name);

        $this->assertSame(1, VisaDetaxe::query()->count());
    }

    /**
     * Un ticket détaxé dont le voyageur rend tous les articles n'attend
     * plus aucun visa : aucune marchandise ne sort de l'Union. Il sort de
     * l'« À faire », du filtre « Visa à noter » et des nombres de l'espace
     * de la société, sa fiche ne propose plus de noter le visa, et l'action
     * le refuse. Un ticket dont un seul des deux articles est rendu
     * attend toujours son visa.
     */
    public function test_un_ticket_detaxe_entierement_rendu_n_attend_plus_son_visa(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $rendu = $this->ticketDetaxe($responsable, '2026FR000123456');
        $enPartie = $this->ticketDetaxe($responsable, '2026FR000654321', 2);
        $this->rendre($responsable, $rendu, 1);
        $this->rendre($responsable, $enPartie, 1);

        $chose = $this->chose($responsable, $magasin, 'visas_detaxe');

        $this->assertSame([1, FactureResource::getUrl('view', ['record' => $enPartie])], [$chose?->nombre, $chose?->url]);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_VISA_A_NOTER)
            ->assertCanSeeTableRecords([$enPartie])
            ->assertCanNotSeeTableRecords([$rendu]);
        Livewire::test(ViewFacture::class, ['record' => $rendu->getRouteKey()])->assertActionHidden('noter_visa_detaxe');
        Livewire::test(ViewFacture::class, ['record' => $enPartie->getRouteKey()])->assertActionVisible('noter_visa_detaxe');
        $this->assertThrows(fn () => app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $rendu, '2026-10-09'), LogicException::class, 'Ce ticket ne garde aucun bien taxé, tous ses articles ayant été rendus : aucune marchandise ne sort de l\'Union, et aucun visa n\'est attendu.');
        $this->assertSame(0, VisaDetaxe::query()->count());

        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        $this->assertContains(['groupe' => 'visa de détaxe à noter', 'nombre' => 1, 'montant' => null], app(ChosesAFaireDeLaSociete::class)->parMagasin($proprietaire)[$magasin->id] ?? []);
    }

    /**
     * Isolation : un ticket d'un autre magasin, même de la même société,
     * est introuvable pour l'action ; son « À faire » ne compte que les
     * tickets du magasin actif.
     */
    public function test_le_visa_ne_se_note_et_ne_se_compte_que_dans_le_magasin_actif(): void
    {
        $magasin = $this->magasin();
        $ailleurs = $this->completerIdentiteLegale($this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Paris']));
        $responsableAilleurs = self::responsableDeTest($ailleurs);
        $ticketAilleurs = app(TenantContext::class)->runAs($ailleurs, fn (): Facture => $this->ticketDetaxe($responsableAilleurs, '2026FR000999999'));
        $responsable = self::responsableDeTest($magasin);
        app(TenantContext::class)->set($magasin);

        $this->assertThrows(fn () => app(NoterLeVisaDuBordereauAction::class)->handle($responsable, $ticketAilleurs, '2026-10-09'), LogicException::class, Facture::REFUS_INTROUVABLE);
        $this->assertNull($this->chose($responsable, $magasin, 'visas_detaxe'));
        $this->assertSame(1, $this->chose($responsableAilleurs, $ailleurs, 'visas_detaxe')?->nombre);
        $this->assertSame(0, VisaDetaxe::query()->withoutGlobalScopes()->count());
    }

    /**
     * Le magasin de Lyon, identité légale complète, le 9 octobre 2026 à
     * 10 h 15 à Paris.
     */
    private function magasin(): Magasin
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:00'));

        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon']));
    }

    /**
     * Un ticket émis de 250,00 € TTC par sac à main, de ce nombre de sacs,
     * par cet auteur, dans le magasin actif.
     */
    private function ticket(User $auteur, int $sacs = 1): Facture
    {
        $produit = Produit::factory()->create(['nom' => 'Sac à main', 'prix_vente_ttc' => '250.00', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => $sacs]]));
    }

    /**
     * Un ticket émis de ce nombre de sacs dont cet auteur a noté ce n° de
     * bordereau.
     */
    private function ticketDetaxe(User $auteur, string $bordereau, int $sacs = 1): Facture
    {
        $ticket = $this->ticket($auteur, $sacs);
        app(NoterBordereauDetaxeAction::class)->handle($auteur, $ticket, $bordereau);

        return $ticket;
    }

    /**
     * Le voyageur rend ce nombre de sacs du ticket : un avoir émis par cet
     * auteur sur sa ligne.
     */
    private function rendre(User $auteur, Facture $ticket, int $sacs): void
    {
        app(EnregistrerAvoirAction::class)->handle($auteur, $ticket->client()->firstOrFail(), [['ligne_facture_id' => $ticket->lignes()->sole()->id, 'quantite' => $sacs]], 'Article rendu');
    }

    /**
     * La chose de cette clé dans l'« À faire » de ce compte, dans ce
     * magasin, qui devient le magasin actif.
     */
    private function chose(User $compte, Magasin $magasin, string $cle): ?ChoseAFaire
    {
        $compte = $compte->fresh() ?? $compte;
        $this->dansLeMagasin($magasin->fresh() ?? $magasin, $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin->fresh() ?? $magasin);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', $cle);
    }
}
