<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Ce que partage la création d'un compte par la société (voir
 * CreerMembreAction, CreerAdministrateurAction et
 * CreerExpertComptableAction) :
 * - son nom, et aucune adresse e-mail : seule la personne renseigne la
 *   sienne, dans son profil, jamais qui crée son compte ;
 * - son identifiant de connexion, tiré au hasard (voir
 *   GenerateurDIdentifiants), avec, pour un compte de membre, son magasin de
 *   rattachement, le seul qu'il ouvrira jamais ;
 * - son premier mot de passe, son identifiant lui-même, remis en main
 *   propre par qui l'a créé, jamais envoyé par e-mail ni journalisé :
 *   mot_de_passe_est_l_identifiant le dit, jusqu'à ce que la personne
 *   choisisse le sien ;
 * - le jeton de l'écran de création, tiré à son ouverture : une demande
 *   répétée après une réponse perdue rend le compte déjà créé par le même
 *   auteur pour la même demande, jamais un second compte ; le même jeton
 *   pour un autre auteur ou une autre demande est refusé, sans rien dire du
 *   compte (voir compteDejaCree()) ; l'index unique du jeton dans la
 *   société arrête une seconde demande qui serait passée quand même ;
 * - au plus 20 comptes par jour et par auteur, plus 10 par magasin réel
 *   ouvert de la société, comptés sous le verrou de l'auteur que prend
 *   JournalDesDroits : deux créations simultanées du même auteur se
 *   suivent et ne dépassent pas la limite ; une demande répétée n'y compte
 *   pas.
 * Le compte naît actif, créé par l'auteur (cree_par).
 */
final class CreationDesComptes
{
    public const COMPTES_PAR_JOUR = 20;

    public const COMPTES_PAR_MAGASIN_ET_PAR_JOUR = 10;

    public const MESSAGE_DEMANDE_DEJA_SERVIE = 'Cette demande a déjà servi.';

    public function __construct(private readonly GenerateurDIdentifiants $generateur) {}

    /**
     * Le nom validé ; tout autre champ (une adresse, un rôle) est ignoré.
     *
     * @param  array{name?: mixed}  $identite
     * @return array{name: string}
     *
     * @throws ValidationException
     */
    public function valider(array $identite): array
    {
        $valide = Validator::make(['name' => $identite['name'] ?? null], ['name' => ['required', 'string', 'max:255']])->validate();

        return ['name' => trim($valide['name'])];
    }

    /**
     * L'empreinte d'une demande de création : son rôle, son magasin, son nom
     * et ses autorisations, dans un ordre stable, sans aucun secret.
     *
     * @param  array<string, mixed>  $demande
     */
    public static function empreinte(array $demande): string
    {
        ksort($demande);

        return hash('sha256', json_encode($demande, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Le compte que ce jeton a déjà créé dans la société, pour une demande
     * répétée du même auteur avec la même empreinte ; null quand le jeton
     * n'a pas encore servi. À appeler après le contrôle des droits de
     * l'auteur relu, sous son verrou.
     *
     * @throws LogicException quand le jeton a servi à un autre auteur ou pour une autre demande
     */
    public function compteDejaCree(User $auteur, Societe $societe, ?string $jeton, string $empreinte): ?User
    {
        if ($jeton === null) {
            return null;
        }

        $identifiant = Identifiant::withoutGlobalScopes()->where('societe_id', $societe->getKey())->where('jeton_de_creation', $jeton)->first();

        if ($identifiant === null) {
            return null;
        }

        $compte = User::query()->where('societe_id', $societe->getKey())->find($identifiant->user_id);

        if ($compte === null || (int) $compte->cree_par !== (int) $auteur->getKey() || $identifiant->empreinte_de_creation !== $empreinte) {
            throw new LogicException(self::MESSAGE_DEMANDE_DEJA_SERVIE);
        }

        return $compte;
    }

    /**
     * Refuse une création de plus que la limite du jour de cet auteur, relu
     * sous son verrou.
     *
     * @throws ValidationException
     */
    public function verifierLaLimiteDuJour(User $auteur, Societe $societe): void
    {
        $limite = self::limiteDuJour($societe);

        if (User::query()->where('cree_par', $auteur->getKey())->where('created_at', '>=', now()->startOfDay())->count() >= $limite) {
            throw ValidationException::withMessages(['name' => self::messageDeLaLimite($limite)]);
        }
    }

    /**
     * Combien de comptes un même auteur crée par jour dans cette société :
     * 20, plus 10 par magasin réel ouvert.
     */
    public static function limiteDuJour(Societe $societe): int
    {
        $magasins = Magasin::query()
            ->where('societe_id', $societe->getKey())
            ->where('est_demonstration', false)
            ->where('statut', StatutMagasin::Actif->value)
            ->count();

        return self::COMPTES_PAR_JOUR + self::COMPTES_PAR_MAGASIN_ET_PAR_JOUR * $magasins;
    }

    public static function messageDeLaLimite(int $limite): string
    {
        return "Vous avez déjà créé {$limite} comptes aujourd'hui : réessayez demain.";
    }

    /**
     * Crée le compte et son identifiant, dans la transaction de l'appelant
     * et dans la société active : actif, son identifiant pour mot de passe,
     * créé par l'auteur, sans adresse.
     *
     * @param  array{name: string}  $identite
     *
     * @throws UniqueConstraintViolationException quand une autre demande du même jeton est passée entre-temps
     */
    public function creer(User $auteur, Societe $societe, array $identite, RoleSociete $role, ?Magasin $magasin, ?string $jeton, string $empreinte): User
    {
        $compte = tap((new User(['name' => $identite['name'], 'password' => Str::random(40)]))->forceFill([
            'societe_id' => $societe->getKey(),
            'role_societe' => $role,
            'statut' => StatutCompte::Actif,
            'cree_par' => $auteur->getKey(),
        ]))->save();

        $identifiant = $this->generateur->attribuer($compte, $magasin?->getKey(), $jeton, $empreinte);
        $compte->forceFill(['password' => $identifiant, 'mot_de_passe_est_l_identifiant' => true])->save();

        return $compte;
    }

    /**
     * Le doublon arrêté porte-t-il sur le jeton de création ?
     */
    public static function estUnJetonDejaServi(UniqueConstraintViolationException $doublon): bool
    {
        return str_contains($doublon->getMessage(), 'identifiants_societe_id_jeton_de_creation_unique')
            || str_contains($doublon->getMessage(), 'identifiants.jeton_de_creation');
    }

    /**
     * Les comptes suspendus ou retirés de ce magasin qui portent déjà ce
     * nom, sans tenir compte de la casse ni des espaces autour : l'écran de
     * création les propose avant d'en créer un autre (réactiver ou
     * réintégrer un ancien salarié).
     *
     * @return Collection<int, User>
     */
    public function comptesDuMemeNom(Magasin $magasin, string $nom): Collection
    {
        $nom = mb_strtolower(trim($nom));

        if ($nom === '') {
            return new Collection;
        }

        return User::query()
            ->where('societe_id', $magasin->societe_id)
            ->where('role_societe', RoleSociete::Membre->value)
            ->whereIn('statut', [StatutCompte::Suspendu->value, StatutCompte::Retire->value])
            ->whereIn('id', Identifiant::withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('magasin_id', $magasin->getKey())->select('user_id'))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $compte): bool => mb_strtolower(trim($compte->name)) === $nom)
            ->values();
    }
}
