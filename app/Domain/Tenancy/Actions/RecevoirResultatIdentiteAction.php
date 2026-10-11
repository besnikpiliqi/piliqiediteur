<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\SignauxDeRisque;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Identite\ResultatDIdentite;
use App\Integrations\Rne\RneIndisponibleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reçoit le résultat d'une vérification d'identité démarrée (voir
 * DemarrerVerificationIdentiteAction et ApprouverMandatAction) : au retour
 * de la personne, dont la session PILIQI a gardé le state (voir
 * RetourVerificationIdentiteController), ou par le rappel signé du
 * prestataire (voir RappelsDesPrestatairesController).
 *
 * La session se retrouve par sa référence, toutes sociétés confondues (le
 * rappel arrive sans société), puis tout se fait dans sa société. Elle ne
 * sert qu'une fois : un résultat déjà reçu pour cette référence, ou une
 * référence inconnue, n'écrit rien. Seuls les détails de la liste blanche
 * se gardent (voir ResultatDIdentite).
 *
 * L'identité concluante du propriétaire se rapproche aussitôt des
 * dirigeants du registre national des entreprises (voir
 * RapprochementDesDirigeants) : rapprochée, elle prouve sa qualité de
 * représentant (B1) ; sans dirigeant correspondant, elle ne prouve rien
 * (signal identite_non_concluante) ; le registre injoignable laisse le
 * rapprochement en attente, réessayable (voir rapprocherDeNouveau()). La
 * troisième vérification échouée lève le signal essais_repetes. Celle d'un
 * dirigeant sollicité pour un mandat conclut sa demande (voir
 * ApprouverMandatAction::conclure()). Puis la société est validée si elle
 * peut l'être (voir ValiderLaSocieteAction).
 */
class RecevoirResultatIdentiteAction
{
    public const ECHECS_AVANT_BLOCAGE = 3;

    public const MESSAGE_RIEN_A_RAPPROCHER = 'Aucune identité vérifiée n\'attend d\'être rapprochée des dirigeants de votre entreprise.';

    public function __construct(
        private readonly RegistreDesVerifications $registre,
        private readonly RapprochementDesDirigeants $rapprochement,
        private readonly ApprouverMandatAction $approuverMandat,
        private readonly ValiderLaSocieteAction $validerLaSociete,
    ) {}

    /**
     * @return VerificationSociete|null la ligne du résultat, null quand rien n'est écrit (référence inconnue, déjà reçue, ou toujours en cours)
     */
    public function handle(string $reference, ResultatDIdentite $resultat): ?VerificationSociete
    {
        if ($resultat->statut === ResultatVerification::EnAttente) {
            return null;
        }

        $demarree = VerificationSociete::withoutGlobalScope(SocieteScope::class)
            ->where('type', TypeVerification::Identite->value)
            ->where('fournisseur', DemarrerVerificationIdentiteAction::FOURNISSEUR)
            ->where('reference', $reference)
            ->where('resultat', ResultatVerification::EnAttente->value)
            ->first();

        if ($demarree === null) {
            return null;
        }

        $societe = Societe::query()->findOrFail($demarree->societe_id);

        $ligne = app(TenantContext::class)->runAsSociete($societe, fn (): ?VerificationSociete => DB::transaction(function () use ($societe, $demarree, $reference, $resultat): ?VerificationSociete {
            VerificationSociete::query()->whereKey($demarree->getKey())->lockForUpdate()->first();

            $dejaRecue = VerificationSociete::query()
                ->where('type', TypeVerification::Identite->value)
                ->where('reference', $reference)
                ->where('resultat', '<>', ResultatVerification::EnAttente->value)
                ->exists();

            if ($dejaRecue) {
                return null;
            }

            $demandeId = $demarree->details['demande_accord_id'] ?? null;

            if ($demandeId !== null) {
                return $this->approuverMandat->conclure(DemandeAccordDirigeant::query()->findOrFail($demandeId), $reference, $resultat);
            }

            return $this->conclure($societe, $demarree->beneficiaire()->firstOrFail(), $reference, $resultat);
        }));

        if ($ligne !== null) {
            $this->validerLaSociete->siPossible($societe->fresh());
        }

        return $ligne;
    }

