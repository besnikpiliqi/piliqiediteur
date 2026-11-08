<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Policies\AffectationPolicy;
use App\Policies\AvoirFournisseurPolicy;
use App\Policies\AvoirPolicy;
use App\Policies\ClotureCaissePersonnellePolicy;
use App\Policies\ClotureCaissePolicy;
use App\Policies\EntreeAuditPolicy;
use App\Policies\FactureAchatPolicy;
use App\Policies\FacturePolicy;
use App\Policies\InventaireOuverturePolicy;
use App\Policies\InvitationCabinetPolicy;
use App\Policies\MagasinDeLaSocietePolicy;
use App\Policies\MembrePolicy;
use App\Policies\PaiementPolicy;
use App\Policies\PosteReconnuPolicy;
use App\Policies\RecuPaiementPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Les gestes qu'aucun compte ne fait jamais, pas même le propriétaire, qui
 * a pourtant tous les droits sauf celui de vendre : un document émis, un
 * paiement, une clôture ou un relevé de caisse ne se modifie, ne se
 * supprime, ne se restaure ni ne se duplique ; un magasin et un compte ne
 * se suppriment jamais (CLAUDE.md, « Société ou magasin suspendu, fermé »
 * et « Comptes et équipe ») ; le journal d'audit ne s'écrit pas à la main.
 */
class GestesToujoursRefusesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function gestes(): array
    {
        $gestes = [
            AffectationPolicy::class => ['update', 'deleteAny', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
            AvoirFournisseurPolicy::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'restore'],
            AvoirPolicy::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'restore'],
            ClotureCaissePersonnellePolicy::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'restore'],
            ClotureCaissePolicy::class => ['update', 'delete', 'deleteAny', 'forceDelete', 'restore'],
            EntreeAuditPolicy::class => ['create', 'deleteAny'],
            FactureAchatPolicy::class => ['forceDelete', 'restore'],
            FacturePolicy::class => ['restore'],
            InventaireOuverturePolicy::class => ['forceDelete', 'restore'],
            InvitationCabinetPolicy::class => ['delete'],
            MagasinDeLaSocietePolicy::class => ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
            MembrePolicy::class => ['deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
            PaiementPolicy::class => ['delete', 'deleteAny', 'forceDelete', 'restore'],
            PosteReconnuPolicy::class => ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate'],
            RecuPaiementPolicy::class => ['update', 'delete'],
        ];
        $cas = [];

        foreach ($gestes as $politique => $methodes) {
            foreach ($methodes as $methode) {
                $cas[class_basename($politique).'::'.$methode] = [$politique, $methode];
            }
        }

        return $cas;
    }

    /**
     * @param  class-string  $politique
     */
    #[DataProvider('gestes')]
    public function test_aucun_compte_pas_meme_le_proprietaire_ne_fait_ce_geste(string $politique, string $methode): void
    {
        $magasin = $this->ouvrirUneSociete();
        $comptes = [$this->proprietaireDe($magasin), self::responsableDeTest($magasin)];
        $parametres = array_slice((new ReflectionMethod($politique, $methode))->getParameters(), 1);

        foreach ($comptes as $compte) {
            $fiches = array_map(static function (\ReflectionParameter $parametre) use ($magasin): Model {
                $type = $parametre->getType();
                $classe = $type instanceof ReflectionNamedType ? $type->getName() : Model::class;

                return $classe === User::class ? self::responsableDeTest($magasin) : new $classe;
            }, $parametres);

            $this->assertFalse(app($politique)->{$methode}($compte, ...$fiches), "{$compte->role_societe?->value} : ".class_basename($politique)."::{$methode}");
        }
    }
}
