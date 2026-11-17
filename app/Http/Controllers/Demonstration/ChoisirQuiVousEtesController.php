<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Actions\ChangerDeCompteAction;
use App\Domain\Demonstration\Exceptions\ChangementDeCompteRefuse;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Demonstration\Visites\BandeauDeLaDemonstration;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Demonstration\Visites\Visite;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Filament\EspaceDeLaSociete;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Choisissez qui vous êtes », le changement de compte et « Quitter »
 * dans une copie (lot DEMO-COPIE, C.8). Chaque route est du groupe web
 * (session, jeton CSRF), derrière OuvrirLaCopie : sans témoin valable, la
 * page introuvable. La classe refuse hors du site de démonstration.
 *
 * - GET /choisir : les comptes de la société de la copie, groupés, le
 *   propriétaire en premier avec « Commencer en tant que propriétaire »,
 *   le message à montrer de la copie, la date de fin, le repère et le mot
 *   de passe de démonstration. Quand le témoin a déjà choisi un compte,
 *   qui est encore au bandeau, la visite le rouvre aussitôt et mène à son
 *   accueil (choix 15), sauf juste après un changement refusé, que la page
 *   affiche ; un compte suspendu depuis efface ce choix.
 * - POST /demonstration/changer-de-compte : voir ChangerDeCompteAction ;
 *   ouvre ensuite l'accueil du compte choisi, comme une connexion (voir
 *   accueilDe()).
 * - POST /demonstration/quitter : retire le témoin de ce navigateur (au
 *   registre aussi : une valeur copiée avant ne marche plus), ferme la
 *   session, puis le dit, avec la date jusqu'où le lien marche.
 */
final class ChoisirQuiVousEtesController
{
    public function page(Request $requete, ChangerDeCompteAction $changer): Response
    {
        $visite = self::visite();
        $erreur = session('erreur_du_bandeau');
        $rappele = $erreur === null ? self::compteARouvrir($visite) : null;

        if ($rappele !== null) {
            $connecte = Auth::guard('web')->user();

            try {
                $choisi = $connecte instanceof User && $connecte->is($rappele) ? $connecte : PlaceDesCopies::pendantUnGestePermis(fn (): User => $changer->executer($requete, $visite->temoin, CleDuModele::de($rappele)));

                return Redirection::vers(self::accueilDe($choisi));
            } catch (ChangementDeCompteRefuse) {
                Temoin::query()->whereKey($visite->temoin->id)->update(['compte_choisi' => null]);
            }
        }

        return response()->view('demonstration.choisir', ['bandeau' => self::bandeau(), 'erreur' => is_string($erreur) ? $erreur : null])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Le compte choisi en dernier par ce témoin (la visite suivante le
     * rouvre, choix 15), s'il est encore un compte du bandeau ; null sans
     * choix, après une réfection de la copie (le choix est effacé), ou pour
     * un compte retiré depuis.
     */
    private static function compteARouvrir(Visite $visite): ?User
    {
        $compte = $visite->temoin->compte_choisi;

        if ($compte === null) {
            return null;
        }

        try {
            return ChangerDeCompteAction::compteDuBandeau($compte);
        } catch (ChangementDeCompteRefuse) {
            return null;
        }
    }

    public function changer(Request $requete, ChangerDeCompteAction $changer): RedirectResponse
    {
        $visite = self::visite();
        $compte = $requete->input('compte');

        try {
            $choisi = PlaceDesCopies::pendantUnGestePermis(fn (): User => $changer->executer($requete, $visite->temoin, is_numeric($compte) ? (int) $compte : 0));
        } catch (ChangementDeCompteRefuse $refus) {
            return Redirection::vers(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX, requete: $requete, aMontrer: ['erreur_du_bandeau' => $refus->getMessage()]);
        }

        return Redirection::vers(self::accueilDe($choisi));
    }

    /**
     * L'accueil d'un compte, comme après sa connexion : l'espace de son
     * cabinet ou de sa société pour qui l'ouvre (propriétaire,
     * administrateur, compte d'un cabinet), sinon le panneau des magasins.
     */
    public static function accueilDe(User $compte): string
    {
        return $compte->ouvreLEspaceDuCabinet() || $compte->peutAdministrer()
            ? EspaceDeLaSociete::accueil($compte->societe()->firstOrFail())
            : (Filament::getPanel('app')->getUrl() ?? route('filament.app.tenant'));
    }

    public function quitter(Request $requete, TemoinsDesVisites $temoins): Response
    {
        $visite = self::visite();
        $fin = Calendrier::deParis()->afficher($visite->copie->fin, 'd/m/Y');
        $temoins->retirer($visite->temoin);
        PlaceDesCopies::pendantUnGestePermis(function () use ($requete): void {
            Auth::guard('web')->logout();
            $requete->session()->invalidate();
            $requete->session()->regenerateToken();
        });

        return response()->view('demonstration.quittee', ['fin' => $fin])
            ->header('Cache-Control', 'no-store, private')
            ->withCookie($temoins->oublier());
    }

    private static function visite(): Visite
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        return Visite::enCours() ?? abort(404);
    }

    private static function bandeau(): BandeauDeLaDemonstration
    {
        self::visite();
        $compte = Auth::guard('web')->user();

        return BandeauDeLaDemonstration::enCours($compte instanceof User ? $compte : null) ?? abort(404);
    }
}
