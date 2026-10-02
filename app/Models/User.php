<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            ->withPivot(['role', 'statut', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function magasinActuel(): BelongsTo
    {
        return $this->belongsTo(Magasin::class, 'current_magasin_id');
    }

    public function appartientA(Magasin $magasin): bool
    {
        return $this->magasins()
            ->wherePivot('magasin_id', $magasin->getKey())
            ->wherePivot('statut', 'active')
            ->exists();
    }

    public function estProprietaireDe(Magasin $magasin): bool
    {
        return $this->magasins()
            ->wherePivot('magasin_id', $magasin->getKey())
            ->wherePivot('role', 'owner')
            ->exists();
    }

    /**
     * Filament\Models\Contracts\HasTenants : liste des magasins affichés
     * dans le sélecteur de tenant du panneau "app". Seuls les
     * rattachements actifs apparaissent -- un accès invité ou suspendu
     * n'ouvre pas la porte.
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
     * `config('app.env') === 'local'` pour autoriser l'accès à un panneau
     * -- ce qui passe silencieusement en local mais bloque tout (y
     * compris les tests, le staging et la production) avec un 403. On
     * n'a jamais voulu dépendre de l'environnement pour l'autorisation.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'app' => $this->magasins()->wherePivot('statut', StatutMembreMagasin::Actif->value)->exists(),
            // Le panneau Super Admin n'a pas encore de notion de "staff
            // plateforme" -- personne n'y accède tant que la Phase 7
            // (administration SaaS) ne l'aura pas modélisée explicitement.
            default => false,
        };
    }
}
