<?php

namespace App\Http\Controllers;

use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
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
 * pas servi. Le fichier part en pièce jointe, et le navigateur ne le garde
 * pas en cache.
 */
class TelechargerLesDocumentsController
{
    public const INTROUVABLE = 'Ce téléchargement n\'existe pas, ou il a été demandé par un autre compte.';

    public const EN_PREPARATION = 'Ce fichier est encore en préparation : une notification vous préviendra quand il sera prêt.';

    public const ECHEC = 'Ce fichier n\'a pas pu être préparé : demandez de nouveau les documents depuis la page « Télécharger les documents ».';

    public const EXPIRE = 'Ce lien a expiré : il valait sept jours après la demande. Demandez de nouveau les documents depuis la page « Télécharger les documents ».';

    public const SANS_DROIT = 'Vous ne téléchargez plus les documents de ces magasins, ou vous ne voyez plus certains des documents de ce fichier : il ne vous est plus servi.';

    public function __invoke(Request $requete, DocumentsDeLaPeriode $documents, string $telechargement): Response|StreamedResponse
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

        return Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->download(ValeurRequise::de($trouve->chemin, 'le fichier du téléchargement prêt'), $trouve->nomDuFichier(), [
            'Content-Type' => $trouve->format->typeMime(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function indisponible(string $message, int $statut): Response
    {
        return response()->view('telechargements.indisponible', ['message' => $message], $statut)->header('Referrer-Policy', 'no-referrer');
    }
}
