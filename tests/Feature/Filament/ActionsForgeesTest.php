<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MagasinResource\Pages\ModifierMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Une requête forgée (Livewire appelé comme si le bouton était là) ne
 * fait rien de ce que l'écran n'offre pas : une action masquée ne se
 * monte pas, une clé d'un autre magasin ou d'un collègue ne désigne aucun
 * enregistrement.
 */
class ActionsForgeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([EnvoyerDocumentJob::class]);
    }

    public function test_une_cle_d_un_autre_magasin_ou_d_un_collegue_dans_une_action_de_table_ou_de_masse_ne_monte_rien(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->set($lyon);
        $collegue = $this->membre($lyon, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_ENVOYER]);
        $vendeur = $this->membre($lyon, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_ENVOYER]);
        $brouillonDuCollegue = app(CreerFactureAction::class)->handle($collegue, Client::factory()->create(), $this->lignes());
        $ticketDuCollegue = app(EmettreFactureAction::class)->handle($collegue, app(CreerFactureAction::class)->handle($collegue, Client::factory()->create(), $this->lignes()));
        [$brouillonDeVilleurbanne, $devisDeVilleurbanne, $vendeurDeVilleurbanne] = app(TenantContext::class)->runAs($villeurbanne, function () use ($villeurbanne): array {
            $proprietaire = $this->proprietaireDe($villeurbanne);

            return [
                app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()),
                app(CreerDevisAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()),
                $this->membre($villeurbanne, RoleMagasin::Vendeur),
            ];
        });

        $this->dansLeMagasin($lyon, $vendeur);
        $factures = Livewire::test(ListFactures::class);
        $this->forgerLActionDeLigne($factures, 'emettre', $brouillonDuCollegue);
        $this->forgerLActionDeLigne($factures, 'envoyer_par_email', $ticketDuCollegue, ['destinataire' => 'client@exemple.fr']);
        $this->forgerLActionDeLigne($factures, 'emettre', $brouillonDeVilleurbanne);

        $this->dansLeMagasin($lyon, $this->proprietaireDe($lyon));
        Livewire::test(ListDevis::class)->call('mountTableBulkAction', 'delete', [$devisDeVilleurbanne->getKey()])->call('callMountedTableBulkAction');
        $equipe = Livewire::test(ListEquipeDuMagasin::class);
        $affectationDeVilleurbanne = Affectation::query()->where('user_id', $vendeurDeVilleurbanne->id)->sole();
        $this->forgerLActionDeLigne($equipe, 'retirer_du_magasin', $affectationDeVilleurbanne);
        $this->forgerLActionDeLigne($equipe, 'suspendre', $affectationDeVilleurbanne);

        $this->assertSame([StatutFacture::Brouillon, StatutFacture::Brouillon], [$brouillonDuCollegue->fresh()->statut, Facture::withoutGlobalScope(MagasinScope::class)->findOrFail($brouillonDeVilleurbanne->id)->statut]);
        $this->assertSame(0, EnvoiDocument::withoutGlobalScope(MagasinScope::class)->count());
        $this->assertNotNull(Devis::withoutGlobalScope(MagasinScope::class)->find($devisDeVilleurbanne->id));
        $this->assertTrue($vendeurDeVilleurbanne->fresh()->estActif());
        $this->assertSame(1, Affectation::query()->where('user_id', $vendeurDeVilleurbanne->id)->count());
    }

    /**
     * Une donnée par page : qui la visite, l'action masquée pour lui (ou la
     * page elle-même, qui lui est fermée), ce qu'il y envoie, et ce qui
     * prouve que rien n'a été fait.
     *
     * @return array<string, array{0: string}>
     */
    public static function actionsMasquees(): array
    {
        return array_combine($noms = [
            'émettre une facture',
            'envoyer un document',
            'clôturer la journée',
            'corriger le SIREN',
            'ouvrir la démonstration',
            'transférer la propriété',
            'créer un vendeur',
            'nommer un responsable',
            'créer l\'expert-comptable',
            'fermer un magasin',
            'rouvrir un magasin',
        ], array_map(static fn (string $nom): array => [$nom], $noms));
    }

    #[DataProvider('actionsMasquees')]
    public function test_une_action_d_en_tete_masquee_appelee_directement_ne_fait_rien(string $nom): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => in_array($nom, ['corriger le SIREN', 'ouvrir la démonstration'], true)]);
        $societe = $magasin->societe;

        $rienNAEteFait = match ($nom) {
            'émettre une facture' => (function () use ($magasin): Closure {
                $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::FACTURE_EMETTRE])));
                $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes());
                $this->dansLeMagasin($magasin, $vendeur);
                $this->forgerLActionDeLigne(Livewire::test(ListFactures::class)->assertTableActionHidden('emettre', $brouillon), 'emettre', $brouillon);

                return fn (): bool => $brouillon->fresh()->statut === StatutFacture::Brouillon;
            })(),
            'envoyer un document' => (function () use ($magasin): Closure {
                $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::FACTURE_ENVOYER])));
                $ticket = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes()));
                $this->dansLeMagasin($magasin, $vendeur);
                $this->forgerLAction(Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('envoyer_par_email'), 'envoyer_par_email', ['destinataire' => 'client@exemple.fr']);

                return fn (): bool => EnvoiDocument::query()->doesntExist();
            })(),
            'clôturer la journée' => (function () use ($magasin): Closure {
                $this->vendreParLeProprietaire($magasin);
                $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CLOTURE_CAISSE_VIEW]);
                $this->dansLeMagasin($magasin, $vendeur);
                $this->forgerLAction(Livewire::test(ListClotureCaisses::class)->assertActionHidden('cloturer'), 'cloturer');

                return fn (): bool => ClotureCaisse::query()->doesntExist();
            })(),
            'corriger le SIREN' => (function () use ($magasin, $societe): Closure {
                $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
                $siren = $societe->siren;
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                $this->forgerLAction(Livewire::test(IdentiteSociete::class)->assertActionHidden('corrigerLeSiren'), 'corrigerLeSiren', ['siren' => '732829320']);

                return fn (): bool => $societe->fresh()->siren === $siren;
            })(),
            'ouvrir la démonstration' => (function () use ($magasin, $societe): Closure {
                $administrateur = $this->administrateur($magasin);
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                $this->forgerLAction(Livewire::test(TableauDeBord::class)->assertActionHidden('ouvrirLaDemonstration'), 'ouvrirLaDemonstration');

                return fn (): bool => $societe->demonstration()->doesntExist();
            })(),
            'transférer la propriété' => (function () use ($magasin, $societe): Closure {
                $proprietaire = $this->proprietaireDe($magasin);
                $administrateur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
                $responsable = $this->responsable($magasin);
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $responsable->getRouteKey()])->assertActionHidden('transmettreLaPropriete'), 'transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_CHOISI]);

                return fn (): bool => $this->proprietaireDe($magasin)->is($proprietaire) && $administrateur->fresh()->estAdministrateur() && $responsable->fresh()->estMembre();
            })(),
            'créer un vendeur' => (function () use ($magasin): Closure {
                $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
                $this->dansLeMagasin($magasin, $vendeur);
                Livewire::test(ListEquipeDuMagasin::class)->assertForbidden();

                return fn (): bool => User::query()->where('email', 'paul@example.test')->doesntExist();
            })(),
            'nommer un responsable' => (function () use ($magasin, $societe): Closure {
                $administrateur = $this->administrateur($magasin);
                $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                Livewire::test(ListMembres::class)->assertForbidden();

                return fn (): bool => Affectation::query()->where('user_id', $vendeur->id)->sole()->role === RoleMagasin::Vendeur;
            })(),
            'créer l\'expert-comptable' => (function () use ($magasin, $societe): Closure {
                $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                $this->forgerLAction(Livewire::test(ListMembres::class)->assertActionHidden('creerUnExpertComptable'), 'creerUnExpertComptable', ['name' => 'Cabinet', 'email' => 'cabinet@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE]);

                return fn (): bool => User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->doesntExist();
            })(),
            'fermer un magasin' => (function () use ($magasin, $societe): Closure {
                $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
                $this->dansLEspaceDeLaSociete($societe, $administrateur);
                $this->forgerLAction(Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertActionHidden('ceder'), 'ceder', ['confirmation' => 'Ma Boutique', 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI]);

                return fn (): bool => $magasin->fresh()->statut === StatutMagasin::Actif && app(TenantContext::class)->runAs($magasin, fn (): bool => MouvementStock::query()->doesntExist());
            })(),
            'rouvrir un magasin' => (function () use ($magasin, $societe): Closure {
                app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');
                $this->dansLEspaceDeLaSociete($societe, $this->proprietaireDe($magasin));
                $this->forgerLAction(Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertActionHidden('rouvrir'), 'rouvrir', ['mot_de_passe' => 'password']);

                return fn (): bool => $magasin->fresh()->aEteCede() && PeriodeActivite::query()->where('magasin_id', $magasin->id)->count() === 1;
            })(),
        };

        $this->assertTrue($rienNAEteFait());
    }

    /**
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private function vendreParLeProprietaire(Magasin $magasin): Facture
    {
        $proprietaire = $this->proprietaireDe($magasin);

        return app(EmettreFactureAction::class)->handle($proprietaire, app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()));
    }
}
