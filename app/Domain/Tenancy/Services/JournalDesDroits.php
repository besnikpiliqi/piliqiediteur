<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;

/**
 * Inscrit chaque modification des droits d'une personne dans un magasin
 * (JET 130) au journal des événements du magasin, avec ses droits avant
 * et après : son rôle, son statut et ses autorisations dans ce magasin,
 * ou null quand elle n'y en a aucun (avant sa création, après son
 * retrait). Le propriétaire de la société a dans chacun de ses magasins
 * tous les droits sans case : son rôle de société, le statut de son
 * compte et toutes les autorisations ; un nouveau magasin d'une société
 * validée les inscrit, avant nul (voir CreerEtablissementAction).
 *
 * Une action qui modifie des droits, dans sa transaction et avec le
 * magasin pour magasin actif, appelle avant() avant de rien modifier, puis
 * apres() une fois la modification faite. avant() verrouille d'abord le
 * registre du magasin, dans l'ordre des verrous de RegistreFiscal, et
 * refuse la modification si le registre ne peut pas l'inscrire.
 */
class JournalDesDroits
{
    public function __construct(private readonly RegistreFiscal $registre) {}

    /**
     * Les droits de l'utilisateur dans le magasin avant la modification,
     * une fois le registre du magasin verrouillé ; null pour un compte qui
     * reste à créer.
     *
     * @return array{role: string, statut: string, permissions: list<string>}|null
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function avant(Magasin $magasin, ?User $utilisateur): ?array
    {
        $this->registre->verrouiller($magasin);

        return $utilisateur === null ? null : $this->droits($magasin, $utilisateur);
    }

    /**
     * Inscrit la modification, si les droits de l'utilisateur ne sont plus
     * ceux d'avant : une modification qui ne change rien n'en est pas une.
     *
     * @param  array{role: string, statut: string, permissions: list<string>}|null  $avant  ce qu'a donné avant()
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function apres(Magasin $magasin, User $utilisateur, ?array $avant): void
    {
        $apres = $this->droits($magasin, $utilisateur);

        if ($apres === $avant) {
            return;
        }

        $this->registre->journaliser(CodeEvenement::ModificationDesDroits, [
            'membre' => ['id' => $utilisateur->getKey(), 'nom' => $utilisateur->name],
            'avant' => $avant,
            'apres' => $apres,
        ]);
    }

    /**
     * Ses droits dans ce magasin, tels que la base les donne, les
     * autorisations par ordre alphabétique.
     *
     * @return array{role: string, statut: string, permissions: list<string>}|null
     */
    private function droits(Magasin $magasin, User $utilisateur): ?array
    {
        $compte = User::query()->findOrFail($utilisateur->getKey());

        if ($compte->estProprietaireDuMagasin($magasin)) {
            $toutes = PermissionRegistry::toutes();
            sort($toutes);

            return [
                'role' => $compte->role_societe->value,
                'statut' => $compte->statut->value,
                'permissions' => $toutes,
            ];
        }

        $membre = $magasin->membres()->where('user_id', $utilisateur->getKey())->first();

        if ($membre === null) {
            return null;
        }

        return [
            'role' => $membre->role->value,
            'statut' => $membre->statut->value,
            'permissions' => $utilisateur->autorisationsCocheesDans($magasin),
        ];
    }
}
