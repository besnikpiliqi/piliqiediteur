<?php

namespace App\Models;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * Un compte n'appartient qu'à une seule société (societe_id) : son
 * propriétaire, qui a tous les droits dans la société et dans chacun de
 * ses magasins sans case ni rattachement (voir estProprietaire() et
 * ControleDesDroits), ou un membre de l'équipe d'un de ses magasins,
 * rattaché à ce magasin avec les autorisations cochées pour lui (voir
 * MagasinUser). Son rôle de société, son statut et sa société ne se
 * remplissent jamais par masse (hors de $fillable).
 *
 * Un compte ne s'efface pas : son adresse ne sert jamais à un autre
 * compte (index unique de users.email).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role_societe' => RoleSociete::class,
            'statut' => StatutCompte::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Une adresse e-mail de compte s'enregistre et se compare en
     * minuscules, sans espaces autour : « A@x.fr » et « a@x.fr » sont la
     * même adresse, qui ne sert jamais à un second compte (index unique de
     * users.email). La connexion et « Mot de passe oublié » la cherchent
     * ainsi (voir FournisseurDesComptes), et les formulaires la valident
     * déjà sous cette forme (voir la macro adresseDeConnexion()).
     */
    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (?string $email): ?string => $email === null ? null : self::normaliserEmail($email));
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /**
     * Le magasin de ce membre, avec son rôle et son statut (voir
     * MagasinUser) : un membre n'est rattaché qu'à un seul magasin (index
     * unique sur magasin_user.user_id). Le propriétaire n'y est rattaché à
     * aucun : il a accès à tous les magasins de sa société.
     */
    public function magasins(): BelongsToMany
    {
        return $this->belongsToMany(Magasin::class, 'magasin_user')
            ->using(MagasinUser::class)
            ->withPivot(['role', 'statut', 'joined_at'])
            ->withTimestamps();
    }

    public function estProprietaire(): bool
    {
        return $this->role_societe === RoleSociete::Proprietaire;
    }

    /**
     * Est-il le propriétaire de la société de ce magasin ?
     */
    public function estProprietaireDuMagasin(Magasin $magasin): bool
    {
        return $this->estProprietaire() && (int) $magasin->societe_id === (int) $this->societe_id;
    }

    public function estActif(): bool
    {
        return $this->statut === StatutCompte::Actif;
    }

    /**
     * Peut-il administrer sa société (son espace d'administration) ? Le
     * propriétaire actif seul.
     */
    public function peutAdministrer(): bool
    {
        return $this->estActif() && $this->estProprietaire();
    }

    /**
     * Peut-il ouvrir ce magasin ? Un magasin de sa société, avec un compte
     * actif : le propriétaire ouvre tous ceux de sa société, un membre
     * celui auquel il est rattaché, tant que son rattachement est actif.
     */
    public function peutOuvrir(Magasin $magasin): bool
    {
        if ((int) $magasin->societe_id !== (int) $this->societe_id || ! $this->estActif()) {
            return false;
        }

        return $this->estProprietaire() || $this->magasins()
            ->wherePivot('magasin_id', $magasin->getKey())
            ->wherePivot('statut', StatutMembreMagasin::Actif->value)
            ->exists();
    }

    /**
     * Les magasins qu'il peut ouvrir, par nom : tous ceux de sa société
     * pour le propriétaire, le sien pour un membre au rattachement actif ;
     * aucun pour un compte qui n'est pas actif.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsAccessibles(): Collection
    {
        if (! $this->estActif()) {
            return new Collection;
        }

        $magasins = $this->estProprietaire()
            ? Magasin::query()->where('societe_id', $this->societe_id)
            : $this->magasins()->wherePivot('statut', StatutMembreMagasin::Actif->value)->where('magasins.societe_id', $this->societe_id);

        return $magasins->orderBy('magasins.nom')->orderBy('magasins.id')->get();
    }

    /**
     * Les autorisations cochées pour ce membre dans ce magasin, relues
     * dans la base, par ordre alphabétique : celles qui lui sont données
     * directement dans ce magasin (Spatie en mode teams). Le propriétaire,
     * lui, a toutes les autorisations sans case (voir ControleDesDroits).
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
     * Filament\Models\Contracts\HasTenants : les magasins que le panneau
     * « app » lui ouvre (voir magasinsAccessibles()), et sa société dans
     * celui de l'administration de la société s'il peut l'administrer.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return match ($panel->getId()) {
            'app' => $this->magasinsAccessibles(),
            'societe' => $this->peutAdministrer() ? new Collection([$this->societe]) : new Collection,
            default => new Collection,
        };
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return match (true) {
            $tenant instanceof Magasin => $this->peutOuvrir($tenant),
            $tenant instanceof Societe => $tenant->getKey() === (int) $this->societe_id && $this->peutAdministrer(),
            default => false,
        };
    }

    /**
     * Sans cette implémentation explicite, Filament se rabat sur
     * `config('app.env') === 'local'` pour autoriser l'accès à un panneau :
     * l'accès ne doit jamais dépendre de l'environnement. Le panneau des
     * magasins s'ouvre à un compte actif qui a au moins un magasin à
     * ouvrir ; celui de l'administration de la société, à qui peut
     * l'administrer. Tout autre panneau reste fermé, jusqu'à ce que
     * l'administration de l'éditeur (étape 13 de la feuille de route)
     * définisse le sien.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'app' => $this->magasinsAccessibles()->isNotEmpty(),
            'societe' => $this->peutAdministrer(),
            default => false,
        };
    }
}
