<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Le seul écrivain du registre des vérifications d'une société (voir
 * VerificationSociete) : chaque ligne s'inscrit dans la société, avec les
 * seuls détails que retient son type, et au journal d'audit de la société,
 * catégorie des vérifications, dans la même transaction. Une ligne écrite
 * par une procédure de l'éditeur retient ses opérateurs, et son entrée
 * d'audit sa justification.
 */
class RegistreDesVerifications
{
    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * @param  array<string, mixed>|null  $details  les seuls détails que retient son type (voir TypeVerification::detailsRetenus())
     * @param  User|null  $auteur  la personne dont l'action a fait écrire la ligne ; null pour le système ou l'éditeur
     * @param  User|null  $beneficiaire  la personne dont la ligne prouve l'identité ou la qualité
     * @param  SignalDeRisque|null  $signal  le signal que la ligne constate, ou que l'éditeur lève
     */
    public function noter(
        Societe $societe,
        TypeVerification $type,
        ResultatVerification $resultat,
        ?array $details = null,
        ?User $auteur = null,
        ?User $beneficiaire = null,
        ?string $fournisseur = null,
        ?string $reference = null,
        ?SignalDeRisque $signal = null,
        ?Magasin $magasin = null,
        ?InterventionDeLEditeur $editeur = null,
    ): VerificationSociete {
        return app(TenantContext::class)->runAsSociete($societe, fn (): VerificationSociete => DB::transaction(function () use ($type, $resultat, $details, $auteur, $beneficiaire, $fournisseur, $reference, $signal, $magasin, $editeur): VerificationSociete {
            $verification = tap((new VerificationSociete)->forceFill([
                'magasin_id' => $magasin?->getKey(),
                'type' => $type,
                'resultat' => $resultat,
                'fournisseur' => $fournisseur ?? ($editeur === null ? null : 'editeur'),
                'reference' => $reference,
                'signal' => $signal,
                'details' => $details,
                'auteur_id' => $auteur?->getKey(),
                'operateur' => $editeur?->operateur(),
                'beneficiaire_id' => $beneficiaire?->getKey(),
            ]))->save();

            $this->journalAudit->inscrire(ActionAuditee::VerificationEnregistree, $auteur, $magasin, null, [
                'type' => $type->value,
                'resultat' => $resultat->value,
                'fournisseur' => $verification->fournisseur,
                'siret' => $magasin?->siret,
                ...($signal === null ? [] : ['signal' => $signal->value]),
                ...($beneficiaire === null ? [] : ['beneficiaire' => ['id' => $beneficiaire->getKey(), 'nom' => $beneficiaire->name]]),
            ], $verification, $editeur);

            return $verification;
        }));
    }
}
