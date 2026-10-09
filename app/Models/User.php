<?php

namespace App\Models;

use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Le magasin de ce compte, avec son rôle et son statut (voir
     * MagasinUser) : un compte n'appartient qu'à un seul magasin (index
     * unique sur magasin_user.user_id). La relation reste une
     * BelongsToMany parce que Filament (HasTenants) attend une liste de
     * magasins.
     */
    public function magasins(): BelongsToMany
    {
        return $this->belongsToMany(Magasin::class, 'magasin_user')
            ->using(MagasinUser::class)
            ->withPivot(['role', 'statut', 'joined_at'])
            ->withTimestamps();
    }

    public function appartientA(Magasin $magasin): bool
    {
        return $this->magasins()
            ->wherePivot('magasin_id', $magasin->getKey())
            ->wherePivot('statut', StatutMembreMagasin::Actif->value)
            ->exists();
    }

    /**
     * Le rôle propriétaire ne s'obtient qu'en créant le magasin (voir
     * CreerMagasinAction) et ne se modifie jamais depuis l'équipe : seul
     * le propriétaire coche les autorisations des membres et change leur
     * rôle.
     */
    public function estProprietaireDe(Magasin $magasin): bool
    {
        return $magasin->proprietaires()->whereKey($this->getKey())->exists();
    }

    /**
     * Les autorisations cochées pour lui dans ce magasin, relues dans la
     * base, par ordre alphabétique : celles qui lui sont données
     * directement dans ce magasin (Spatie en mode teams). Le propriétaire,
     * lui, tient les siennes de son rôle.
     *
     * @return list<string>
     */
    public function autorisationsCocheesDans(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs(
            $magasin,
            fn (): array => $this->permissions()->orderBy('permissions.name')->pluck('permissions.name')->all(),
        );
    }

    /**
     * Efface ses notifications à propos de ce magasin (voir
     * NotificationDuMagasin) le jour où il n'y a plus accès : retiré de
     * l'équipe ou suspendu, il ne voit plus rien du magasin dans sa
     * cloche.
     */
    public function effacerLesNotificationsDu(Magasin $magasin): void
    {
        $this->notifications()->where('data->magasin_id', $magasin->getKey())->delete();
    }

    /**
     * Filament\Models\Contracts\HasTenants : le magasin que le panneau
     * « app » ouvre à ce compte, dans la liste qu'attend Filament ; vide
     * quand son rattachement n'est pas actif : un accès suspendu n'ouvre
     * pas la porte.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->magasins()
            ->wherePivot('statut', StatutMembreMagasin::Actif->value)
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Magasin && $this->appartientA($tenant);
    }

    /**
     * Sans cette implémentation explicite, Filament se rabat sur
     * `config('app.env') === 'local'` pour autoriser l'accès à un panneau :
     * l'accès ne doit jamais dépendre de l'environnement. Seul le panneau
     * des magasins existe, ouvert à qui est membre actif de son magasin ;
     * tout autre panneau reste fermé, jusqu'à ce que
     * l'administration de l'éditeur (étape 13 de la feuille de route)
     * définisse le sien.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app'
            && $this->magasins()->wherePivot('statut', StatutMembreMagasin::Actif->value)->exists();
    }
}
