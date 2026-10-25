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
 * vente, relances) : elle lit les paramètres en base à son ouverture et
 * après chaque enregistrement, et garde cette lecture sans que le
 * navigateur puisse la changer (voir FicheLue) ; l'enregistrement passe
 * par ModifierLesParametresDuMagasinAction, qui n'écrit que ce que la
 * personne a corrigé depuis, et relit sous verrou le magasin et son
 * auteur. Un refus se lit en notification, et la saisie reste à l'écran.
 */
trait EnregistreLesParametresDuMagasin
{
    use GereLesExceptionsMetier;

    /**
     * Les paramètres tels que la page les a lus (voir FicheLue::valeurs()).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $parametresLus = [];

    /**
     * Le magasin actif, relu en base : la page affiche et garde ce qu'il
     * vaut à cet instant.
     */
    protected function lireLesParametres(): Magasin
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant()->fresh();
        $this->parametresLus = FicheLue::de($magasin)->valeurs();

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
     * Enregistre la saisie avec l'autorisation de la page et l'annonce ;
     * faux quand l'enregistrement est refusé. La page relit ensuite les
     * paramètres (voir lireLesParametres()).
     *
     * @param  array<string, mixed>  $saisie
     */
    protected function enregistrerLesParametres(string $autorisation, array $saisie, string $annonce): bool
    {
        try {
            app(ModifierLesParametresDuMagasinAction::class)->handle(auth()->user(), $autorisation, $this->parametresLus, $saisie);
        } catch (LogicException|AuthorizationException $refus) {
            static::notifierLeRefus($refus);

            return false;
        }

        Notification::make()->title($annonce)->success()->send();

        return true;
    }
}
