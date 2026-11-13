<?php

namespace App\Http\Controllers;

use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\RevueAntivirus;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le lien d'un téléchargement des documents par période (voir
 * TelechargementDeDocuments), dans le panneau de la personne connectée
 * (voir TelechargementDeDocuments::lien()) : /app, /societe ou
 * /cabinet/telechargements/{uuid}. L'adresse n'est jamais devinable (un
 * uuid tiré au hasard) et ne suffit pas : le téléchargement ne se cherche
 * que parmi ceux que ce compte a demandés, quelle que soit la société dont
 * il lit les documents (un compte d'un cabinet les demande pour un
 * client) ; il se sert pendant sept jours après sa demande, une fois prêt,
 * et seulement si ce compte télécharge encore les documents de chacun de
 * ses magasins (voir DocumentsDeLaPeriode::peutTelechargerDans()) et voit
 * encore chacun des documents que le fichier contient, type par type et
 * document par document (voir DocumentsDeLaPeriode::nombreEncoreVisibles()) :
 * une autorisation retirée (le téléchargement, ou la consultation d'un
 * type, ou celle des documents de toute l'équipe), un lien avec un cabinet
 * terminé ou une autorisation que le client lui retire, un compte suspendu
 * le ferment aussitôt. Une page en français dit pourquoi le fichier n'est
 * pas servi.
 *
 * Les pièces du fournisseur qu'un ZIP contient (voir
 * TelechargementDeDocuments::piecesContenues()) se revoient avant chaque
 * service, comme une pièce téléchargée seule (décision de l'éditeur du
 * 07/11/2026, lot FI) : une pièce bloquée depuis la préparation ferme le
 * fichier, qui se redemande sans elle ; une pièce dont la dernière analyse
 * date de plus de 24 heures repasse par l'antivirus, qui peut la bloquer
 * alors ; un antivirus injoignable fait réessayer plus tard (503).
 *
 * Le fichier, qui peut contenir des pièces reçues, part comme elles (voir
 * EnTetesDesFichiers::fichierRecu()) : en pièce jointe, dans un bac à
 * sable, jamais gardé en cache.
 */
class TelechargerLesDocumentsController
{
    public const INTROUVABLE = 'Ce téléchargement n\'existe pas, ou il a été demandé par un autre compte.';

    public const EN_PREPARATION = 'Ce fichier est encore en préparation : une notification vous préviendra quand il sera prêt.';

    public const ECHEC = 'Ce fichier n\'a pas pu être préparé : demandez de nouveau les documents depuis la page « Télécharger les documents ».';

    public const EXPIRE = 'Ce lien a expiré : il valait sept jours après la demande. Demandez de nouveau les documents depuis la page « Télécharger les documents ».';

    public const SANS_DROIT = 'Vous ne téléchargez plus les documents de ces magasins, ou vous ne voyez plus certains des documents de ce fichier : il ne vous est plus servi.';

    public const PIECE_BLOQUEE = 'Une pièce du fournisseur de ce fichier a été bloquée par l\'antivirus depuis sa préparation : le fichier ne se sert plus. Demandez de nouveau les documents depuis la page « Télécharger les documents » : il se préparera sans elle.';

    public function __invoke(Request $requete, DocumentsDeLaPeriode $documents, PiecesDuFournisseur $pieces, RevueAntivirus $revue, string $telechargement): Response|StreamedResponse
    {
        $compte = $requete->user();
        $trouve = $compte instanceof User ? TelechargementDeDocuments::duLienPour($compte, $telechargement)->first() : null;

        if ($trouve === null) {
            return $this->indisponible(self::INTROUVABLE, 404);
        }

        if ($trouve->estExpire()) {
            return $this->indisponible(self::EXPIRE, 410);
        }

        if ($trouve->statut !== StatutDuTelechargement::Pret) {
            return $trouve->statut === StatutDuTelechargement::Echec ? $this->indisponible(self::ECHEC, 404) : $this->indisponible(self::EN_PREPARATION, 404);
        }

        $societe = Societe::query()->findOrFail($trouve->societe_id);
        $magasins = Magasin::query()->where('societe_id', $societe->getKey())->whereIn('id', $trouve->magasins)->get()
            ->each(fn (Magasin $magasin): Magasin => $magasin->setRelation('societe', $societe));

        if (! $compte->peutAgir()
            || $magasins->count() !== count($trouve->magasins)
            || $magasins->contains(fn (Magasin $magasin): bool => ! $documents->peutTelechargerDans($compte, $magasin))
            || $documents->nombreEncoreVisibles($compte, $magasins, $trouve->clesChoisies()) !== $trouve->nombre_documents) {
            return $this->indisponible(self::SANS_DROIT, 403);
        }

        try {
            $this->revoirLesPieces($compte, $trouve, $magasins, $documents, $pieces, $revue);
        } catch (PieceBloqueeException) {
            return $this->indisponible(self::PIECE_BLOQUEE, 403);
        } catch (AntivirusInjoignableException $injoignable) {
            report($injoignable);

            return $this->indisponible(TelechargerUnePieceDuFournisseurController::ANTIVIRUS_INJOIGNABLE, 503)->header('Retry-After', '300');
        }

        return Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->download(ValeurRequise::de($trouve->chemin, 'le fichier du téléchargement prêt'), $trouve->nomDuFichier(), EnTetesDesFichiers::fichierRecu($trouve->nomDuFichier(), $trouve->format->typeMime()));
    }

    /**
     * Les pièces du fournisseur que contient le fichier, revues avant de le
     * servir : aucune ne doit être bloquée ni introuvable, et chacune dont
     * la dernière analyse date de plus de 24 heures repasse par
     * l'antivirus, au nom de ce compte, dans le contexte de son magasin
     * (voir PiecesDuFournisseur::contenuServable()). Une lecture hors du
     * magasin courant, filtrée par les magasins du fichier, que les droits
     * ont déjà contrôlés dans la société de la demande.
     *
     * @param  Collection<int, Magasin>  $magasins
     *
     * @throws PieceBloqueeException
     * @throws AntivirusInjoignableException
     */
    private function revoirLesPieces(User $compte, TelechargementDeDocuments $telechargement, Collection $magasins, DocumentsDeLaPeriode $documents, PiecesDuFournisseur $pieces, RevueAntivirus $revue): void
    {
        $identifiants = $telechargement->piecesContenues();

        if ($identifiants === []) {
            return;
        }

        $contenues = PieceDuFournisseur::query()->withoutGlobalScopes()->whereIn('magasin_id', $magasins->modelKeys())->whereIn('id', $identifiants)->get();

        if ($contenues->count() !== count(array_unique($identifiants)) || $contenues->contains(fn (PieceDuFournisseur $piece): bool => $piece->bloquee_le !== null)) {
            throw new PieceBloqueeException;
        }

        $parMagasin = $magasins->keyBy(fn (Magasin $magasin): int => CleDuModele::de($magasin));

        foreach ($contenues->filter(fn (PieceDuFournisseur $piece): bool => $revue->aRevoir(ValeurRequise::de($piece->analysee_le, 'l\'instant de la dernière analyse de la pièce'))) as $piece) {
            $documents->dansLeMagasin($parMagasin->get($piece->magasin_id) ?? throw new PieceBloqueeException, fn (): string => $pieces->contenuServable($compte, $piece));
        }
    }

    private function indisponible(string $message, int $statut): Response
    {
        return response()->view('telechargements.indisponible', ['message' => $message], $statut)->header('Referrer-Policy', 'no-referrer');
    }
}
