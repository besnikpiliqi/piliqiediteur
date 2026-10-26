<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Annule, sur une décision, un code de confirmation encore actif (voir
 * CodeConfirmation) : un nouveau courrier du même motif le remplace (le
 * premier code ne vaut plus), ou l'inscription de sa société est close.
 * Le code s'annule dans sa société, et l'annulation s'inscrit à son
 * journal d'audit avec sa raison. Le SIREN et le motif du code sont
 * libérés : un autre code peut partir.
 */
class AnnulationDesCodes
{
    public const NOUVEAU_COURRIER = 'nouveau_courrier';

    public const INSCRIPTION_CLOSE = 'inscription_close';

    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * @param  User|null  $auteur  la personne dont l'action annule le code ; null pour le système ou l'éditeur
     */
    public function annuler(CodeConfirmation $code, string $raison, ?User $auteur = null, ?InterventionDeLEditeur $editeur = null): void
    {
        app(TenantContext::class)->runAsSociete(Societe::query()->findOrFail($code->societe_id), fn () => DB::transaction(function () use ($code, $raison, $auteur, $editeur): void {
            $code->forceFill(['annule_le' => now()])->save();
            $this->journalAudit->inscrire(ActionAuditee::CodeAnnule, $auteur, null, null, ['motif' => $code->motif->value, 'raison' => $raison], $code, $editeur);
        }));
    }
}
