<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crée une société à la confirmation de son inscription (voir
 * InscriptionSociete et ConfirmerInscriptionController), en une seule
 * transaction : la société, « à vérifier », préremplie depuis le
 * répertoire Sirene de l'INSEE (raison sociale, forme juridique tirée de
 * la catégorie juridique, siège, n° de TVA proposé) ; le compte de son
 * propriétaire, son adresse déjà confirmée par le lien de l'inscription ;
 * son premier magasin (voir CreerEtablissementAction) ; les contrôles
 * Sirene au registre des vérifications ; l'inscription au journal
 * d'audit.
 *
 * Que ce SIREN soit libre, en attente dans une autre inscription ou déjà
 * validé par une autre société, la société est créée de la même façon, à
 * vérifier, et rien n'en est révélé : seule une preuve de qualité départage
 * plus tard les sociétés d'un même SIREN.
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
        private readonly CreerEtablissementAction $creerEtablissement,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  array{siren?: mixed}  $societe
     * @param  array{nom?: mixed, code?: mixed, nic?: mixed}  $premierMagasin
     * @param  array{name?: mixed, email?: mixed, password?: mixed}  $proprietaire  le mot de passe en clair, ou déjà haché par la demande d'inscription (voir InscriptionSociete), qui l'a contrôlé
     *
     * @throws ValidationException
     */
    public function handle(array $societe, array $premierMagasin, array $proprietaire): Societe
    {
        $siren = Validator::make($societe, ['siren' => ['required', 'string', new SirenValide]])->validate()['siren'];
        $compte = Validator::make($proprietaire, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ])->validate();

        if (User::query()->where('email', User::normaliserEmail($compte['email']))->exists()) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
        }

        $consultation = $this->controleSirene->unite($siren);

        if ($consultation['resultat'] === ResultatVerification::Negative) {
            throw ValidationException::withMessages(['siren' => $consultation['motif']]);
        }

        $identite = $consultation['unite'] === null ? [] : $this->controleSirene->identiteDepuisSirene($consultation['unite']);

        try {
            return DB::transaction(function () use ($siren, $identite, $premierMagasin, $compte, $consultation): Societe {
                $nouvelle = tap((new Societe)->forceFill(['siren' => $siren, ...$identite]))->save();

                return app(TenantContext::class)->runAsSociete($nouvelle, function () use ($nouvelle, $premierMagasin, $compte, $consultation): Societe {
                    $proprietaire = tap((new User([
                        'name' => $compte['name'],
                        'email' => $compte['email'],
                        'password' => $compte['password'],
                    ]))->forceFill([
                        'societe_id' => $nouvelle->getKey(),
                        'role_societe' => RoleSociete::Proprietaire,
                        'statut' => StatutCompte::Actif,
                        'email_verified_at' => now(),
                    ]))->save();

                    $code = filled($premierMagasin['code'] ?? null) && is_string($premierMagasin['code'])
                        ? CodeMagasin::premierLibre($nouvelle, CodeMagasin::normaliser($premierMagasin['code']))
                        : null;
                    $magasin = $this->creerEtablissement->handle($proprietaire, $nouvelle, [...$premierMagasin, 'code' => $code]);

                    $this->controleSirene->noterUnite($nouvelle, $consultation, $proprietaire);
                    $this->journalAudit->inscrire(ActionAuditee::SocieteInscrite, null, null, [
                        'siren' => $nouvelle->siren,
                        'raison_sociale' => $nouvelle->raison_sociale,
                        'forme_juridique' => JournalAudit::valeur($nouvelle->forme_juridique),
                        'proprietaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                        'premier_magasin' => ['id' => $magasin->getKey(), 'nom' => $magasin->nom, 'siret' => $magasin->siret],
                    ], $nouvelle);

                    return $nouvelle;
                });
            });
        } catch (UniqueConstraintViolationException $doublon) {
            // Une autre confirmation a pris cette adresse depuis le
            // contrôle ci-dessus : la même réponse, et rien n'est créé.
            if (str_contains($doublon->getMessage(), 'users.email') || str_contains($doublon->getMessage(), 'users_email_unique')) {
                throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
            }

            throw $doublon;
        }
    }
}
