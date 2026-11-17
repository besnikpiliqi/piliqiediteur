<?php

namespace App\Http\Middleware;

use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Exceptions\NomDeBaseHorsDuMotif;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Http\Controllers\Demonstration\EntrerDansLaDemonstrationController;
use Closure;
use Illuminate\Http\Request;
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
 * sa page d'attente, jamais une erreur technique. Un nom de base hors du
 * motif d'une copie, lu au registre (C.6.6), arrête la requête avec la page
 * « démonstration indisponible » et paraît dans l'état publié (C.19). Sur
 * le site réel, il ne fait rien.
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
            return redirect(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX);
        }

        try {
            $this->ouverture->ouvrir($copie);
        } catch (NomDeBaseHorsDuMotif) {
            EtatDuSite::signalerUnNomHorsDuMotif();

            return self::attente('indisponible', 503);
        }

        $this->temoins->voir($visite->temoin);

        return $next($request);
    }

    /**
     * La page d'attente d'une copie, en français, qui se relit seule tant
     * que la copie peut encore s'ouvrir.
     */
    public static function attente(string $cas, int $statut): Response
    {
        return response()->view('demonstration.attente', ['cas' => $cas], $statut)
            ->header('Cache-Control', 'no-store, private');
    }
}
