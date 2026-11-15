<?php

namespace Tests\Browser\Support;

use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Fixtures\FichiersDeTest;

/**
 * Une charge de chaque état dans la société d'essai, pièce jointe, de
 * mars 2026 (voir TestCaseDuNavigateur::societeComplete()) : le loyer
 * validé, l'électricité à valider, le téléphone en pièce sans chiffres et
 * les honoraires renvoyés au client, déposés par son propriétaire et
 * traités par le collaborateur du cabinet lié, par les vraies actions,
 * pour que la page des charges montre chaque couleur de case. Le disque
 * des pièces doit être simulé par le test (Storage::fake). Aucun contexte
 * n'est actif ensuite.
 */
final class ChargesDeChaqueEtat
{
    public static function deposer(SocieteDeTest $societe): void
    {
        $deposer = static fn (array $champs): string => (string) app(DeposerUneChargeAction::class)->handle(
            $societe->proprietaire,
            $societe->societe,
            SaisieDeCharge::duFormulaire(['date_piece' => '2026-03-01', ...$champs]),
            new PieceDeposee('piece.pdf', FichiersDeTest::pdf('Pièce de la charge')),
        )->uuid;
        app(TenantContext::class)->clear();
        $validee = $deposer(['type' => 'piliqi:loyer', 'fournisseur' => 'SCI des Halles', 'numero_piece' => 'Q-2026-03', 'lignes' => [['taux' => '2000', 'ht' => '1200,00']]]);
        $deposer(['type' => 'piliqi:electricite', 'fournisseur' => 'EDF', 'numero_piece' => 'E-2026-03', 'lignes' => [['taux' => '2000', 'ht' => '84,00']]]);
        $deposer(['type' => 'piliqi:telephone_internet', 'fournisseur' => 'Orange Pro']);
        $renvoyee = $deposer(['type' => 'piliqi:honoraires', 'fournisseur' => 'Maître Durand', 'numero_piece' => 'H-12', 'lignes' => [['taux' => '2000', 'ht' => '300,00']]]);
        app(ValiderUneChargeAction::class)->handle($societe->collaborateur, $societe->societe, $validee);
        app(RenvoyerUneChargeAction::class)->handle($societe->collaborateur, $societe->societe, $renvoyee, 'Pièce illisible');
        app(TenantContext::class)->clear();
    }
}
