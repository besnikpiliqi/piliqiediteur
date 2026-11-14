<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\Facturation\Actions\RelancerLaTransmissionAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\TransmissionNonAboutie;
use App\Domain\Tenancy\Concerns\WithTenantContext;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Providers\FournisseursExternesServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * La transmission d'une facture à la plateforme agréée. L'appel au PDP
 * est un appel réseau externe : il ne doit jamais bloquer ni faire
 * échouer la transaction d'émission de la facture (voir
 * EmettreFactureAction). Dispatché avec ->afterCommit() par le listener
 * qui le déclenche, pour ne démarrer qu'une fois la facture réellement
 * visible en base ; relancé chaque heure par app:relancer-transmissions-pdp
 * et à la main par RelancerLaTransmissionAction tant que la transmission
 * est en échec technique.
 *
 * Une panne n'est jamais un rejet (constat C33 des audits du
 * 07/11/2026) : la transmission garde sa référence de dépôt, tirée à sa
 * création ; chaque reprise demande d'abord à la plateforme si elle a déjà
 * reçu ce dépôt (la réponse a pu se perdre après la réception) et ne
 * redépose, sous la même référence, que si elle ne le connaît pas : une
 * facture n'est jamais déposée deux fois. Sur une panne, la transmission
 * reste soumise, un événement dit de quelle sorte d'incident il s'agit, et
 * l'exception est relancée pour la tentative suivante : son message
 * technique part au journal de l'application avec elle (le travailleur de
 * la file la rapporte), jamais en base. Après la dernière tentative, elle
 * passe « non transmise : incident technique » (voir failed()). Seule une
 * réponse de rejet de la plateforme la rend « rejetée », avec son motif ;
 * un état final (acceptée, livrée, archivée, rejetée, refusée) arrête tout
 * nouvel essai, et le redépôt d'une facture rejetée est une nouvelle
 * transmission, sous une nouvelle référence, par un geste de l'étape 2.
 *
 * ShouldBeUnique : une facture n'est jamais soumise deux fois en
 * parallèle ; la transmission se relit en plus sous le verrou de sa
 * facture.
 */
class SubmitFactureToPdpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithTenantContext;

    /**
     * L'explication enregistrée sur une transmission qui n'a pas abouti
     * après toutes les tentatives automatiques.
     */
    public const EXPLICATION_DE_L_ECHEC = "La plateforme agréée n'a pas pu être jointe après plusieurs tentatives : c'est un incident technique, pas un refus de la facture. PILIQI la relance chaque heure, sous la même référence, sans jamais la déposer deux fois.";

    public int $tries = 4;

    /**
     * Quelques minutes entre deux tentatives, le temps qu'un incident du
     * réseau ou de la plateforme se résorbe.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $magasinId,
        public readonly int $factureId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->factureId;
    }

    /**
     * @throws Throwable une panne, relancée pour la tentative suivante
     */
    public function handle(PdpGatewayInterface $gateway): void
    {
        $this->demarrerContexteTenant();

        $facture = Facture::query()->with('magasin')->findOrFail($this->factureId);

        // Le magasin de démonstration ne transmet jamais rien (voir
        // Magasin::peutEcrireAuxClients()).
        if ($facture->magasin->est_demonstration) {
            return;
        }

        $transmission = $this->transmissionAReprendre($facture);

        if ($transmission === null) {
            return;
        }

        try {
            $resultat = ($transmission->wasRecentlyCreated ? null : $gateway->retrouverDepot($transmission))
                ?? $gateway->soumettreFacture($facture, $transmission->reference_depot);
        } catch (Throwable $panne) {
            $transmission->evenements()->create([
                'statut' => $transmission->statut->value,
                'charge_utile' => ['incident' => self::sorteDIncident($panne)],
                'survenu_le' => now(),
            ]);

            throw $panne;
        }

        $this->enregistrer($transmission, $resultat);
    }

    /**
     * Toutes les tentatives automatiques ont échoué : la transmission passe
     * « non transmise : incident technique », avec une explication en
     * français, sans le message technique ; à son entrée dans cet état, le
     * propriétaire et les administrateurs qui suivent la plateforme agréée
     * dans ce magasin en sont prévenus (décision de l'éditeur, Q-AUD3), et
     * PILIQI la relance chaque heure. Une transmission que la plateforme a
     * entre-temps acceptée ou rejetée ne change pas.
     */
    public function failed(?Throwable $exception): void
    {
        $this->demarrerContexteTenant();

        $facture = Facture::query()->with('magasin.societe')->find($this->factureId);
        $transmission = $facture === null ? null : self::derniereTransmission($facture);

        if ($facture === null || $transmission === null || $transmission->statut->estFinal()) {
            return;
        }

        $dejaEnEchec = $transmission->statut === StatutTransmissionPdp::EchecTechnique;

        $transmission->forceFill(['code_erreur' => null, 'message_erreur' => self::EXPLICATION_DE_L_ECHEC])->save();
        $transmission->enregistrerChangementStatut(StatutTransmissionPdp::EchecTechnique, ['incident' => self::sorteDIncident($exception)]);

        if (! $dejaEnEchec) {
            Notification::send(RelancerLaTransmissionAction::comptesPrevenus($facture), new TransmissionNonAboutie($facture));
        }
    }

    /**
     * La dernière transmission sortante de la facture, relue sous le verrou
     * de la facture, à reprendre si elle n'est pas finale ; une nouvelle,
     * avec sa référence de dépôt, s'il n'y en a encore aucune ; null si la
     * plateforme a déjà répondu.
     */
    private function transmissionAReprendre(Facture $facture): ?TransmissionPdp
    {
        return DB::transaction(function () use ($facture): ?TransmissionPdp {
            Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            $derniere = self::derniereTransmission($facture);

            if ($derniere !== null) {
                return $derniere->statut->estFinal() ? null : $derniere;
            }

            return $facture->transmissionsPdp()->create([
                'direction' => 'outbound',
                'prestataire_pdp' => FournisseursExternesServiceProvider::pdpActive(),
                'statut' => StatutTransmissionPdp::Soumise,
                'soumise_le' => now(),
            ]);
        });
    }

    private static function derniereTransmission(Facture $facture): ?TransmissionPdp
    {
        return $facture->transmissionsPdp()->where('direction', 'outbound')->latest('id')->first();
    }

    private function enregistrer(TransmissionPdp $transmission, ResultatSoumissionPdp $resultat): void
    {
        $transmission->forceFill([
            'transmission_externe_id' => $resultat->transmissionExterneId,
            'format' => $resultat->format,
            'chemin_fichier_archive' => $resultat->cheminFichierArchive,
            'code_erreur' => $resultat->codeErreur,
            'message_erreur' => $resultat->messageErreur,
        ])->save();

        $transmission->enregistrerChangementStatut($resultat->statut);
    }

    /**
     * La sorte d'incident, inscrite à l'historique de la transmission sans
     * le message technique : le réseau (connexion impossible, délai
     * dépassé), ou une erreur inattendue.
     */
    private static function sorteDIncident(?Throwable $panne): string
    {
        return $panne instanceof ConnectionException ? 'reseau' : 'inattendu';
    }
}
