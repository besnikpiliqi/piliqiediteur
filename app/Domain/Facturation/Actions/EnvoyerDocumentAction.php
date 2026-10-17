<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
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
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
 * magasin (voir DebitDesEmails), afin qu'aucun compte ne puisse en faire
 * un relais d'envoi en masse. Les relances automatiques, déjà bornées par
 * les délais du magasin, n'y sont pas soumises.
 *
 * Un magasin qui ne peut pas vendre n'écrit pas à ses clients (voir
 * Magasin::verifierQuIlPeutEcrireAuxClients()) : ni envoi ni relance tant
 * que sa société est en cours de vérification, ni une fois le magasin
 * suspendu ou fermé, ou sa société suspendue, résiliée ou refusée ; le
 * magasin de démonstration, jamais.
 *
 * L'objet de l'e-mail nomme l'émetteur du document (voir
 * EnvoiDocument::objetPour()) : un ticket, une facture ou un avoir
 * numéroté sans enregistrement signé n'en a aucun, et ne part pas (voir
 * DocumentSansEnregistrementSigneException).
 *
 * Un envoi ou une relance demandé par une personne (handle(), relancer())
 * relit son auteur sous le verrou de sa ligne, avant celui du document
 * (voir LecteurDesDroits) : il doit pouvoir envoyer ce document-là dans ce
 * magasin (un vendeur sans « voir celles de toute l'équipe » n'envoie que
 * ses factures). L'envoi note son nom figé, et l'e-mail ne part que si,
 * au moment de partir, il le peut encore (voir EnvoyerDocumentJob). La
 * relance automatique (relanceAutomatique()) est celle du système, sans
 * auteur.
 */
class EnvoyerDocumentAction
{
    public function __construct(
        private readonly EmettreDevisAction $emettreDevis,
        private readonly LecteurDesDroits $lecteur,
        private readonly DebitDesEmails $debit,
    ) {}

    /**
     * Un devis brouillon part au statut « Envoyé », avec le numéro qu'il
     * reçoit, ou celui qu'il a gardé s'il a été modifié depuis son dernier
     * envoi (voir EmettreDevisAction) ; une facture émise devient
     * « envoyée », sans qu'aucun autre statut ne change.
     *
     * @param  Facture|Devis|Avoir  $document
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws DocumentSansEnregistrementSigneException
     */
    public function handle(User $auteur, DocumentEnvoyable $document, string $destinataire, ?string $message): EnvoiDocument
    {
        $destinataire = $this->destinataireValide($destinataire);

        return DB::transaction(function () use ($auteur, $document, $destinataire, $message) {
            $expediteur = $this->expediteur($auteur, $document);
            $document = $this->verrouiller($document);
            $this->verifierLeDebit($document, $expediteur);

            if ($document instanceof Devis && $document->statut === StatutDevis::Brouillon) {
                $document = $this->emettreDevis->handle($expediteur, $document);
            }

            if (! $document->peutEtreEnvoye()) {
                throw new LogicException("Impossible d'envoyer ce document au statut « {$document->statut->getLabel()} ».");
            }

            if ($document instanceof Facture && $document->statut === StatutFacture::Emise) {
                $document->forceFill(['statut' => StatutFacture::Envoyee])->save();
            }

            $envoi = $document->envois()->create([
                'destinataire' => $destinataire,
                'objet' => EnvoiDocument::objetPour($document),
                'message' => filled($message) ? trim($message) : null,
                'nature' => NatureEnvoi::Envoi,
                'envoye_par' => $expediteur->getKey(),
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
     * Demandée par une personne, à l'adresse qu'elle donne.
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws DocumentSansEnregistrementSigneException
     */
    public function relancer(User $auteur, Facture $facture, string $destinataire): EnvoiDocument
    {
        $destinataire = $this->destinataireValide($destinataire);

        return DB::transaction(fn (): EnvoiDocument => $this->relance($facture, $destinataire, $this->expediteur($auteur, $facture)));
    }

    /**
     * La relance due d'une facture échue et impayée, par le système, à
     * l'adresse de son client (voir RelancerFacturesImpayees).
     *
     * @throws LogicException
     * @throws DocumentSansEnregistrementSigneException
     */
    public function relanceAutomatique(Facture $facture): EnvoiDocument
    {
        $destinataire = $this->destinataireValide((string) $facture->client->email);

        return DB::transaction(fn (): EnvoiDocument => $this->relance($facture, $destinataire, null));
    }

    /**
     * @throws LogicException
     * @throws DocumentSansEnregistrementSigneException
     */
    private function relance(Facture $facture, string $destinataire, ?User $expediteur): EnvoiDocument
    {
        $facture = $this->verrouiller($facture);
        $facture->magasin->verifierQuIlPeutEcrireAuxClients();
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
            'objet' => EnvoiDocument::objetPour($facture, $niveau),
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
    }

    /**
     * La personne qui demande l'envoi ou la relance, relue sous le verrou de
     * sa ligne, avant celui du document : le document, lu par la requête du
     * magasin actif (un document d'un autre magasin n'existe pas ici), puis
     * son magasin, qui doit pouvoir écrire à ses clients, puis son droit
     * d'envoyer ce document-là.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function expediteur(User $auteur, DocumentEnvoyable $document): User
    {
        $document = $document::query()->whereKey($document->getKey())->firstOrFail();
        $document->magasin->verifierQuIlPeutEcrireAuxClients();

        return $this->lecteur->exigerSousVerrou($auteur, 'envoyer', $document->magasin, $document);
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
        if ($expediteur !== null) {
            $this->debit->verifier($expediteur, (int) $document->magasin_id);
        }
    }

    private function programmer(EnvoiDocument $envoi, ?User $expediteur): EnvoiDocument
    {
        if ($expediteur !== null) {
            $this->debit->compter($expediteur, (int) $envoi->magasin_id);
        }

        EnvoyerDocumentJob::dispatch($envoi->magasin_id, $envoi->id)->afterCommit();

        return $envoi;
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
