<?php

namespace App\Models;

use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
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
     * Tous les magasins auxquels cet utilisateur a accès (propriétaire,
     * salarié, ou comptable multi-magasins), avec son rôle "de base" sur
     * chacun. C'est volontairement une relation many-to-many : un
     * utilisateur (typiquement un comptable) peut appartenir à plusieurs
     * magasins (voir app/Domain/Tenancy/Concerns/BelongsToMagasin.php pour
     * le pourquoi).
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
     * Ses notifications à propos de ce magasin (voir
     * NotificationDuMagasin), le jour où il n'y a plus accès -- retiré de
     * l'équipe, suspendu : sa cloche, commune à tous ses magasins, ne
     * montre plus rien d'un magasin qu'il ne peut plus ouvrir.
     */
    public function effacerLesNotificationsDu(Magasin $magasin): void
    {
        $this->notifications()->where('data->magasin_id', $magasin->getKey())->delete();
    }

    /**
     * Filament\Models\Contracts\HasTenants : liste des magasins affichés
     * dans le sélecteur de tenant du panneau "app". Seuls les
     * rattachements actifs apparaissent -- un accès suspendu n'ouvre pas
     * la porte.
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
     * des magasins existe, ouvert à qui est membre actif d'au moins un
     * magasin ; tout autre panneau reste fermé, jusqu'à ce que
     * l'administration de l'éditeur (étape 13 de la feuille de route)
     * définisse le sien.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app'
            && $this->magasins()->wherePivot('statut', StatutMembreMagasin::Actif->value)->exists();
    }
}
