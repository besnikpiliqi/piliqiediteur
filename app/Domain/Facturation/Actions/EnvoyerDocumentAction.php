<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;

/**
 * Envoie un devis, une facture ou un avoir par e-mail, PDF en pièce
 * jointe, ou relance une facture échue et impayée. Le document est
 * vérifié et l'envoi noté ici (voir EnvoiDocument) ; l'e-mail lui-même
 * part en file d'attente une fois la transaction validée (voir
 * EnvoyerDocumentJob), pour qu'un serveur d'envoi lent ou indisponible ne
 * bloque ni ne fasse échouer le geste du magasin.
 *
 * Tous les magasins envoient depuis le domaine de l'éditeur : les envois
 * demandés par un membre sont limités, par heure, pour lui et pour son
 * magasin, afin qu'aucun compte ne puisse en faire un relais d'envoi en
 * masse. Les relances automatiques, déjà bornées par les délais du
 * magasin, n'y sont pas soumises.
 *
 * Un magasin suspendu ou fermé n'écrit plus à ses clients (voir
 * Magasin::verifierQuIlEstOuvert()) : ni envoi ni relance.
 */
class EnvoyerDocumentAction
{
    public const ENVOIS_PAR_HEURE_ET_PAR_MEMBRE = 60;

    public const ENVOIS_PAR_HEURE_ET_PAR_MAGASIN = 200;

    public function __construct(private readonly EmettreDevisAction $emettreDevis) {}

    /**
     * Un devis encore brouillon reçoit son numéro en partant (voir
     * EmettreDevisAction) ; une facture émise devient « envoyée », sans
     * qu'aucun autre statut ne change.
     *
     * @param  Facture|Devis|Avoir  $document
     *
     * @throws LogicException
     */
    public function handle(DocumentEnvoyable $document, string $destinataire, ?string $message, ?User $expediteur): EnvoiDocument
    {
        $destinataire = $this->destinataireValide($destinataire);

        return DB::transaction(function () use ($document, $destinataire, $message, $expediteur) {
            $document = $this->verrouiller($document);
            $document->magasin->verifierQuIlEstOuvert();
            $this->verifierLeDebit($document, $expediteur);

            if ($document instanceof Devis && $document->statut === StatutDevis::Brouillon) {
                $document = $this->emettreDevis->handle($document);
            }

            if (! $document->peutEtreEnvoye()) {
                throw new LogicException("Impossible d'envoyer ce document au statut « {$document->statut->getLabel()} ».");
            }

            if ($document instanceof Facture && $document->statut === StatutFacture::Emise) {
                $document->forceFill(['statut' => StatutFacture::Envoyee])->save();
            }

            $envoi = $document->envois()->create([
                'destinataire' => $destinataire,
                'objet' => "{$document->libelleDocument()} {$document->numero} — {$document->magasin->nom}",
                'message' => filled($message) ? trim($message) : null,
                'nature' => NatureEnvoi::Envoi,
                'envoye_par' => $expediteur?->getKey(),
            ]);

            return $this->programmer($envoi, $expediteur);
        });
    }