    /**
     * Rapproche de nouveau la dernière identité vérifiée du propriétaire,
     * quand le registre national ne répondait pas à sa réception.
     *
     * @throws VerificationRefuseeException
     */
    public function rapprocherDeNouveau(User $proprietaire, Societe $societe): VerificationSociete
    {
        $ligne = app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe): VerificationSociete {
            if (Gate::forUser($proprietaire)->denies('verifierLaSociete', $societe)) {
                throw new VerificationRefuseeException(DemarrerVerificationIdentiteAction::MESSAGE_SANS_AUTORISATION);
            }

            $duProprietaire = VerificationSociete::query()->where('beneficiaire_id', $proprietaire->getKey())->orderBy('id')->get();
            $identite = $duProprietaire->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Identite && $ligne->estConcluante());
            $dernierRapprochement = $duProprietaire->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::RneRepresentants);

            if ($identite === null || $dernierRapprochement?->resultat !== ResultatVerification::Indisponible) {
                throw new VerificationRefuseeException(self::MESSAGE_RIEN_A_RAPPROCHER);
            }

            return $this->rapprocher($societe, $proprietaire, ResultatDIdentite::depuisLesDetails($identite->details), $identite);
        });

        $this->validerLaSociete->siPossible($societe->fresh());

        return $ligne;
    }

    private function conclure(Societe $societe, User $proprietaire, string $reference, ResultatDIdentite $resultat): VerificationSociete
    {
        $identite = $this->registre->noter($societe, TypeVerification::Identite, $resultat->statut, $resultat->details(), null, $proprietaire, DemarrerVerificationIdentiteAction::FOURNISSEUR, $reference);

        if ($resultat->statut === ResultatVerification::Negative) {
            $this->constaterLesEchecsRepetes($societe);
        }

        if ($resultat->statut === ResultatVerification::Concluante) {
            $this->rapprocher($societe, $proprietaire, $resultat, $identite);
        }

        return $identite;
    }

    /**
     * Rapproche l'identité vérifiée des dirigeants de l'entreprise : la
     * ligne du rapprochement, concluante avec la chaîne qui mène au
     * dirigeant, négative sans dirigeant correspondant, ou impossible quand
     * le registre ne répond pas.
     */
    private function rapprocher(Societe $societe, User $proprietaire, ResultatDIdentite $resultat, VerificationSociete $identite): VerificationSociete
    {
        try {
            $candidat = $this->rapprochement->rapprocher((string) $societe->siren, $resultat);
        } catch (RneIndisponibleException) {
            return $this->registre->noter($societe, TypeVerification::RneRepresentants, ResultatVerification::Indisponible, ['siren' => $societe->siren, 'identite_id' => $identite->getKey()], null, $proprietaire, 'inpi');
        }

        return $this->registre->noter($societe, TypeVerification::RneRepresentants, $candidat === null ? ResultatVerification::Negative : ResultatVerification::Concluante, [
            'siren' => $societe->siren,
            'identite_id' => $identite->getKey(),
            ...($candidat === null ? [] : [
                'chaine' => $candidat['chaine'],
                'role' => $candidat['representant']->role,
                'debut_fonction' => $candidat['representant']->debutFonction,
            ]),
        ], null, $proprietaire, 'inpi');
    }

    /**
     * À la troisième vérification d'identité échouée depuis le dernier
     * constat ou la dernière levée des essais répétés, le signal se
     * constate : les voies automatiques se bloquent jusqu'à la décision de
     * l'éditeur.
     */
    private function constaterLesEchecsRepetes(Societe $societe): void
    {
        $lignes = VerificationSociete::query()->orderBy('id')->get();
        $depuis = max(
            SignauxDeRisque::dernierConstat($lignes, SignalDeRisque::EssaisRepetes),
            (int) $lignes->filter(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Manuelle && $ligne->signal === SignalDeRisque::EssaisRepetes)->max('id'),
        );
        $echecs = $lignes->filter(fn (VerificationSociete $ligne): bool => $ligne->getKey() > $depuis && $ligne->type === TypeVerification::Identite && $ligne->resultat === ResultatVerification::Negative)->count();

        if ($echecs >= self::ECHECS_AVANT_BLOCAGE) {
            $this->registre->noter($societe, TypeVerification::Signal, ResultatVerification::AExaminer, ['constat' => "{$echecs} vérifications d'identité échouées"], signal: SignalDeRisque::EssaisRepetes);
        }
    }
}
