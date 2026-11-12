<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Exceptions\LienDeLExpertComptableInvalideException;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * L'expert-comptable de la société choisit son mot de passe par le lien de
 * 7 jours envoyé à son adresse (voir EnvoiDesLiensDesExpertsComptables) :
 * le mot de passe suit la règle commune et ne contient pas son identifiant
 * (voir RegleDuMotDePasse) ; le lien ne sert qu'une fois, consommé sous le
 * verrou du compte puis de sa ligne d'attachement, relue : même empreinte,
 * échéance future, compte actif, déclaration en attente, société ni close
 * ni révoquée, sinon la page neutre (voir
 * LienDeLExpertComptableInvalideException). La date du premier mot de passe
 * choisi s'écrit une fois, ce qui ferme la correction de son adresse par le
 * propriétaire ; les autres sessions du compte se ferment. Aucune entrée
 * d'audit : la première connexion s'inscrit ensuite.
 */
class ChoisirLeMotDePasseDeLExpertComptableAction
{
    public function __construct(
        private readonly SessionsDesComptes $sessions,
        private readonly ExpertsComptablesDeclares $experts,
    ) {}

    /**
     * @throws LienDeLExpertComptableInvalideException
     * @throws ValidationException
     */
    public function handle(#[SensitiveParameter] string $jeton, #[SensitiveParameter] string $motDePasse, #[SensitiveParameter] string $confirmation): User
    {
        [$attachement, $compte, $societe] = $this->lienValable($jeton);

        Validator::make(['password' => $motDePasse, 'password_confirmation' => $confirmation], ['password' => ['required', 'string', 'confirmed']], attributes: ['password' => 'mot de passe'])->validate();
        RegleDuMotDePasse::exiger($motDePasse, $compte->identifiantDeConnexion());

        return app(TenantContext::class)->runAsSociete($societe, fn (): User => DB::transaction(function () use ($attachement, $compte, $jeton, $motDePasse): User {
            $compteRelu = User::query()->where('societe_id', $compte->societe_id)->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();
            $ligne = CompteExpertComptable::query()->whereKey($attachement->getKey())->lockForUpdate()->firstOrFail();
            $societeRelue = Societe::query()->findOrFail($compteRelu->societe_id);

            if (! hash_equals((string) $ligne->getAttribute('jeton_hache'), CompteExpertComptable::empreinte($jeton)) || ! $ligne->lienValable()
                || ! self::estOuvert($compteRelu, $societeRelue) || $this->experts->declarationDe($compteRelu)?->estDeclare() !== true) {
                throw new LienDeLExpertComptableInvalideException;
            }

            $compteRelu->forceFill(['password' => $motDePasse, 'mot_de_passe_est_l_identifiant' => false]);
            $this->sessions->fermerAvantDEnregistrer($compteRelu);
            $compteRelu->save();
            $ligne->forceFill([
                'jeton_hache' => null,
                'jeton_expire_le' => null,
                'mot_de_passe_choisi_le' => $ligne->mot_de_passe_choisi_le ?? now(),
            ])->save();

            return $compteRelu;
        }));
    }

    /**
     * La ligne, le compte et la société que désigne ce jeton, quand le lien
     * vaut encore : lus avant qu'aucune société ne soit ouverte, seulement
     * ce que la page du lien montre.
     *
     * @return array{0: CompteExpertComptable, 1: User, 2: Societe}
     *
     * @throws LienDeLExpertComptableInvalideException
     */
    public function lienValable(#[SensitiveParameter] string $jeton): array
    {
        $attachement = CompteExpertComptable::parSonJeton($jeton);
        $compte = $attachement === null ? null : User::query()->where('societe_id', $attachement->societe_id)->whereKey($attachement->user_id)->first(['id', 'societe_id', 'name', 'role_societe', 'statut']);
        $societe = $compte === null ? null : Societe::query()->find($compte->societe_id, ['id', 'raison_sociale', 'siren', 'close_le', 'statut']);

        if ($attachement === null || $compte === null || $societe === null || ! $attachement->lienValable() || ! self::estOuvert($compte, $societe)) {
            throw new LienDeLExpertComptableInvalideException;
        }

        $declaration = app(TenantContext::class)->runAsSociete($societe, fn () => $this->experts->declarationDe($compte));

        if ($declaration === null || ! $declaration->estDeclare()) {
            throw new LienDeLExpertComptableInvalideException;
        }

        return [$attachement, $compte, $societe];
    }

    private static function estOuvert(User $compte, Societe $societe): bool
    {
        return $compte->role_societe === RoleSociete::ExpertComptable && $compte->estActif() && $societe->close_le === null && $societe->statut !== StatutSociete::Revoquee;
    }
}
