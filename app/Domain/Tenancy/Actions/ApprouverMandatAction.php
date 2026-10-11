<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\DemandeDIdentite;
use App\Integrations\Identite\IdentiteIndisponibleException;
use App\Integrations\Identite\ResultatDIdentite;
use App\Integrations\Identite\SessionDIdentite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La réponse d'un dirigeant à une demande d'accord (voir
 * DemanderAccordDUnDirigeantAction), depuis le lien qu'il a reçu (voir
 * AccordDirigeantController), sans compte PILIQI.
 *
 * - demarrer() : il vérifie son identité chez le prestataire, dans une
 *   session à usage unique liée à sa session par son state, et confirme «
 *   être {dirigeant visé} et autoriser {demandeur} à gérer le compte de
 *   {société} » ;
 * - conclure() (appelé par RecevoirResultatIdentiteAction) : son identité
 *   vérifiée et rapprochée du dirigeant visé approuve le mandat, qui
 *   prouve la qualité de mandataire du demandeur (B2) pour le SIREN de la
 *   demande, avec le jour où ce dirigeant a pris ses fonctions (voir
 *   SignauxDeRisque, dirigeant récent) ; une identité qui n'est pas celle
 *   du dirigeant visé ne prouve rien et clôt la demande ;
 * - refuser() : il refuse, et le demandeur en est prévenu.
 *
 * La demande se retrouve par l'empreinte de son jeton, toutes sociétés
 * confondues (le lien arrive sans société), puis tout se fait dans sa
 * société. Un lien expiré, déjà répondu ou inconnu ne mène à rien.
 */
class ApprouverMandatAction
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public function __construct(
        private readonly VerificationIdentite $identite,
        private readonly RegistreDesVerifications $registre,
    ) {}

    /**
     * La demande en attente de ce jeton, null s'il n'en désigne aucune.
     */
    public static function demandeEnAttente(string $jeton): ?DemandeAccordDirigeant
    {
        $demande = DemandeAccordDirigeant::withoutGlobalScope(SocieteScope::class)->where('jeton_hache', DemandeAccordDirigeant::empreinte($jeton))->first();

        return $demande !== null && $demande->estEnAttente() ? $demande : null;
    }

    /**
     * @return array{session: SessionDIdentite, state: string}
     *
     * @throws VerificationRefuseeException
     */
    public function demarrer(string $jeton, string $urlDeRetour): array
    {
        $demande = self::demandeEnAttente($jeton) ?? throw new VerificationRefuseeException(self::MESSAGE_LIEN_INVALIDE);

        return app(TenantContext::class)->runAsSociete($demande->societe, function () use ($demande, $urlDeRetour): array {
            $representant = $demande->representant();
            $state = Str::random(40);

            try {
                $session = $this->identite->demarrer(new DemandeDIdentite(
                    texteDeConsentement: 'Vous confirmez être '.trim(($representant->prenoms[0] ?? '').' '.$representant->nom).", {$representant->role}, et autoriser {$demande->demandeur->name} à gérer le compte ".config('app.name').' de '.$demande->societe->getFilamentName().'.',
                    urlDeRetour: $urlDeRetour,
                    state: $state,
                ));
            } catch (IdentiteIndisponibleException $indisponible) {
                throw new VerificationRefuseeException($indisponible->getMessage());
            }

            $this->registre->noter($demande->societe, TypeVerification::Identite, ResultatVerification::EnAttente, ['consentement' => true, 'demande_accord_id' => $demande->getKey()], fournisseur: DemarrerVerificationIdentiteAction::FOURNISSEUR, reference: $session->reference);

            return ['session' => $session, 'state' => $state];
        });
    }

    /**
     * Conclut la demande avec l'identité vérifiée du dirigeant, dans la
     * transaction de RecevoirResultatIdentiteAction, sa société active.
     */
    public function conclure(DemandeAccordDirigeant $demande, string $reference, ResultatDIdentite $resultat): VerificationSociete
    {
        $societe = $demande->societe;
        $identite = $this->registre->noter($societe, TypeVerification::Identite, $resultat->statut, [...$resultat->details(), 'demande_accord_id' => $demande->getKey()], fournisseur: DemarrerVerificationIdentiteAction::FOURNISSEUR, reference: $reference);
        $demande = DemandeAccordDirigeant::query()->whereKey($demande->getKey())->lockForUpdate()->firstOrFail();

        if (! $demande->estEnAttente()) {
            return $identite;
        }

        $representant = $demande->representant();
        $approuve = $resultat->statut === ResultatVerification::Concluante && RapprochementDesDirigeants::correspond($resultat, $representant);

        $demande->forceFill($approuve ? ['approuvee_le' => now(), 'verification_identite_id' => $identite->getKey()] : ['refusee_le' => now()])->save();
        $this->registre->noter($societe, TypeVerification::Mandat, $approuve ? ResultatVerification::Concluante : ResultatVerification::Negative, [
            'siren' => $demande->siren,
            'demande_accord_id' => $demande->getKey(),
            'dirigeant' => $representant->libelle(),
            'role' => $representant->role,
            'debut_fonction' => $representant->debutFonction,
            'identite_id' => $identite->getKey(),
        ], beneficiaire: $demande->demandeur, fournisseur: DemarrerVerificationIdentiteAction::FOURNISSEUR);

        $demande->demandeur->notify(new AvisDeVerification(
            $societe,
            $approuve ? 'Votre mandat est approuvé' : 'Votre mandat n\'est pas approuvé',
            $approuve
                ? "{$representant->libelle()} a vérifié son identité et approuvé votre mandat."
                : 'L\'identité vérifiée n\'est pas celle du dirigeant sollicité : le mandat ne prouve rien. Vous pouvez demander l\'accord d\'un autre dirigeant, ou déposer des pièces pour l\'éditeur.',
            $approuve ? AvisDeVerification::SUCCES : AvisDeVerification::REFUS,
        ));

        return $identite;
    }

    /**
     * @throws VerificationRefuseeException
     */
    public function refuser(string $jeton): void
    {
        $demande = self::demandeEnAttente($jeton) ?? throw new VerificationRefuseeException(self::MESSAGE_LIEN_INVALIDE);

        app(TenantContext::class)->runAsSociete($demande->societe, fn () => DB::transaction(function () use ($demande): void {
            $verrouillee = DemandeAccordDirigeant::query()->whereKey($demande->getKey())->lockForUpdate()->firstOrFail();

            if (! $verrouillee->estEnAttente()) {
                throw new VerificationRefuseeException(self::MESSAGE_LIEN_INVALIDE);
            }

            $verrouillee->forceFill(['refusee_le' => now()])->save();
            $representant = $verrouillee->representant();
            $this->registre->noter($verrouillee->societe, TypeVerification::Mandat, ResultatVerification::Negative, [
                'siren' => $verrouillee->siren,
                'demande_accord_id' => $verrouillee->getKey(),
                'dirigeant' => $representant->libelle(),
                'role' => $representant->role,
            ], beneficiaire: $verrouillee->demandeur);

            $verrouillee->demandeur->notify(new AvisDeVerification($verrouillee->societe, 'Votre mandat est refusé', "{$representant->libelle()} a refusé de vous autoriser à gérer le compte de la société.", AvisDeVerification::REFUS));
        }));
    }
}
