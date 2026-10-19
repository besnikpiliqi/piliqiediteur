<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\GenerateurDIdentifiants;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crée une société à la confirmation de son inscription (voir
 * InscriptionSociete et ConfirmerInscriptionController), en une seule
 * transaction : la société, « à vérifier », préremplie depuis le
 * répertoire Sirene de l'INSEE (raison sociale, forme juridique tirée de
 * la catégorie juridique, siège, n° de TVA proposé) ; le compte de son
 * propriétaire, son adresse de connexion déjà confirmée par le lien de
 * l'inscription, et son identifiant de connexion, tiré au hasard, jamais
 * égal au mot de passe qu'il a déjà choisi (voir GenerateurDIdentifiants) ;
 * le contrôle Sirene de l'entreprise au registre des vérifications ;
 * l'inscription au journal d'audit. La société naît sans aucun magasin :
 * ni SIRET réservé, ni registre, ni série ; tous ses magasins se créent
 * ensuite dans son espace d'administration (voir CreerEtablissementAction).
 *
 * Que ce SIREN soit libre, en attente dans une autre inscription ou déjà
 * validé par une autre société, la société est créée de la même façon, à
 * vérifier, et rien n'en est révélé : seule une preuve de qualité départage
 * plus tard les sociétés d'un même SIREN. Une adresse déjà adresse de
 * connexion d'un autre propriétaire est refusée, sans dire à qui elle
 * appartient ; l'adresse de contact d'un autre compte n'empêche rien.
 *
 * Une entreprise cessée ou inconnue du répertoire est refusée ; quand
 * l'INSEE ne répond pas, la société est créée sans préremplissage et le
 * contrôle reprend ensuite (voir app:reverifier-sirene).
 */
class CreerSocieteAction
{
    public const MESSAGE_ADRESSE_INUTILISABLE = 'Cette adresse e-mail ne peut pas être utilisée.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly JournalAudit $journalAudit,
        private readonly GenerateurDIdentifiants $generateur,
    ) {}

    /**
     * La société d'une nouvelle inscription, avec le compte de son
     * propriétaire, créé ici.
     *
     * @param  array{siren?: mixed}  $societe
     * @param  array{name?: mixed, email?: mixed, password?: mixed}  $proprietaire  le mot de passe en clair, ou déjà haché par la demande d'inscription (voir InscriptionSociete), qui l'a contrôlé
     *
     * @throws ValidationException
     */
    public function handle(array $societe, array $proprietaire): Societe
    {
        $compte = Validator::make($proprietaire, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ])->validate();
        $email = User::normaliserEmail($compte['email']);
        $motDePasseHache = Hash::isHashed($compte['password']) ? $compte['password'] : Hash::make($compte['password']);

        if (FournisseurDesComptes::proprietaireDeLAdresse($email) !== null) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
        }

        $siren = Validator::make($societe, ['siren' => ['required', 'string', new SirenValide]])->validate()['siren'];
        $consultation = $this->controleSirene->unite($siren);

        if ($consultation['resultat'] === ResultatVerification::Negative) {
            throw ValidationException::withMessages(['siren' => $consultation['motif']]);
        }

        $identite = $consultation['unite'] === null ? [] : $this->controleSirene->identiteDepuisSirene($consultation['unite']);

        try {
            return DB::transaction(function () use ($siren, $identite, $consultation, $compte, $email, $motDePasseHache): Societe {
                $nouvelle = tap((new Societe)->forceFill(['siren' => $siren, ...$identite]))->save();

                return app(TenantContext::class)->runAsSociete($nouvelle, function () use ($nouvelle, $consultation, $compte, $email, $motDePasseHache): Societe {
                    $proprietaire = tap((new User([
                        'name' => $compte['name'],
                        'email' => $email,
                        'password' => $motDePasseHache,
                    ]))->forceFill([
                        'societe_id' => $nouvelle->getKey(),
                        'role_societe' => RoleSociete::Proprietaire,
                        'statut' => StatutCompte::Actif,
                        'email_verified_at' => now(),
                    ]))->save();
                    $this->generateur->attribuer($proprietaire, motDePasseHache: $motDePasseHache);

                    $this->controleSirene->noterUnite($nouvelle, $consultation, $proprietaire);
                    $this->journalAudit->inscrire(ActionAuditee::SocieteInscrite, $proprietaire, null, null, [
                        'siren' => $nouvelle->siren,
                        'raison_sociale' => $nouvelle->raison_sociale,
                        'forme_juridique' => JournalAudit::valeur($nouvelle->forme_juridique),
                        'proprietaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                    ], $nouvelle);

                    return $nouvelle;
                });
            });
        } catch (UniqueConstraintViolationException $doublon) {
            // Une autre confirmation a pris cette adresse de connexion depuis
            // le contrôle ci-dessus : la même réponse, et rien n'est créé.
            if (str_contains($doublon->getMessage(), 'users_adresse_de_connexion') || str_contains($doublon->getMessage(), 'users.email')) {
                throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
            }

            throw $doublon;
        }
    }
}
