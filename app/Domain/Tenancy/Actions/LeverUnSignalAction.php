<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;

/**
 * Les décisions de l'éditeur sur un signal de risque (voir
 * editeur:societe:lever-signal et editeur:societe:signaler) : sa levée
 * motivée, qui ne vaut que pour la part « éditeur » de ce que le signal
 * exige (un signal qui exige aussi un courrier reste actif sans lui, voir
 * SignalDeRisque::leveePar()) ; ou un doute de l'éditeur, constaté, qui
 * bloque la validation jusqu'à sa levée. La ligne de la levée cite le
 * signal, sa justification et le SIREN examiné, pour lequel seul elle
 * vaut. La société est validée si la levée le permet.
 */
class LeverUnSignalAction
{
    public function __construct(
        private readonly RegistreDesVerifications $registre,
        private readonly ValiderLaSocieteAction $validerLaSociete,
    ) {}

    /**
     * @throws VerificationRefuseeException
     */
    public function lever(Societe $societe, SignalDeRisque $signal, InterventionDeLEditeur $editeur): VerificationSociete
    {
        $this->refuserSiDejaDecidee($societe);

        $ligne = app(TenantContext::class)->runAsSociete($societe, fn (): VerificationSociete => $this->registre->noter($societe, TypeVerification::Manuelle, ResultatVerification::Concluante, [
            'siren' => $societe->siren,
            'decision' => 'signal_leve',
            'justification' => $editeur->justification,
        ], signal: $signal, editeur: $editeur));

        $this->validerLaSociete->siPossible($societe->fresh(), $editeur);

        return $ligne;
    }

    /**
     * @throws VerificationRefuseeException
     */
    public function signalerUnDoute(Societe $societe, InterventionDeLEditeur $editeur): VerificationSociete
    {
        $this->refuserSiDejaDecidee($societe);

        return app(TenantContext::class)->runAsSociete($societe, fn (): VerificationSociete => $this->registre->noter($societe, TypeVerification::Signal, ResultatVerification::AExaminer, ['constat' => 'doute de l\'éditeur'], signal: SignalDeRisque::DecisionEditeur, editeur: $editeur));
    }

    /**
     * @throws VerificationRefuseeException
     */
    private function refuserSiDejaDecidee(Societe $societe): void
    {
        if ($societe->estValidee() || $societe->estRefusee()) {
            throw new VerificationRefuseeException(RefuserLaSocieteAction::MESSAGE_DEJA_DECIDEE);
        }
    }
}
