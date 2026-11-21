<?php

namespace App\Http\Middleware;

use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Exceptions\NomDeBaseHorsDuMotif;
use App\Domain\Demonstration\Jobs\RattraperLesTachesDUneCopie;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\TachesDesCopies;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Demonstration\Visites\Visite;
use App\Http\Controllers\Demonstration\EntrerDansLaDemonstrationController;
use App\Http\Controllers\Demonstration\Redirection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chaque requête du site de démonstration arrive à sa copie (lot
 * DEMO-COPIE, C.6) : intergiciel global, avant le groupe web, donc avant
 * StartSession (la session se lit déjà dans la bonne base). Il lit le
 * témoin, le cherche au registre, vérifie la copie (prête ou en lecture
 * seule, non échue, empreinte du schéma égale à celle du code, marque
 * « demonstration ») et branche la connexion, le cache, la session et les
 * disques sur elle (OuvertureDUneCopie). Sans témoin valable, seules
 * répondent les adresses d'entrée et les adresses internes, qui ne lisent
 * que le registre ; tout le reste répond la page introuvable, sans dire
 * qu'une démonstration existe. Une copie qui ne se sert pas encore montre
 * sa page d'attente, jamais une erreur technique. Une copie qu'aucune
 * passe de l'heure n'a faite (C.13) demande le rattrapage de ses tâches et
 * montre « Nous mettons votre démonstration à l'heure » jusqu'à sa fin ;
 * une requête Livewire, un geste en cours sur une page déjà ouverte,
 * passe sans attendre (Livewire montrerait la page d'attente comme une
 * erreur), et la page suivante attend la fin du rattrapage. Un
 * nom de base hors du
 * motif d'une copie, lu au registre (C.6.6), arrête la requête avec la page
 * « démonstration indisponible » et paraît dans l'état publié (C.19). Une
 * copie dont la base n'existe plus (copie perdue, E.7) demande une seule
 * fois sa réfection depuis le modèle et montre « Votre démonstration se
 * prépare », jamais une erreur technique ; la visite suivante, une fois la
 * copie refaite, lit le message de son motif. La
 * visite de la requête se lit ensuite par Visite::enCours() (le bandeau
 * « Vous êtes : », « Choisissez qui vous êtes »). Sur le site réel, il ne
 * fait rien.
 */
final class OuvrirLaCopie
{
    /**
     * Les adresses servies sans copie, qui ne lisent que le registre.
     */
    public const ADRESSES_SANS_COPIE = ['d', 'entrer', 'entrer-par-le-compte', 'interne/messages', 'interne/etat', 'robots.txt'];

    /**
     * Les pages fermées sur le site de démonstration (A.7, choix 4) : la
     * connexion, « Mot de passe oublié », l'inscription et la saisie du
     * code du courrier mènent à « Choisissez qui vous êtes » pour qui a le
     * témoin d'une copie, et répondent introuvable sans lui.
     */
    public const PAGES_FERMEES = ['app/login', 'societe/login', 'cabinet/login', 'app/password-reset/*', 'app/register', 'code', 'code/*', 'inscription/*'];

    public function __construct(
        private readonly TemoinsDesVisites $temoins,
        private readonly OuvertureDUneCopie $ouverture,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance(Visite::class);

        if (! SiteDePiliqi::estLaDemonstration() || in_array(trim($request->path(), '/'), self::ADRESSES_SANS_COPIE, true)) {
            return $next($request);
        }

        $visite = $this->temoins->retrouver($request->cookies->get(TemoinsDesVisites::nom()));

        if ($visite === null) {
            abort(404);
        }

        $copie = $visite->copie;

        if ($visite->estTerminee) {
            return self::attente('terminee', 410);
        }

        if (! $copie->etat->seSert()) {
            return match ($copie->etat) {
                EtatDUneCopie::EnAttenteDUnePlace => self::attente('en_attente_d_une_place', 503),
                EtatDUneCopie::EnMigration => self::attente('mise_a_jour', 503),
                default => self::attente('se_prepare', 503),
            };
        }

        if ($copie->empreinte_du_schema !== EmpreinteDuSchema::duCode()) {
            return self::attente('mise_a_jour', 503);
        }

        if ($request->is(...self::PAGES_FERMEES)) {
            return Redirection::vers(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX);
        }

        try {
            $this->ouverture->ouvrirJusquA($copie);
        } catch (NomDeBaseHorsDuMotif) {
            EtatDuSite::signalerUnNomHorsDuMotif();

            return self::attente('indisponible', 503);
        } catch (QueryException $erreur) {
            if (! self::baseAbsente((string) $copie->nom_de_la_base)) {
                throw $erreur;
            }

            RefaireUneCopie::demanderPourUneCopiePerdue($copie->reference);

            return self::attente('se_prepare', 503);
        }

        if (! TachesDesCopies::estALHeure($copie, CarbonImmutable::now())) {
            RattraperLesTachesDUneCopie::demander($copie->reference);
            $copie->refresh();

            if (! TachesDesCopies::estALHeure($copie, CarbonImmutable::now()) && ! $request->hasHeader('X-Livewire')) {
                return self::attente('a_l_heure', 503);
            }
        }

        $this->temoins->voir($visite->temoin);
        app()->instance(Visite::class, $visite);

        return $next($request);
    }

    /**
     * La base de la copie n'existe plus sur le serveur (une copie perdue,
     * E.7) : lu au catalogue de PostgreSQL par la connexion du registre,
     * que le rôle des pages a le droit de lire.
     */
    private static function baseAbsente(string $base): bool
    {
        return $base !== '' && ! DB::connection('registre')->table('pg_database')->where('datname', $base)->exists();
    }

    /**
     * La page d'attente d'une copie, en français, qui se relit seule tant
     * que la copie peut encore s'ouvrir. Rendue avant le routeur, elle dit
     * elle-même son type (le routeur le pose sur les autres réponses) :
     * sans lui, un serveur qui n'en ajoute pas la montrerait en texte brut.
     */
    public static function attente(string $cas, int $statut): Response
    {
        return response()->view('demonstration.attente', ['cas' => $cas], $statut)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private');
    }
}
