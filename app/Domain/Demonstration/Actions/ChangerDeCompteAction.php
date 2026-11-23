<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Demonstration\Exceptions\ChangementDeCompteRefuse;
use App\Domain\Demonstration\Models\CompteDuBandeau;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Demonstration\Visites\SocieteDeLaCopie;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Middleware\ExigerSonPropreMotDePasse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Le changement de compte du bandeau « Vous êtes : » d'une copie (lot
 * DEMO-COPIE, C.8) : la seule exception aux règles du vrai logiciel, qui
 * saute le mot de passe et le poste reconnu ; tout le reste est le vrai
 * chemin d'une connexion.
 *
 * Le compte doit être de la copie, au bandeau, de la société de la copie
 * et non retiré ; un compte suspendu reçoit le refus de la connexion. Le
 * compte précédent se déconnecte par Auth::logout() (événement Logout :
 * JET 40 « fin de session » dans chaque magasin ouvert, voir
 * JournaliserFinDeSession), la session est invalidée et refaite, le
 * contexte de la société et du magasin effacé ; puis le compte choisi se
 * connecte comme après une connexion réussie (date de la connexion
 * précédente, première connexion signalée au propriétaire fictif, mot de
 * passe à choisir s'il le faut) ; son premier accès à un magasin
 * inscrira le JET 80 (voir JournaliserOuvertureDeSession). Le registre de
 * la copie dit ainsi quel opérateur a fait chaque vente (BOFiP § 150). Le
 * changement s'inscrit au journal d'audit de la société fictive comme une
 * connexion « depuis le bandeau de la démonstration », et le témoin de la
 * visite retient le compte choisi (la visite suivante le rouvre). Il
 * reste permis dans une copie en lecture seule (voir PlaceDesCopies), pour
 * y consulter sous chaque compte.
 */
final class ChangerDeCompteAction
{
    public const REFUS_COMPTE_INCONNU = 'Ce compte n\'est pas un compte de cette démonstration.';

    public const REFUS_COMPTE_SUSPENDU = 'Ce compte est suspendu : il ne peut pas se connecter. Réactivez-le depuis le compte du propriétaire pour l\'essayer.';

    public function __construct(
        private readonly JournalAudit $journal,
        private readonly SuiviDesConnexions $suivi,
        private readonly TenantContext $contexte,
    ) {}

    /**
     * @throws ChangementDeCompteRefuse pour un compte qui n'est pas un compte actif du bandeau de cette copie
     */
    public function executer(Request $requete, Temoin $temoin, int $compteId): User
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $compte = self::compteDuBandeau($compteId);

        if ($compte->estSuspendu()) {
            throw new ChangementDeCompteRefuse(self::REFUS_COMPTE_SUSPENDU);
        }

        PlaceDesCopies::pendantUnGestePermis(function () use ($requete, $temoin, $compte): void {
            Auth::guard('web')->logout();
            $requete->session()->invalidate();
            $requete->session()->regenerateToken();
            $this->contexte->clear();

            Auth::guard('web')->login($compte);
            $requete->session()->regenerate();
            $requete->session()->put(ExigerSonPropreMotDePasse::A_LA_CONNEXION, $compte->doitChoisirSonMotDePasse());
            $this->suivi->noterLaConnexion($compte);
            $societe = $compte->societe()->firstOrFail();
            $this->contexte->runAsSociete($societe, fn () => $this->journal->inscrire(
                ActionAuditee::ConnexionDepuisLeBandeau,
                $compte,
                null,
                null,
                ['compte' => ['id' => $compte->getKey(), 'nom' => $compte->name]],
            ));
            $this->contexte->clear();
            Temoin::query()->whereKey($temoin->id)->update(['compte_choisi' => $compte->getKey()]);
        });

        return $compte;
    }

    /**
     * Le compte de ce numéro, s'il est au bandeau de la copie, de sa
     * société et non retiré : un numéro forgé, celui d'un compte d'une
     * autre société de la même base (un client, dans la copie d'un
     * cabinet) ou d'un compte retiré ne désigne rien.
     *
     * @throws ChangementDeCompteRefuse
     */
    public static function compteDuBandeau(int $compteId): User
    {
        $societe = SocieteDeLaCopie::trouver() ?? throw new ChangementDeCompteRefuse(self::REFUS_COMPTE_INCONNU);
        $auBandeau = CompteDuBandeau::query()->withoutGlobalScopes()
            ->where('societe_id', $societe->getKey())
            ->where('user_id', $compteId)
            ->exists();
        $compte = $auBandeau ? User::query()->where('societe_id', $societe->getKey())->whereKey($compteId)->first() : null;

        if ($compte === null || $compte->estRetire()) {
            throw new ChangementDeCompteRefuse(self::REFUS_COMPTE_INCONNU);
        }

        return $compte;
    }
}
