<?php

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Mechanisms\ComponentRegistry;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Une page d'un document du magasin, sa modification ou sa fiche, restée
 * ouverte pendant qu'une autre requête supprimait ce document (un
 * brouillon de facture, de facture d'achat, de devis ou d'inventaire
 * d'ouverture) : au geste suivant (« Enregistrer », « Supprimer », tout
 * bouton), Livewire relit le document de la page avant tout code de la
 * page (voir ModelSynth::hydrate() ; pour un devis, que sa suppression
 * garde en base, RevientALaListeSiLeDocumentEstSupprime), ne le trouve
 * plus, et la requête finirait sur une page introuvable. À la place, rien
 * ne s'exécute, et la personne revient à la liste de la ressource, qui lui
 * dit que ce document n'existe pas, ou plus, dans ce magasin
 * (REFUS_INTROUVABLE de son modèle, voir message()).
 *
 * La réponse est celle qu'attend Livewire : chaque composant de la requête
 * garde l'instantané que le navigateur a envoyé, avec la redirection vers
 * la liste (l'effet « redirect » de Livewire), et l'avis attend dans la
 * session que la liste l'affiche. Seule l'absence du document de la page
 * elle-même, dans le panneau d'un magasin, est ainsi rattrapée (voir
 * pageDuDocument()) ; toute autre absence reste une page introuvable,
 * comme l'adresse d'un document qui n'existe pas.
 */
final class ReponseAUnDocumentDisparu
{
    public const MESSAGE = 'Ce document n\'existe pas, ou plus, dans ce magasin.';

    public static function pour(NotFoundHttpException $exception, Request $request): ?JsonResponse
    {
        $introuvable = $exception->getPrevious();
        $composants = $request->input('components');

        if (! $introuvable instanceof ModelNotFoundException
            || ! $request->hasHeader('X-Livewire')
            || ! is_array($composants)
            || Filament::getCurrentPanel()?->getId() !== 'app') {
            return null;
        }

        $page = collect($composants)
            ->map(fn (mixed $composant): ?string => self::pageDuDocument($composant, $introuvable))
            ->filter()
            ->first();

        if ($page === null) {
            return null;
        }

        Notification::make()->title(self::message($introuvable->getModel()))->danger()->send();
        $liste = $page::getResource()::getUrl('index');

        return new JsonResponse([
            'components' => array_map(fn (mixed $composant): array => [
                'snapshot' => data_get($composant, 'snapshot'),
                'effects' => ['redirect' => $liste],
            ], array_values($composants)),
            'assets' => [],
        ]);
    }

    /**
     * Ce que dit l'écran d'un document de ce modèle qui n'est plus dans le
     * magasin : le refus de son modèle quand il en a un.
     *
     * @param  class-string  $modele
     */
    public static function message(string $modele): string
    {
        return defined("{$modele}::REFUS_INTROUVABLE") ? constant("{$modele}::REFUS_INTROUVABLE") : self::MESSAGE;
    }

    /**
     * La page de ce composant de la requête, quand son document est celui
     * qui n'a pas été trouvé : son instantané, à la somme de contrôle
     * vérifiée, désigne un document de ce modèle que le magasin ne voit
     * plus (lu par son modèle, MagasinScope compris ; un devis supprimé y
     * manque aussi, voir RevientALaListeSiLeDocumentEstSupprime), et son
     * composant est une page de la ressource de ce modèle. Livewire ne dit
     * pas quelle clé il cherchait (voir ModelSynth::hydrate()).
     *
     * @return class-string<Page>|null
     */
    private static function pageDuDocument(mixed $composant, ModelNotFoundException $introuvable): ?string
    {
        $instantane = json_decode((string) data_get($composant, 'snapshot'), true);
        $document = data_get($instantane, 'data.record.1');

        if (! is_array($instantane) || ! is_array($document) || ($document['s'] ?? null) !== 'mdl' || ! isset($document['class'], $document['key'])) {
            return null;
        }

        $modele = Relation::getMorphedModel($document['class']) ?? $document['class'];

        try {
            Checksum::verify($instantane);
            $page = app(ComponentRegistry::class)->getClass(data_get($instantane, 'memo.name'));
        } catch (CorruptComponentPayloadException|ComponentNotFoundException) {
            return null;
        }

        return $modele === $introuvable->getModel()
            && is_subclass_of($page, Page::class)
            && $page::getResource()::getModel() === $modele
            && $modele::query()->whereKey($document['key'])->doesntExist()
            ? $page
            : null;
    }
}
