<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le nom qu'un compte d'un cabinet d'expertise comptable laisse dans
 * l'historique d'un client (voir User::nomPourLHistorique()) : son nom,
 * avec celui de son cabinet au moment de l'opération, jamais plus long
 * que les colonnes qui le gardent.
 */
class NomPourLHistoriqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_nom_fige_d_un_compte_de_cabinet_porte_le_nom_du_cabinet_au_moment_de_l_operation(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::FACTURE_ACHAT_CREER]);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $this->dansLeMagasin($magasin, $collaborateur);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($collaborateur, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 1, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);

        app(TenantContext::class)->runAsSociete($cabinet, fn (): bool => $cabinet->forceFill(['raison_sociale' => 'NOUVEAU NOM DU CABINET'])->save());
        $collaborateur->forceFill(['name' => 'Paul Renommé'])->save();

        $this->assertSame("Paul Lefèvre (cabinet SOCIETE FICTIVE {$cabinet->siren})", $factureAchat->fresh()->cree_par_nom);
        $this->assertSame('Paul Renommé (cabinet NOUVEAU NOM DU CABINET)', User::nomFigeDe($collaborateur->id));
    }

    public function test_le_journal_d_audit_du_client_nomme_le_collaborateur_avec_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $lien);

        $entree = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::LienTermine->value)->sole());
        $this->assertSame("Claire Martin (cabinet {$cabinet->raison_sociale})", $entree->auteur_libelle);
    }

    public function test_le_nom_pour_l_historique_ne_depasse_jamais_255_caracteres(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->runAsSociete($cabinet, fn (): bool => $cabinet->forceFill(['raison_sociale' => 'CABINET '.Str::repeat('D', 192)])->save());
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE]);
        $collaborateur = $this->confier($this->collaborateur($cabinet, 'Paul '.Str::repeat('L', 250)), [$magasin]);

        $nom = $collaborateur->fresh()->nomPourLHistorique();

        $this->assertSame(255, mb_strlen($nom));
        $this->assertStringStartsWith('Paul LLL', $nom);
        $this->assertStringContainsString(' (cabinet CABINET DDD', $nom);
        $this->dansLeMagasin($magasin, $collaborateur);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($collaborateur, app(CreerFactureAchatAction::class)->handle($collaborateur, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 1, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']));
        $this->assertSame($nom, $factureAchat->fresh()->cree_par_nom);
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), LienCabinet::query()->withoutGlobalScopes()->sole());
        $this->assertLessThanOrEqual(255, mb_strlen((string) app(TenantContext::class)->runAsSociete($magasin->societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::LienTermine->value)->value('auteur_libelle'))));
    }

    /**
     * Une dénomination courte reste entière, sans « … » : seul le nom trop
     * long se raccourcit, et le nom pour l'historique fait exactement 255
     * caractères.
     */
    public function test_une_denomination_courte_reste_entiere_et_seul_un_nom_trop_long_se_raccourcit(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->runAsSociete($cabinet, fn (): bool => $cabinet->forceFill(['raison_sociale' => 'ABC'])->save());
        $collaborateur = $this->collaborateur($cabinet, 'Paul '.Str::repeat('L', 245));

        $nom = $collaborateur->fresh()->nomPourLHistorique();

        $this->assertSame('Paul '.Str::repeat('L', 235).'… (cabinet ABC)', $nom);
        $this->assertSame(255, mb_strlen($nom));
    }

    /**
     * Le nom figé d'un expert-comptable de la société porte le cabinet
     * déclaré (décision de l'éditeur du 01/11/2026), tel qu'il était au
     * moment de l'opération, et se lit aussi sans aucun contexte (voir
     * User::nomFigeDe()).
     */
    public function test_le_nom_fige_d_un_expert_comptable_de_la_societe_porte_le_cabinet_declare_et_se_lit_sans_contexte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $expert = $this->expertComptable($magasin, autorisations: PermissionRegistry::casesDuCabinet([...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::FACTURE_ACHAT_CREER]));
        $this->dansLeMagasin($magasin, $expert);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($expert, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 1, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);
        app(TenantContext::class)->clear();
        $expert->forceFill(['name' => 'Marc Renommé'])->save();

        $this->assertSame('Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)', app(TenantContext::class)->runAs($magasin, fn (): ?string => $factureAchat->fresh()?->cree_par_nom));
        $this->assertSame('Marc Renommé (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)', User::nomFigeDe($expert->id));
    }
}
