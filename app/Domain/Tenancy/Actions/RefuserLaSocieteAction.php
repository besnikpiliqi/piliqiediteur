<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le refus définitif d'une société en cours de vérification (refusee_le),
 * qui met tous ses magasins en lecture seule pour toujours : par
 * l'éditeur (voir editeur:societe:refuser), ou par le répertoire Sirene
 * quand son entreprise est cessée avant la validation (voir
 * ReverificationSirene). Les deux refus suivent la même suite :
 * - sous le verrou de la société (voir refuserSousVerrou()) : le refus
 *   s'inscrit au journal d'audit avec son motif (la justification de
 *   l'éditeur, ou celui du répertoire) ; les pièces déposées s'effacent,
 *   dès la décision ; ses codes de confirmation encore actifs s'annulent,
 *   pour qu'ils ne retiennent pas le courrier d'une autre inscription du
 *   même SIREN, celle du vrai titulaire ;
 * - après la transaction (voir apresLeRefus()) : son magasin de
 *   démonstration se ferme (un échec n'empêche rien : il ne vend plus, et
 *   la commande quotidienne le reprend), et son propriétaire est prévenu.
 */
class RefuserLaSocieteAction
{
    public const MESSAGE_DEJA_DECIDEE = 'Cette société est déjà validée ou refusée.';

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
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): void
    {
        app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($societe, $editeur): void {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouillee->estValidee() || $verrouillee->estRefusee()) {
                throw new VerificationRefuseeException(self::MESSAGE_DEJA_DECIDEE);
            }

            $this->refuserSousVerrou($verrouillee, $editeur->justification, $editeur);
        }));

        $this->apresLeRefus($societe, null);
    }

    /**
     * Refuse la société, dans la transaction de l'appelant, sa ligne
     * verrouillée et elle-même société active, ni validée ni refusée.
     */
    public function refuserSousVerrou(Societe $verrouillee, ?string $motif, ?InterventionDeLEditeur $editeur = null): void
    {
        $verrouillee->forceFill(['refusee_le' => now()])->save();
        $this->journalAudit->inscrire(ActionAuditee::SocieteRefusee, null, null, null, ['motif' => $motif], $verrouillee, $editeur);
        $this->pieces->effacerToutes($verrouillee, PiecesDeVerification::MOTIF_DECISION, $editeur);

        CodeConfirmation::query()->whereNull('utilise_le')->whereNull('annule_le')->orderBy('id')->lockForUpdate()->get()
            ->each(fn (CodeConfirmation $code) => $this->annulationDesCodes->annuler($code, AnnulationDesCodes::SOCIETE_REFUSEE, editeur: $editeur));
    }

    /**
     * Après la transaction du refus : la démonstration se ferme, et le
     * propriétaire est prévenu, avec le motif du répertoire quand c'est lui
     * qui fait refuser (la justification de l'éditeur reste interne).
     */
    public function apresLeRefus(Societe $societe, ?string $motifPourLeProprietaire): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $motifPourLeProprietaire): void {
            $demonstration = $societe->demonstration()->where('statut', StatutMagasin::Actif->value)->first();

            if ($demonstration !== null) {
                try {
                    $this->fermerLaDemonstration->handle($demonstration);
                } catch (Throwable $erreur) {
                    report($erreur);
                }
            }

            $societe->proprietaire?->notify(new AvisDeVerification(
                $societe,
                'L\'inscription de votre société est refusée',
                trim(($motifPourLeProprietaire ?? '').' Vos magasins restent consultables, en lecture seule. Pour toute question, contactez l\'éditeur.'),
                AvisDeVerification::REFUS,
            ));
        });
    }
}
