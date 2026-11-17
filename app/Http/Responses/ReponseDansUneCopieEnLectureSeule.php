<?php

namespace App\Http\Responses;

use App\Domain\Demonstration\Exceptions\CopieEnLectureSeule;
use App\Domain\Tenancy\Support\ValeursLues;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une écriture refusée dans une copie du site de démonstration en lecture
 * seule (lot DEMO-COPIE, C.16) : jamais une erreur technique. Rien n'est
 * écrit ; la personne reste sur la page d'où elle venait, qui dit pourquoi
 * (« Votre démonstration a atteint sa place… »), comme le bandeau de la
 * copie. Une requête Livewire garde l'instantané que le navigateur a
 * envoyé, avec la redirection vers la page d'où elle part (voir
 * ReponseDansUnMagasinEnLectureSeule) ; une autre requête revient à la page
 * précédente. Le site de démonstration n'a pas d'API (fermée).
 */
final class ReponseDansUneCopieEnLectureSeule
{
    public static function pour(CopieEnLectureSeule $refus, Request $request): Response
    {
        $avis = Notification::make()->title($refus->getMessage())->danger();

        if ($request->hasSession()) {
            // La session de la requête, celle de sa copie, que la réponse
            // enregistre : l'avis attend la page suivante.
            $request->session()->push('filament.notifications', $avis->toArray());
        }

        $precedente = url()->previous();
        $composants = $request->input('components');

        if ($request->hasHeader('X-Livewire') && is_array($composants)) {
            return new JsonResponse([
                'components' => array_map(fn (mixed $composant): array => [
                    'snapshot' => data_get($composant, 'snapshot'),
                    'effects' => ['redirect' => $precedente],
                ], array_values($composants)),
                'assets' => [],
            ]);
        }

        $reponse = new RedirectResponse($precedente);

        if ($request->hasSession()) {
            $reponse->setSession(ValeursLues::instance($request->session(), Store::class));
        }

        return $reponse;
    }
}
