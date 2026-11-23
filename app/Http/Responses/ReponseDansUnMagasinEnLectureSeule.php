<?php

namespace App\Http\Responses;

use App\Domain\Tenancy\Services\TenantContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Une page du magasin restée ouverte pendant qu'il passait en lecture
 * seule (fermé par sa société, suspendu par l'éditeur, sa société
 * suspendue, résiliée ou révoquée, son inscription close) : la
 * modification d'une fiche, une création, les paramètres du magasin, la
 * caisse ou une vente. Au geste suivant (« Sauvegarder », « Créer »,
 * « Enregistrer », tout bouton), la page redemande son droit d'écrire
 * avant tout geste (Filament, ou la page elle-même), que la lecture seule
 * refuse à tous (voir ControleDesDroits), et la requête finirait sur une
 * page interdite, sans rien dire. À la place, rien ne s'exécute, et la
 * personne revient au tableau de bord du magasin, que son bandeau marque
 * en lecture seule, avec le refus que reçoit un geste arrivé pendant la
 * fermeture elle-même (voir Magasin::refusEnLectureSeule()).
 *
 * La réponse est celle qu'attend Livewire (voir ReponseAUnDocumentDisparu) :
 * chaque composant de la requête garde l'instantané que le navigateur a
 * envoyé, avec la redirection vers le tableau de bord, et l'avis attend
 * dans la session que le tableau de bord l'affiche. Seul un refus d'une
 * requête Livewire du panneau d'un magasin en lecture seule est ainsi
 * rattrapé ; tout autre refus reste une page interdite, comme l'adresse
 * d'une page d'écriture d'un magasin en lecture seule.
 */
final class ReponseDansUnMagasinEnLectureSeule
{
    public static function pour(HttpExceptionInterface $exception, Request $request): ?JsonResponse
    {
        $composants = $request->input('components');
        $contexte = app(TenantContext::class);

        if ($exception->getStatusCode() !== 403
            || ! $request->hasHeader('X-Livewire')
            || ! is_array($composants)
            || Filament::getCurrentPanel()?->getId() !== 'app'
            || ! $contexte->has()) {
            return null;
        }

        $magasin = $contexte->magasin();
        $refus = $magasin->refusEnLectureSeule();

        if ($refus === null) {
            return null;
        }

        Notification::make()->title($refus)->danger()->send();
        $tableauDeBord = Filament::getUrl($magasin);

        return new JsonResponse([
            'components' => array_map(fn (mixed $composant): array => [
                'snapshot' => data_get($composant, 'snapshot'),
                'effects' => ['redirect' => $tableauDeBord],
            ], array_values($composants)),
            'assets' => [],
        ]);
    }
}
