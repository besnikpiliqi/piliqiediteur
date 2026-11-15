<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FichiersDeTest;

/**
 * Les préparations des tests des charges de la société (lot CHARGES) : une
 * saisie au formulaire, une pièce PDF, et le dépôt d'une charge par la
 * vraie action, avec son disque factice.
 */
trait PrepareDesCharges
{
    protected function disqueDesCharges(): void
    {
        Storage::fake(PiecesDesCharges::DISQUE);
    }

    /**
     * Une saisie de charge, une ligne à ce taux et à ce HT (en euros) ;
     * sans HT, la pièce seule.
     *
     * @param  array<string, mixed>  $champs
     */
    protected function saisie(string $type = 'piliqi:loyer', ?string $ht = '1200.00', int $taux = 2000, array $champs = []): SaisieDeCharge
    {
        return SaisieDeCharge::duFormulaire([
            'type' => $type,
            'fournisseur' => 'SCI des Halles',
            'numero_piece' => null,
            'date_piece' => '2026-05-02',
            'lignes' => $ht === null ? [] : [['taux' => $taux, 'ht' => $ht]],
            ...$champs,
        ]);
    }

    protected function piece(string $nom = 'quittance.pdf'): PieceDeposee
    {
        return new PieceDeposee($nom, FichiersDeTest::pdf('Quittance de loyer'));
    }

    /**
     * Une charge déposée par ce compte dans cette société, par la vraie
     * action, avec sa pièce ; aucun contexte n'est actif ensuite.
     */
    protected function deposer(User $auteur, Societe $societe, ?SaisieDeCharge $saisie = null, bool $avecLaPiece = true): Charge
    {
        app(TenantContext::class)->clear();
        $charge = app(DeposerUneChargeAction::class)->handle($auteur, $societe, $saisie ?? $this->saisie(), $avecLaPiece ? $this->piece() : null);
        app(TenantContext::class)->clear();

        return Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey());
    }
}
