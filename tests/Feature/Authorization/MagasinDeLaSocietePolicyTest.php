<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les gestes sur un magasin de la société, dans son espace
 * d'administration (voir MagasinDeLaSocietePolicy) : le modifier, changer
 * son SIRET, le fermer et le rouvrir, pour qui gère les magasins de sa
 * portée ; le céder, pour le propriétaire seul ; l'indiquer comme
 * établissement principal, avec tous les magasins dans sa portée. Jamais
 * un responsable, un vendeur, l'expert-comptable ni le compte d'une autre
 * société ; jamais le magasin de démonstration ; plus rien dans une
 * société en lecture seule.
 */
class MagasinDeLaSocietePolicyTest extends TestCase
{
    use RefreshDatabase;

    private const GESTES = ['update', 'changerLeSiret', 'fermerLeMagasin', 'rouvrirLeMagasin', 'cederLeMagasin', 'choisirCommePrincipal'];

    public function test_chaque_compte_n_a_que_les_gestes_de_sa_portee_sur_un_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $comptes = [
            'propriétaire' => $this->proprietaireDe($magasin),
            'administrateur de tous les magasins autorisé' => $this->administrateur($magasin, null, AutorisationsSociete::toutes()),
            'administrateur de ce magasin autorisé' => $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]),
            'administrateur d\'un autre magasin autorisé' => $this->administrateur($magasin, [$autre->id], [AutorisationsSociete::MAGASINS_GERER]),
            'administrateur sans l\'autorisation' => $this->administrateur($magasin),
            'responsable' => $this->responsable($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'expert-comptable' => $this->expertComptable($magasin),
            'propriétaire d\'une autre société' => $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ailleurs'])),
        ];
        $this->dansLaSociete($magasin);

        $this->assertSame([
            'propriétaire' => self::gestes(true, true, true),
            'administrateur de tous les magasins autorisé' => self::gestes(true, false, true),
            'administrateur de ce magasin autorisé' => self::gestes(true, false, false),
            'administrateur d\'un autre magasin autorisé' => self::gestes(false, false, false),
            'administrateur sans l\'autorisation' => self::gestes(false, false, false),
            'responsable' => self::gestes(false, false, false),
            'vendeur' => self::gestes(false, false, false),
            'expert-comptable' => self::gestes(false, false, false),
            'propriétaire d\'une autre société' => self::gestes(false, false, false),
        ], array_map(fn (User $compte): array => self::permis($compte, $magasin), $comptes));
    }

    public function test_personne_n_agit_sur_le_magasin_de_demonstration(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $proprietaire = $this->proprietaireDe($magasin);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $magasin->societe);
        $this->dansLaSociete($magasin);

        $this->assertSame(self::gestes(false, false, false), self::permis($proprietaire, $demonstration));
        $this->assertSame(self::gestes(true, true, true), self::permis($proprietaire, $magasin));
    }

    /**
     * Une société en lecture seule (suspendue ici) garde son espace
     * consultable, mais aucun de ses magasins ne se modifie, ne se ferme,
     * ne se rouvre ni ne se cède.
     */
    public function test_une_societe_en_lecture_seule_n_agit_plus_sur_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $this->dansLaSociete($magasin->fresh());

        $this->assertTrue($proprietaire->can('view', $magasin->fresh()));
        $this->assertSame(self::gestes(false, false, false), self::permis($proprietaire, $magasin->fresh()));
    }

    /**
     * Ce que la policy répond pour chaque geste, dans l'ordre de GESTES.
     *
     * @return array<string, bool>
     */
    private static function permis(User $compte, Magasin $magasin): array
    {
        return array_combine(self::GESTES, array_map(fn (string $geste): bool => $compte->fresh()->can($geste, $magasin), self::GESTES));
    }

    /**
     * Les gestes attendus : modifier, changer le SIRET, fermer et rouvrir
     * vont ensemble ; céder et indiquer l'établissement principal à part.
     *
     * @return array<string, bool>
     */
    private static function gestes(bool $gerer, bool $ceder, bool $principal): array
    {
        return [
            'update' => $gerer,
            'changerLeSiret' => $gerer,
            'fermerLeMagasin' => $gerer,
            'rouvrirLeMagasin' => $gerer,
            'cederLeMagasin' => $ceder,
            'choisirCommePrincipal' => $principal,
        ];
    }

    /**
     * L'espace d'administration de la société du magasin, posé sans
     * magasin actif.
     */
    private function dansLaSociete(Magasin $magasin): void
    {
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($magasin->societe);
    }
}
