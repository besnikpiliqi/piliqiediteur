<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Exceptions\MessageRefuse;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Messages\ReceptionDesMessages;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Support\ValeurSimple;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Timebox;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'entrée dans une copie (lot DEMO-COPIE, C.4 et C.5).
 *
 * - GET /d : la page qui lit le secret après « # » (le navigateur ne
 *   l'envoie jamais au serveur), par le seul fichier
 *   /js/demonstration/entrer-dans-la-demonstration.js, sous sa propre
 *   politique de contenu, stricte ; le script l'envoie par POST /entrer,
 *   puis efface l'adresse.
 * - POST /entrer : hors du jeton CSRF et sans session, en-tête Origin du
 *   site de démonstration exigé, 10 essais par minute et par adresse IP
 *   (cache du registre). Le secret mène au lien actif de sa copie ; un
 *   témoin valable d'une autre copie demande d'abord une confirmation. Lien
 *   faux, mal formé, ancien ou d'une copie supprimée : la même réponse
 *   introuvable, après le même délai (auth.timebox_duration, compté depuis
 *   le début de la recherche) ; seul un lien valable répond sans attendre,
 *   comme une connexion réussie.
 * - POST /entrer-par-le-compte : le billet d'entrée signé du bouton du
 *   propriétaire, envoyé depuis la page du site réel (en-tête Origin égal à
 *   l'origine exacte du site réel), lu par ReceptionDesMessages (audience
 *   « entree », 60 secondes, nonce consommé) ; tout refus répond la page
 *   introuvable.
 *
 * Le témoin créé porte la génération courante du lien, puis la visite
 * passe à « Choisissez qui vous êtes ». Un navigateur qui a déjà un témoin
 * valable de la même copie le garde : la visite suivante rouvre ainsi le
 * compte qu'il avait choisi (choix 15).
 */
final class EntrerDansLaDemonstrationController
{
    public const ESSAIS_PAR_MINUTE = 10;

    public const CHEMIN_DU_CHOIX = '/choisir';

    public const POLITIQUE_DE_CONTENU = "default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    public const QUESTION_AUTRE_COPIE = 'Vous êtes déjà dans une autre démonstration (repère %s, créée le %s). Ouvrir celle de ce lien à la place ?';

    public const TROP_D_ESSAIS = 'Trop d\'essais depuis cette adresse : réessayez dans une minute.';

    public function page(): Response
    {
        return response()->view('demonstration.entrer')
            ->header('Content-Security-Policy', self::POLITIQUE_DE_CONTENU)
            ->header('Cache-Control', 'no-store, private');
    }

    public function entrer(Request $request, TemoinsDesVisites $temoins): Response
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        if ($request->headers->get('Origin') !== self::origineDuSite()) {
            abort(404);
        }

        $limiteur = new RateLimiter(Cache::store('registre'));
        $cle = 'demonstration-entrer:'.$request->ip();

        if ($limiteur->tooManyAttempts($cle, self::ESSAIS_PAR_MINUTE)) {
            return new JsonResponse(['message' => self::TROP_D_ESSAIS], 429);
        }

        $limiteur->hit($cle, 60);
        $secret = $request->input('secret');

        $copie = (new Timebox)->call(function (Timebox $timebox) use ($secret): ?Copie {
            $copie = self::copieDuLien(is_string($secret) ? $secret : '');

            if ($copie !== null) {
                $timebox->returnEarly();
            }

            return $copie;
        }, ValeurSimple::enEntier(config('auth.timebox_duration')));

        if ($copie === null) {
            return new JsonResponse(['message' => 'Introuvable.'], 404);
        }

        $actuelle = $temoins->retrouver($request->cookies->get(TemoinsDesVisites::nom()));

        if ($actuelle !== null && ! $actuelle->estTerminee && $actuelle->copie->id !== $copie->id && $request->input('confirme') !== true) {
            return new JsonResponse(['question' => sprintf(
                self::QUESTION_AUTRE_COPIE,
                $actuelle->copie->repere,
                Calendrier::deParis()->afficher($actuelle->copie->creee_le, 'd/m/Y'),
            )], 409);
        }

        if ($actuelle !== null && ! $actuelle->estTerminee && $actuelle->copie->id === $copie->id) {
            return new JsonResponse(['aller' => self::CHEMIN_DU_CHOIX]);
        }

        return (new JsonResponse(['aller' => self::CHEMIN_DU_CHOIX]))->withCookie($temoins->creer($copie, 'lien'));
    }

    public function entrerParLeCompte(Request $request, ReceptionDesMessages $reception, TemoinsDesVisites $temoins): Response
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $origine = ValeurSimple::enTexte(config('demonstration.origine_du_site_reel'));

        if ($origine === '' || $request->headers->get('Origin') !== $origine) {
            abort(404);
        }

        $limiteur = new RateLimiter(Cache::store('registre'));
        $cle = 'demonstration-entrer-par-le-compte:'.$request->ip();

        if ($limiteur->tooManyAttempts($cle, self::ESSAIS_PAR_MINUTE)) {
            abort(429);
        }

        $limiteur->hit($cle, 60);
        $message = $request->input('message');
        $signature = $request->input('signature');

        try {
            $champs = $reception->lire(is_string($message) ? $message : '', is_string($signature) ? $signature : '', MessagesSignes::AUDIENCE_DE_L_ENTREE);
        } catch (MessageRefuse) {
            abort(404);
        }

        $copie = Copie::query()->where('reference', $champs['reference'])->first();

        if ($copie === null || $copie->etat === EtatDUneCopie::Supprimee || $copie->estEchue(CarbonImmutable::now())) {
            abort(404);
        }

        $actuelle = $temoins->retrouver($request->cookies->get(TemoinsDesVisites::nom()));

        if ($actuelle !== null && ! $actuelle->estTerminee && $actuelle->copie->id === $copie->id) {
            return Redirection::vers(self::CHEMIN_DU_CHOIX, 303);
        }

        return Redirection::vers(self::CHEMIN_DU_CHOIX, 303)->withCookie($temoins->creer($copie, 'bouton'));
    }

    /**
     * La copie du lien actif de ce secret : génération courante, non
     * retiré, non échu, d'une copie qui n'est pas supprimée.
     */
    private static function copieDuLien(string $secret): ?Copie
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $secret) !== 1) {
            return null;
        }

        $lien = Lien::query()->with('copie')->where('empreinte', hash('sha256', $secret))->whereNull('retire_le')->first();
        $copie = $lien?->copie;

        if ($lien === null || $copie === null || $lien->generation !== $copie->generation_du_lien
            || $copie->etat === EtatDUneCopie::Supprimee || $copie->estEchue(CarbonImmutable::now())) {
            return null;
        }

        return $copie;
    }

    private static function origineDuSite(): string
    {
        $url = parse_url(ValeurSimple::enTexte(config('app.url')));

        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            return '';
        }

        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
    }
}