    /**
     * Rappelle au client ce qu'il reste à payer, facture jointe : c'est la
     * relance qui suit la dernière envoyée, au plus une par jour et par
     * facture, jamais deux fois le même niveau (voir aussi
     * RelancerFacturesImpayees, qui décide quand elle est due).
     *
     * Une relance en échec ne compte pas (voir
     * Facture::relancesComptees()) : c'est elle qui repart, à sa place dans
     * l'historique, vers l'adresse donnée cette fois.
     *
     * @throws LogicException
     */
    public function relancer(Facture $facture, string $destinataire, ?User $expediteur): EnvoiDocument
    {
        $destinataire = $this->destinataireValide($destinataire);

        return DB::transaction(function () use ($facture, $destinataire, $expediteur) {
            $facture = $this->verrouiller($facture);
            $facture->magasin->verifierQuIlEstOuvert();
            $this->verifierLeDebit($facture, $expediteur);

            if (! $facture->estEchueEtImpayee()) {
                throw new LogicException("La facture {$facture->numero} n'est pas une facture échue et impayée : elle ne se relance pas.");
            }

            if ($facture->joursDepuisLaDerniereRelance() === 0) {
                throw new LogicException("La facture {$facture->numero} a déjà été relancée aujourd'hui : la relance suivante pourra partir demain.");
            }

            $niveau = $facture->niveauDeRelanceAtteint() + 1;
            $attributs = [
                'destinataire' => $destinataire,
                'objet' => "Relance n°{$niveau} : {$facture->libelleDocument()} {$facture->numero} — {$facture->magasin->nom}",
                'message' => $facture->messageDeRelance(),
                'envoye_par' => $expediteur?->getKey(),
            ];

            $enEchec = $facture->envois()
                ->where('nature', NatureEnvoi::Relance)
                ->where('niveau_relance', $niveau)
                ->where('statut', StatutEnvoi::Echec)
                ->first();

            if ($enEchec === null) {
                $envoi = $facture->envois()->create([...$attributs, 'nature' => NatureEnvoi::Relance, 'niveau_relance' => $niveau]);
            } else {
                $envoi = $enEchec;
                $envoi->forceFill([
                    ...$attributs,
                    'statut' => StatutEnvoi::EnAttente,
                    'erreur' => null,
                    'envoye_le' => null,
                    // La date de la nouvelle tentative : celle que montre la
                    // fiche, et qui compte pour « une relance par jour ».
                    'created_at' => now(),
                ])->save();
            }

            return $this->programmer($envoi, $expediteur);
        });
    }

    /**
     * Le document relu sous verrou, par la requête du magasin actif : un
     * document d'un autre magasin n'existe pas ici.
     *
     * @template TDocument of Model&DocumentEnvoyable
     *
     * @param  TDocument  $document
     * @return TDocument
     */
    private function verrouiller(DocumentEnvoyable $document): DocumentEnvoyable
    {
        return $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws LogicException
     */
    private function verifierLeDebit(Model&DocumentEnvoyable $document, ?User $expediteur): void
    {
        if ($expediteur === null) {
            return;
        }

        foreach ($this->compteursDeDebit($document->magasin_id, $expediteur) as $cle => $maximum) {
            if (RateLimiter::tooManyAttempts($cle, $maximum)) {
                $minutes = (int) ceil(RateLimiter::availableIn($cle) / 60);

                throw new LogicException("Trop d'e-mails envoyés en une heure : réessayez dans {$minutes} minute(s).");
            }
        }
    }

    private function programmer(EnvoiDocument $envoi, ?User $expediteur): EnvoiDocument
    {
        if ($expediteur !== null) {
            foreach (array_keys($this->compteursDeDebit($envoi->magasin_id, $expediteur)) as $cle) {
                RateLimiter::hit($cle, 3600);
            }
        }

        EnvoyerDocumentJob::dispatch($envoi->magasin_id, $envoi->id)->afterCommit();

        return $envoi;
    }

    /**
     * @return array<string, int> le maximum d'envois par heure de chaque compteur
     */
    private function compteursDeDebit(int $magasinId, User $expediteur): array
    {
        return [
            "envois-documents:membre:{$expediteur->getKey()}" => self::ENVOIS_PAR_HEURE_ET_PAR_MEMBRE,
            "envois-documents:magasin:{$magasinId}" => self::ENVOIS_PAR_HEURE_ET_PAR_MAGASIN,
        ];
    }

    /**
     * Une adresse sans aucune particularité de la norme (commentaire,
     * partie entre guillemets, domaine sans point) : celles-là, le serveur
     * d'envoi les refuserait, ou elles n'arriveraient nulle part.
     *
     * @throws InvalidArgumentException
     */
    private function destinataireValide(string $destinataire): string
    {
        $destinataire = trim($destinataire);

        if (Validator::make(['destinataire' => $destinataire], ['destinataire' => ['required', 'email:strict', 'max:254']])->fails()) {
            throw new InvalidArgumentException("L'adresse e-mail « {$destinataire} » n'est pas valide.");
        }

        return $destinataire;
    }
}
