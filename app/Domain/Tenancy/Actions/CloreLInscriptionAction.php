<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Clôt, pour toujours, une inscription qui attend encore son code (voir
 * MotifCloture) : sans code saisi trente jours après son dernier courrier
 * (voir app:clore-inscriptions-sans-code), ou par le refus de l'éditeur
 * (voir editeur:societe:refuser). La société n'est jamais supprimée :
 * elle reste close, en lecture seule, sans accès commercial, et son SIREN
 * est libéré (voir l'index unique partiel societes_siren_unique) ; une
 * nouvelle inscription de ce SIREN peut alors se faire. Toutes les
 * clôtures suivent la même suite :
 * - sous le verrou de la société (voir cloreSousVerrou()) : la clôture
 *   (close_le, motif_cloture) s'inscrit au journal d'audit avec son motif
 *   et, pour l'éditeur, sa justification ; les pièces déposées s'effacent,
 *   dès la décision ; ses codes de confirmation encore actifs s'annulent ;
 * - après la transaction (voir apresLaCloture()) : son magasin de
 *   démonstration se ferme pour de bon et ses comptes d'entraînement sont
 *   retirés (un échec n'empêche rien : il ne vend plus, et la commande
 *   quotidienne le reprend), et son propriétaire est prévenu.
 */
class CloreLInscriptionAction
{
    public const MESSAGE_N_ATTEND_PLUS = 'Cette inscription n\'attend plus de code : elle est déjà validée, close ou révoquée.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly PiecesDeVerification $pieces,
        private readonly AnnulationDesCodes $annulationDesCodes,
        private readonly FermerLaDemonstrationAction $fermerLaDemonstration,
    ) {}

    /**
     * Le refus de l'éditeur.
     *
     * @throws VerificationRefuseeException
     */
    public function refuser(Societe $societe, InterventionDeLEditeur $editeur): void
    {
        app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($societe, $editeur): void {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

            if (! $verrouillee->attendSonCode()) {
                throw new VerificationRefuseeException(self::MESSAGE_N_ATTEND_PLUS);
            }

            $this->cloreSousVerrou($verrouillee, MotifCloture::Refus, $editeur);
        }));

        $this->apresLaCloture($societe->fresh(), MotifCloture::Refus);
    }

    /**
     * Clôt l'inscription si aucun courrier de sa vérification n'est parti
     * depuis la limite, ni elle-même créée depuis : relu sous le verrou de
     * sa ligne, un nouveau courrier parti entre-temps la garde.
     *
     * @return bool l'inscription vient-elle d'être close ?
     */
    public function cloreSansCode(Societe $societe, CarbonImmutable $limite): bool
    {
        $close = app(TenantContext::class)->runAsSociete($societe, fn (): bool => DB::transaction(function () use ($societe, $limite): bool {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
            $courrierRecent = CodeConfirmation::query()->where('motif', MotifCode::Verification->value)->where('envoye_le', '>', $limite)->exists();

            if (! $verrouillee->attendSonCode() || $courrierRecent || $verrouillee->created_at->greaterThan($limite)) {
                return false;
            }

            $this->cloreSousVerrou($verrouillee, MotifCloture::DelaiExpire);

            return true;
        }));

        if ($close) {
            $this->apresLaCloture($societe->fresh(), MotifCloture::DelaiExpire);
        }

        return $close;
    }

    /**
     * Clôt l'inscription, dans la transaction de l'appelant, sa ligne
     * verrouillée et elle-même la société active, qui attend son code.
     */
    public function cloreSousVerrou(Societe $verrouillee, MotifCloture $motif, ?InterventionDeLEditeur $editeur = null): void
    {
        $verrouillee->forceFill(['close_le' => now(), 'motif_cloture' => $motif])->save();
        $this->journalAudit->inscrire(ActionAuditee::InscriptionClose, null, null, null, ['motif' => $motif->value], $verrouillee, $editeur);
        $this->pieces->effacerToutes($verrouillee, PiecesDeVerification::MOTIF_DECISION, $editeur);

        CodeConfirmation::query()->whereNull('utilise_le')->whereNull('annule_le')->orderBy('id')->lockForUpdate()->get()
            ->each(fn (CodeConfirmation $code) => $this->annulationDesCodes->annuler($code, AnnulationDesCodes::INSCRIPTION_CLOSE, editeur: $editeur));
    }

    /**
     * Après la transaction de la clôture : la démonstration se ferme pour de
     * bon (ses comptes d'entraînement sont retirés, même si elle était déjà
     * fermée pour inactivité, voir FermerLaDemonstrationAction), et le
     * propriétaire est prévenu, avec le motif de la clôture (la
     * justification de l'éditeur reste interne).
     */
    public function apresLaCloture(Societe $societe, MotifCloture $motif): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $motif): void {
            $demonstration = $societe->demonstration()->first();

            if ($demonstration !== null) {
                try {
                    $this->fermerLaDemonstration->handle($demonstration);
                } catch (Throwable $erreur) {
                    report($erreur);
                }
            }

            $societe->proprietaire?->notify(new AvisDeVerification(
                $societe,
                'L\'inscription de votre société est close',
                "{$motif->getLabel()}. Vos magasins restent consultables, en lecture seule. Pour toute question, contactez l'éditeur.",
                AvisDeVerification::REFUS,
            ));
        });
    }
}
