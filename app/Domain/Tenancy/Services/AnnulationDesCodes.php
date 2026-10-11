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
 * CodeConfirmation) : le SIREN de sa société corrigé avant la validation
 * (le pli est parti vers le siège de l'ancien), sa société refusée, ou son
 * remplacement par l'éditeur, qui doit faire partir le code d'une autre
 * inscription du même SIREN. Le code s'annule dans sa société, et
 * l'annulation s'inscrit à son journal d'audit avec sa raison. Le SIREN et
 * le motif du code sont libérés : un autre code peut partir.
 */
class AnnulationDesCodes
{
    public const SIREN_CORRIGE = 'siren_corrige';

    public const SOCIETE_REFUSEE = 'societe_refusee';

    public const REMPLACE_PAR_L_EDITEUR = 'remplace_par_l_editeur';

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
