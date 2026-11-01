<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qui voit et gère quels comptes de la société, dans son espace
 * d'administration (voir MembrePolicy et PorteeDesDroits) : le propriétaire
 * tous, sauf le sien ; un administrateur autorisé à nommer les
 * responsables, les seuls responsables de ses magasins ; personne d'autre.
 * Les administrateurs se gèrent par le propriétaire seul ; les
 * collaborateurs d'un cabinet, par le propriétaire du cabinet seul.
 */
class MembrePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_voit_et_gere_tous_les_comptes_sauf_le_sien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $comptes = [
            $this->membre($magasin, RoleMagasin::Vendeur),
            $this->membre($magasin, RoleMagasin::Observateur),
            $this->responsable($magasin),
            $this->administrateur($magasin),
        ];
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($magasin->societe);

        $this->assertTrue($proprietaire->can('viewAny', User::class));
        $this->assertTrue($proprietaire->can('create', User::class));

        foreach ($comptes as $compte) {
            $this->assertTrue($proprietaire->can('view', $compte), $compte->name);
            $this->assertTrue($proprietaire->can('update', $compte), $compte->name);
            $this->assertTrue($proprietaire->can('suspendreOuReactiver', $compte), $compte->name);
            $this->assertTrue($proprietaire->can('retirerDeLaSociete', $compte), $compte->name);
            $this->assertTrue($proprietaire->can('redonnerLAcces', $compte), $compte->name);
            $this->assertFalse($proprietaire->can('delete', $compte), $compte->name);
        }

        $this->assertTrue($proprietaire->can('view', $proprietaire));
        foreach (['update', 'suspendreOuReactiver', 'retirerDeLaSociete', 'redonnerLAcces', 'delete'] as $geste) {
            $this->assertFalse($proprietaire->can($geste, $proprietaire), $geste);
        }
    }

    /**
     * Un administrateur autorisé à nommer les responsables voit les seuls
     * responsables des magasins de sa portée : ni les vendeurs, ni les
     * observateurs, ni un responsable d'un magasin hors de sa portée, ni un
     * autre administrateur, ni le propriétaire. Sans
     * l'autorisation, il ne voit aucun compte.
     */
    public function test_un_administrateur_autorise_ne_voit_que_les_responsables_de_sa_portee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $autorise = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $sansAutorisation = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        $responsable = $this->responsable($magasin);
        $responsableAilleurs = $this->responsable($horsPortee);
        $autres = [
            $this->membre($magasin, RoleMagasin::Vendeur),
            $this->membre($magasin, RoleMagasin::Observateur),
            $responsableAilleurs,
            $sansAutorisation,
            $this->proprietaireDe($magasin),
        ];
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($magasin->societe);

        $this->assertTrue($autorise->can('viewAny', User::class));
        $this->assertTrue($autorise->can('view', $responsable));
        $this->assertTrue($autorise->can('update', $responsable));
        $this->assertTrue($autorise->can('retirerDeLaSociete', $responsable));
        $this->assertFalse($autorise->can('suspendreOuReactiver', $responsable));

        foreach ($autres as $compte) {
            $this->assertFalse($autorise->can('view', $compte), $compte->name);
            $this->assertFalse($autorise->can('update', $compte), $compte->name);
        }

        $this->assertFalse($sansAutorisation->can('viewAny', User::class));
        $this->assertFalse($sansAutorisation->can('view', $responsable));
        $this->assertFalse($sansAutorisation->can('view', $responsableAilleurs));
    }

    /**
     * Créer, modifier, suspendre, retirer un administrateur : le
     * propriétaire seul. Un administrateur, même de tous les magasins et
     * autorisé à tout, ni un responsable ne les créent ni ne les gèrent.
     */
    public function test_seul_le_proprietaire_cree_et_gere_les_administrateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $autreAdministrateur = $this->administrateur($magasin);
        $responsable = $this->responsable($magasin);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($magasin->societe);

        $this->assertTrue($proprietaire->can('creerUnAdministrateur', User::class));
        $this->assertTrue($proprietaire->can('modifierUnAdministrateur', $autreAdministrateur));

        foreach ([$administrateur, $responsable] as $compte) {
            $this->assertFalse($compte->can('creerUnAdministrateur', User::class), $compte->name);
            $this->assertFalse($compte->can('modifierUnAdministrateur', $autreAdministrateur), $compte->name);
            $this->assertFalse($compte->can('suspendreOuReactiver', $autreAdministrateur), $compte->name);
        }
    }

    /**
     * Le propriétaire d'un cabinet d'expertise comptable, et lui seul, crée,
     * voit, suspend, réactive, retire et réintègre ses collaborateurs, leur
     * redonne l'accès et leur confie des clients ; ni un autre
     * collaborateur, ni le propriétaire d'un client lié, ni un
     * administrateur de ce client ne les voient ni ne les gèrent.
     */
    public function test_seul_le_proprietaire_du_cabinet_voit_et_gere_ses_collaborateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cabinet = $this->ouvrirUnCabinet();
        $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $autreCollaborateur = $this->collaborateur($cabinet, 'Marc Girard');
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        $administrateurDuClient = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($cabinet);

        $this->assertTrue($proprietaireDuCabinet->can('creerUnCollaborateur', User::class));
        $this->assertFalse($proprietaireDuCabinet->can('creerUnAdministrateur', User::class));
        foreach (['view', 'suspendreOuReactiver', 'retirerDeLaSociete', 'redonnerLAcces', 'confierDesClients'] as $geste) {
            $this->assertTrue($proprietaireDuCabinet->can($geste, $collaborateur), $geste);
        }

        foreach ([$autreCollaborateur, $this->proprietaireDe($magasin), $administrateurDuClient] as $compte) {
            $this->assertFalse($compte->can('creerUnCollaborateur', User::class), $compte->name);
            foreach (['view', 'suspendreOuReactiver', 'retirerDeLaSociete', 'redonnerLAcces', 'confierDesClients'] as $geste) {
                $this->assertFalse($compte->can($geste, $collaborateur), "{$compte->name} : {$geste}");
            }
        }
    }
}
