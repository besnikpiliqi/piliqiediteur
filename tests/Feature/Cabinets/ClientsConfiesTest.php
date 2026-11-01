<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\ListClientsDuCabinet;
use App\Filament\Cabinet\Resources\CollaborateurResource\Pages\FicheDuCollaborateur;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire d'un cabinet d'expertise comptable confie à chacun de
 * ses collaborateurs les clients liés qu'il suivra (voir
 * ConfierDesClientsAction) : un collaborateur n'ouvre que ceux-là, et
 * l'accès suit aussitôt chaque changement, au JET 130 de chaque magasin
 * réel du client et au journal d'audit du cabinet.
 */
class ClientsConfiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_du_cabinet_confie_et_retire_un_client_et_l_acces_suit_aussitot(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->collaborateur($cabinet);
        $this->assertFalse($collaborateur->peutOuvrir($magasin));

        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(FicheDuCollaborateur::class, ['record' => $collaborateur->getRouteKey()])->callAction('confierDesClients', ['liens' => [(string) $lien->id]]);

        $this->assertTrue($collaborateur->fresh()->peutOuvrir($magasin));
        $this->assertContains('Client confié : '.$magasin->societe->getFilamentName(), $collaborateur->notifications()->get()->pluck('data.title')->all());
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::ClientsConfiesModifies));

        Livewire::test(ListClientsDuCabinet::class)->callTableAction('confier', $lien, ['collaborateurs' => []]);

        $this->assertFalse($collaborateur->fresh()->peutOuvrir($magasin));
        $this->assertSame(0, DB::table('clients_confies')->count());
        $this->assertNotContains('Client confié : '.$magasin->societe->getFilamentName(), $collaborateur->notifications()->get()->pluck('data.title')->all());
        $this->assertContains('Client retiré', $collaborateur->notifications()->get()->pluck('data.title')->all());
    }

    public function test_on_ne_confie_qu_un_client_lie_a_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Cliente de l\'autre']);
        $termine = $this->lierLeCabinet($cabinet, $magasin);
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $termine);
        $etranger = $this->lierLeCabinet($autreCabinet, $autre);
        $collaborateur = $this->collaborateur($cabinet);

        foreach ([$termine, $etranger] as $lien) {
            $this->assertThrows(fn () => app(ConfierDesClientsAction::class)->handle($this->proprietaireDuCabinet($cabinet), $collaborateur, [$lien->id]), LogicException::class);
        }

        $maintenant = now();
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert(['societe_id' => $cabinet->id, 'lien_id' => $etranger->id, 'user_id' => $collaborateur->id, 'confie_par' => $this->proprietaireDuCabinet($cabinet)->id, 'confie_par_nom' => 'x', 'created_at' => $maintenant])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert(['societe_id' => $cabinet->id, 'lien_id' => $termine->id, 'user_id' => $collaborateur->id, 'confie_par' => $this->proprietaireDuCabinet($cabinet)->id, 'confie_par_nom' => 'x', 'created_at' => $maintenant])), QueryException::class, GardesDesCabinets::CLIENTS_CONFIES);
        $this->assertSame(0, DB::table('clients_confies')->count());
    }

    public function test_confier_suspendre_reactiver_retirer_un_collaborateur_inscrit_le_jet_130_dans_chaque_magasin_reel_du_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premier = $this->ouvrirUneSociete(['nom' => 'Première cliente']);
        $secondMagasin = $this->ouvrirUnAutreMagasin($premier, ['nom' => 'Seconde boutique de la première']);
        $deuxieme = $this->ouvrirUneSociete(['nom' => 'Deuxième cliente']);
        $this->lierLeCabinet($cabinet, $premier);
        $this->lierLeCabinet($cabinet, $deuxieme);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$premier, $deuxieme]);

        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $collaborateur);
        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $collaborateur);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $collaborateur);

        foreach ([$premier, $secondMagasin, $deuxieme] as $magasin) {
            $siens = array_values(array_filter($this->modificationsDesDroits($magasin), fn (array $details): bool => $details['membre']['id'] === $collaborateur->id));
            $this->assertSame([
                [null, 'actif'],
                ['actif', 'suspendu'],
                ['suspendu', 'actif'],
                ['actif', null],
            ], array_map(fn (array $details): array => [$details['avant']['statut'] ?? null, $details['apres']['statut'] ?? null], $siens), $magasin->nom);
            $this->assertSame('collaborateur', $siens[0]['apres']['role_societe']);
        }
        $this->assertSame(0, DB::table('clients_confies')->count());
    }

    public function test_un_collaborateur_ne_confie_rien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $premier = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $second = $this->collaborateur($cabinet, 'Inès Garnier');

        $this->assertThrows(fn () => app(ConfierDesClientsAction::class)->handle($premier, $second, [$lien->id]), AuthorizationException::class);
        $this->assertFalse($premier->can('confierDesClients', $second));
        $this->assertFalse($second->fresh()->peutOuvrir($magasin));
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->count());
    }
}
