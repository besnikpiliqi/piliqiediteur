<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

/**
 * La page du panneau des magasins pour un expert-comptable d'une société
 * qui n'a encore aucun magasin réel (voir MenerUnCompteSansMagasin) : elle
 * le lui dit, sans erreur, et lui laisse se déconnecter. Un compte de
 * membre a toujours son magasin (retiré de son magasin, il est retiré de
 * la société et ne se connecte plus). Un compte qui a un magasin, ou qui
 * administre sa société, n'y reste pas : il va à l'accueil du panneau.
 */
class AucunMagasin extends SimplePage
{
    public const MESSAGE_EXPERT_COMPTABLE = 'Votre société n\'a encore aucun magasin : vous consulterez chacun d\'eux dès que le propriétaire l\'aura créé.';

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
        return self::MESSAGE_EXPERT_COMPTABLE;
    }

    private function compte(): User
    {
        $compte = Filament::auth()->user();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
