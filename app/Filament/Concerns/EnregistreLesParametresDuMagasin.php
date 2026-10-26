<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\FicheLue;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * Une page de paramètres du magasin actif (coordonnées, factures, prix de
 * vente, relances), ouverte à qui a son autorisation (voir
 * autorisationDeLaPage()) : elle lit en base les paramètres que cette
 * autorisation règle, et seulement eux (voir
 * ModifierLesParametresDuMagasinAction::parametresDe()), à son ouverture
 * et après chaque enregistrement, et garde cette lecture sans que le
 * navigateur puisse la changer (voir FicheLue) ; le navigateur peut la
 * lire, et n'y trouve donc jamais le barème ou les relances d'une page
 * qu'il n'ouvre pas. L'enregistrement passe par
 * ModifierLesParametresDuMagasinAction, qui n'écrit que ce que la
 * personne a corrigé depuis, et relit sous verrou le magasin et son
 * auteur. Un refus se lit en notification, et la saisie reste à l'écran.
 * Un magasin passé en lecture seule pendant que la page était ouverte la
 * renvoie au tableau de bord, qui dit pourquoi (voir
 * ReponseDansUnMagasinEnLectureSeule).
 */
trait EnregistreLesParametresDuMagasin
{
    use GereLesExceptionsMetier;

    public const RIEN_N_EST_MODIFIE = 'Rien n\'a été modifié : une autre personne a changé ces paramètres depuis que vous avez ouvert la page. La page montre maintenant les paramètres actuels ; refaites votre correction si besoin.';

    public const AUTRES_PARAMETRES_CHANGES = 'Vos corrections sont enregistrées. Une autre personne avait changé entre-temps d\'autres paramètres de cette page, que vous n\'aviez pas corrigés : ils restent tels qu\'elle les a enregistrés, et la page les montre maintenant.';

    /**
     * Les paramètres tels que la page les a lus (voir FicheLue::valeurs()).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $parametresLus = [];

    /**
     * L'autorisation qui ouvre la page et règle ses paramètres :
     * PermissionRegistry::PARAMETRES_GERER, PRIX_VENTE_REGLER ou
     * RELANCES_REGLER.
     */
    abstract protected static function autorisationDeLaPage(): string;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(static::autorisationDeLaPage()) ?? false;
    }

    /**
     * Le magasin actif, relu en base : la page affiche ce qu'il vaut à cet
     * instant, et garde ses paramètres à elle.
     */
    protected function lireLesParametres(): Magasin
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant()->fresh();
        $this->parametresLus = FicheLue::de($magasin, ModifierLesParametresDuMagasinAction::parametresDe(static::autorisationDeLaPage()))->valeurs();

        return $magasin;
    }

    /**
     * Les paramètres tels que la page les a lus, que retrouve une saisie
     * abandonnée ; ce magasin-là ne s'enregistre jamais.
     */
    protected function parametresTelsQueLus(): Magasin
    {
        /** @var Magasin */
        return FicheLue::depuis(Magasin::class, $this->parametresLus)->fiche();
    }

    /**
     * Enregistre la saisie et relit les paramètres (voir
     * lireLesParametres()) : le magasin relu, que la page affiche ensuite,
     * ou null quand l'enregistrement est refusé. L'annonce de la page ne
     * se fait que si la page montre ensuite ce que la personne a saisi.
     * Sinon, une autre personne a changé entre-temps un paramètre que la
     * saisie ne corrigeait pas : il reste tel qu'elle l'a enregistré, et
     * la page le dit au lieu d'annoncer un état que la base ne vaut pas
     * (des relances « désactivées » qui partiront pourtant).
     *
     * @param  array<string, mixed>  $saisie
     */
    protected function enregistrerLesParametres(array $saisie, string $annonce): ?Magasin
    {
        try {
            $corrections = app(ModifierLesParametresDuMagasinAction::class)->handle(auth()->user(), static::autorisationDeLaPage(), $this->parametresLus, $saisie);
        } catch (LogicException|AuthorizationException $refus) {
            static::notifierLeRefus($refus);

            return null;
        }

        $magasin = $this->lireLesParametres();
        $ecarts = FicheLue::depuis(Magasin::class, $this->parametresLus)->corrections($saisie);

        match (true) {
            $ecarts === [] => Notification::make()->title($annonce)->success()->send(),
            $corrections === [] => Notification::make()->title(self::RIEN_N_EST_MODIFIE)->warning()->send(),
            default => Notification::make()->title(self::AUTRES_PARAMETRES_CHANGES)->warning()->send(),
        };

        return $magasin;
    }
}
