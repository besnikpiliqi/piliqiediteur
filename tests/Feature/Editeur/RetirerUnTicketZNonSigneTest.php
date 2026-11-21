<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\RetirerUnTicketZNonSigneAction;
use App\Domain\Facturation\Exceptions\TicketZNonSigneException;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Tests\TestCase;

/**
 * Le retrait par l'éditeur, sur pièces, d'un ticket Z que rien ne signe
 * (voir RetirerUnTicketZNonSigneAction) : écrit directement dans la base,
 * il arrête la clôture du magasin ; deux opérateurs le retirent, les
 * documents qu'on y avait rattachés s'en détachent, le retrait s'inscrit
 * au journal des événements du magasin et au journal d'audit, puis le
 * vrai ticket Z de sa journée est produit, et la base accepte l'état qui
 * en résulte. Un ticket Z signé ne se retire jamais, ni par la procédure
 * ni par une requête directe. Le temps est figé à une heure de Paris.
 */
class RetirerUnTicketZNonSigneTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Ticket Z écrit en base hors de la clôture, rapport d\'incident joint';

    private const PIECE = 'INCIDENT-2026-0042';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
    }

    /**
     * Le 05/10 est clos ; un faux ticket Z du 06/10, auquel on a rattaché
     * le ticket vendu ce jour-là, arrête la clôture. La commande de deux
     * opérateurs le retire, détache le ticket, et la clôture produit le
     * vrai ticket Z du 06/10, sous le même numéro, qui compte ce ticket,
     * puis celui du 07/10 ; le retrait s'inscrit au journal des événements
     * (JET 270) et au journal d'audit, avec l'intervention de l'éditeur.
     */
    public function test_la_procedure_retire_le_ticket_z_non_signe_puis_produit_le_vrai_ticket_z_de_sa_journee(): void
    {
        [$magasin, $fauxNumero, $ticketDu6] = $this->magasinArreteParUnFauxTicketZ();

        $this->retirerParLaCommande($magasin, $fauxNumero)
            ->expectsOutputToContain("Ticket Z {$fauxNumero} retiré du magasin BOUT (1 document(s) détaché(s)) ; tickets Z produits : Z-BOUT-2026-000002, Z-BOUT-2026-000003.")
            ->assertSuccessful();

        app(TenantContext::class)->runAs($magasin, function () use ($ticketDu6): void {
            $this->assertSame([['2026-10-05', 'Z-BOUT-2026-000001'], ['2026-10-06', 'Z-BOUT-2026-000002'], ['2026-10-07', 'Z-BOUT-2026-000003']], ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture, $cloture->numero])->all());
            $vraiDu6 = ClotureCaisse::query()->where('date_cloture', '2026-10-06')->sole();
            $this->assertSame([$vraiDu6->id, 1], [$ticketDu6->fresh()?->cloture_caisse_id, $vraiDu6->nombre_ventes]);
            $this->assertSame(
                [['parametre' => 'ticket_z_non_signe', 'magasin' => ['id' => $vraiDu6->magasin_id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => ['numero' => 'Z-BOUT-2026-000002', 'journee' => '2026-10-06', 'documents_detaches' => 1], 'nouvelle_valeur' => null]],
                EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', (string) CodeEvenement::ParametreDeConformite->value)->get()->map(fn (EnregistrementFiscal $evenement): mixed => $evenement->contenuSigne()['donnees']['details'])->filter(fn (mixed $details): bool => is_array($details) && ($details['parametre'] ?? null) === 'ticket_z_non_signe')->values()->all(),
            );
        });
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::TicketZNonSigneRetire);
        $this->assertSame(['editeur', self::JUSTIFICATION, ['alice', 'bruno'], ['documents_detaches' => 1, 'journee' => '2026-10-06', 'numero' => 'Z-BOUT-2026-000002']], [$entree->origine, $entree->justification, $entree->operateur['operateurs'], $entree->anciennes_valeurs]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * Un ticket Z signé par la clôture de sa journée ne se retire jamais :
     * ni par la procédure, ni par une requête directe ; le lien d'un
     * document à lui ne se défait pas. Seuls un ticket Z que rien ne
     * signe et le lien d'un document à lui le peuvent, dans la base.
     */
    public function test_seul_un_ticket_z_que_rien_ne_signe_se_retire(): void
    {
        [$magasin, $fauxNumero, $ticketDu6] = $this->magasinArreteParUnFauxTicketZ();
        $signe = app(TenantContext::class)->runAs($magasin, fn (): ClotureCaisse => ClotureCaisse::query()->where('numero', 'Z-BOUT-2026-000001')->sole());
        $ticketDu5 = app(TenantContext::class)->runAs($magasin, fn (): Facture => Facture::query()->where('cloture_caisse_id', $signe->id)->sole());

        $this->assertThrows(fn () => app(RetirerUnTicketZNonSigneAction::class)->handle($magasin->fresh(), 'Z-BOUT-2026-000001', $this->editeur()), LogicException::class, RetirerUnTicketZNonSigneAction::MESSAGE_SIGNE);
        $this->assertThrows(fn () => app(RetirerUnTicketZNonSigneAction::class)->handle($magasin->fresh(), 'Z-BOUT-2026-000099', $this->editeur()), LogicException::class, RetirerUnTicketZNonSigneAction::MESSAGE_INTROUVABLE);
        $this->assertThrows(fn () => app(RetirerUnTicketZNonSigneAction::class)->handle($magasin->fresh(), $fauxNumero, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice'])), LogicException::class, RetirerUnTicketZNonSigneAction::MESSAGE_DEUX_OPERATEURS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clotures_caisse')->where('id', $signe->id)->delete()), QueryException::class, 'clotures_caisse : table en ajout seul, suppression refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $ticketDu5->id)->update(['cloture_caisse_id' => null])), QueryException::class, 'factures.cloture_caisse_id : valeur figée, changement refusé');
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::TicketZNonSigneRetire));

        $this->dansUnPointDeSauvegarde(function () use ($ticketDu6, $fauxNumero): void {
            $this->assertSame(1, DB::table('factures')->where('id', $ticketDu6->id)->update(['cloture_caisse_id' => null]));
            $this->assertSame(1, DB::table('clotures_caisse')->where('numero', $fauxNumero)->delete());
        })();
    }

    /**
     * Sans la confirmation du numéro retapée, avec le même opérateur deux
     * fois ou un magasin inconnu dans la société, rien ne change.
     */
    public function test_la_commande_refuse_sans_confirmation_ni_deux_operateurs_distincts(): void
    {
        [$magasin, $fauxNumero] = $this->magasinArreteParUnFauxTicketZ();
        $parametres = ['magasin' => 'bout', 'numero' => $fauxNumero, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => $fauxNumero];

        $this->lancerUneProcedureDeLEditeur('editeur:magasin:retirer-ticket-z', $magasin->societe, [...$parametres, '--confirmer' => $magasin->societe->siren], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:retirer-ticket-z', $magasin->societe, $parametres, [['alice', 'secret-d-alice'], ['alice', 'secret-d-alice']], 2)
            ->expectsOutputToContain('Cette procédure exige deux opérateurs distincts : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:retirer-ticket-z', $magasin->societe, [...$parametres, 'magasin' => 'AILLEURS'], [['alice', 'secret-d-alice'], ['bruno', 'secret-de-bruno']], 2)
            ->expectsOutputToContain('Ce magasin n\'existe pas dans cette société : donnez son code.')
            ->assertFailed();

        $this->assertSame(1, DB::table('clotures_caisse')->where('magasin_id', $magasin->id)->where('numero', $fauxNumero)->count());
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::TicketZNonSigneRetire));
    }

    /**
     * Un magasin qui a vendu le 05/10, clos ce jour-là à minuit, puis un
     * ticket vendu le 06/10, rattaché à un faux ticket Z du 06/10 écrit
     * directement dans la base sous le numéro suivant : au 08/10, la
     * clôture s'arrête sur lui.
     *
     * @return array{0: Magasin, 1: string, 2: Facture}
     */
    private function magasinArreteParUnFauxTicketZ(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $client = Client::factory()->create();
        self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->travelTo(self::aParis('2026-10-06 00:00:30'));
        app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $ticketDu6 = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 2]]);
        $fauxNumero = 'Z-BOUT-2026-000002';
        $faux = $this->insererUnFauxTicketZ($magasin, '2026-10-06', $fauxNumero, self::aParis('2026-10-07 00:00:30'));
        DB::table('factures')->where('id', $ticketDu6->id)->update(['cloture_caisse_id' => $faux]);
        $this->travelTo(self::aParis('2026-10-08 00:00:30'));
        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-07'), TicketZNonSigneException::class);
        app(TenantContext::class)->clear();

        return [$magasin, $fauxNumero, $ticketDu6];
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice', 'bruno']);
    }

    private function retirerParLaCommande(Magasin $magasin, string $numero): PendingCommand
    {
        return $this->lancerUneProcedureDeLEditeur('editeur:magasin:retirer-ticket-z', $magasin->societe, [
            'magasin' => (string) $magasin->code,
            'numero' => $numero,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $numero,
        ], [['alice', 'secret-d-alice'], ['bruno', 'secret-de-bruno']], 2);
    }
}
