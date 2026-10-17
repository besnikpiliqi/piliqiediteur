<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

/**
 * La page du panneau des magasins pour un compte qui n'a encore aucun
 * magasin à ouvrir et n'administre pas sa société (voir
 * MenerUnCompteSansMagasin) : elle le lui dit, sans erreur, et lui laisse
 * se déconnecter. Un expert-comptable apprend que sa société n'a encore
 * aucun magasin réel ; un membre, que personne ne lui a encore ouvert de
 * magasin. Un compte qui a un magasin, ou qui administre sa société, n'y
 * reste pas : il va à l'accueil du panneau.
 */
class AucunMagasin extends SimplePage
{
    public const MESSAGE_EXPERT_COMPTABLE = 'Votre société n\'a encore aucun magasin : vous consulterez chacun d\'eux dès que le propriétaire l\'aura créé.';

    public const MESSAGE_MEMBRE = 'Vous n\'avez accès à aucun magasin de votre société pour le moment : le propriétaire de votre société ou le responsable de votre magasin vous y donnera accès.';

    protected static string $view = 'filament.app.pages.aucun-magasin';

    public static function registerRoutes(): void
    {
        Route::get('/aucun-magasin', static::class)->name('aucun-magasin');
    }

    public function mount(): void
    {
        $compte = $this->compte();

        if ($compte->peutAdministrer() || $compte->magasinsAccessibles()->isNotEmpty()) {
            $this->redirect(route('filament.app.tenant'));
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Aucun magasin';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Aucun magasin pour le moment';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->compte()->estExpertComptable() ? self::MESSAGE_EXPERT_COMPTABLE : self::MESSAGE_MEMBRE;
    }

    private function compte(): User
    {
        $compte = Filament::auth()->user();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
