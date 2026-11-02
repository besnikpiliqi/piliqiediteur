<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\DestinataireOfficiel;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\GenerateurDIdentifiants;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\UniteSirene;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Crée une société à la confirmation de son inscription (voir
 * InscriptionSociete et ConfirmerInscriptionController), tout ou rien :
 * d'abord les contrôles, sans rien écrire ; puis, en une seule
 * transaction, la société, son propriétaire et son courrier recommandé.
 *
 * Les contrôles : l'adresse n'est l'adresse de connexion d'aucun
 * propriétaire (refusée sans dire à qui elle appartient ; l'adresse de
 * contact d'un autre compte, une adresse en attente de son lien et celle
 * du propriétaire d'une inscription close n'empêchent rien) ; le SIREN
 * n'appartient à aucune autre société (une inscription qui attend son
 * code, ou une société validée, voir Societe::sirenPris()), refusé par le
 * seul MESSAGE_SIREN_PRIS ; l'entreprise existe et est active au répertoire
 * Sirene de l'INSEE, et, pour un cabinet d'expertise comptable, le
 * répertoire ne lui donne pas un autre code d'activité que 69.20Z en NAF
 * rév. 2 (voir ControleSirene::exerceLesActivitesComptables() ; un code
 * absent ou d'une autre nomenclature ne refuse rien) ; le registre
 * national des entreprises de l'INPI donne son représentant légal et
 * l'adresse officielle de son siège (voir DestinataireOfficiel). Une
 * entreprise cessée ou inconnue, ou un cabinet hors du code 69.20Z, est
 * refusé avec le motif du répertoire ; un répertoire, un registre ou un
 * prestataire d'envoi qui ne répond pas fait réessayer plus tard, et rien
 * n'est créé : une inscription n'existe jamais sans son courrier.
 *
 * La transaction : la société, en attente de son code, avec son type
 * d'activité, définitif dès cet instant (voir TypeDActivite), préremplie
 * depuis le répertoire Sirene (raison sociale, forme juridique tirée de la
 * catégorie juridique, siège, n° de TVA proposé), sans aucun magasin ; le
 * compte de son propriétaire, son adresse de connexion confirmée par le
 * lien de l'inscription, au nom du représentant légal que donne le
 * registre (il le change dans son profil s'il est une autre personne), et
 * son identifiant de connexion, tiré au hasard, jamais égal au mot de
 * passe qu'il a déjà choisi (voir GenerateurDIdentifiants) ; le contrôle
 * Sirene de l'entreprise au registre des vérifications ; l'inscription au
 * journal d'audit, avec le type et le code d'activité lu au répertoire et
 * sa nomenclature, enregistrés nulle part ailleurs (son origine :
 * « inscription », la société s'inscrit elle-même) ; pour un cabinet
 * d'expertise comptable, sa ligne de vérification (voir Cabinet), avec ce
 * code, contrôlé quand il est 69.20Z en NAF rév. 2, sinon à contrôler par
 * l'éditeur, et ce contrôle au journal d'audit ; le courrier recommandé et
 * son code (voir
 * EnvoyerCodeDeConfirmationAction::premier()). Deux confirmations
 * simultanées du même SIREN n'en créent qu'une : l'index unique partiel
 * societes_siren_unique refuse la seconde, qui lit MESSAGE_SIREN_PRIS.
 */
class CreerSocieteAction
{
    public const MESSAGE_ADRESSE_INUTILISABLE = 'Cette adresse e-mail ne peut pas être utilisée.';

    /**
     * Ce que lit la personne qui inscrit un SIREN déjà pris, et rien
     * d'autre : ni le nom, ni la date, ni l'état de la société qui le
     * garde.
     */
    public const MESSAGE_SIREN_PRIS = 'Ce SIREN ne peut pas être inscrit. S\'il s\'agit de votre entreprise, le code qui valide son inscription est dans le courrier recommandé envoyé à son représentant légal, à l\'adresse de son siège ; vous pouvez aussi contacter l\'éditeur.';

    public const MESSAGE_REPERTOIRE_INDISPONIBLE = 'Le répertoire Sirene de l\'INSEE ne répond pas pour le moment : réessayez dans quelques minutes.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly DestinataireOfficiel $destinataireOfficiel,
        private readonly JournalAudit $journalAudit,
        private readonly GenerateurDIdentifiants $generateur,
        private readonly EnvoyerCodeDeConfirmationAction $envoyerCode,
    ) {}

    /**
     * La société d'une nouvelle inscription, avec le compte de son
     * propriétaire et son premier courrier.
     *
     * @param  string  $motDePasse  le mot de passe en clair, ou déjà haché par la demande d'inscription (voir InscriptionSociete), qui l'a contrôlé
     *
     * @throws ValidationException
     */
    public function handle(string $siren, TypeDActivite $type, string $email, #[SensitiveParameter] string $motDePasse): Societe
    {
        $valides = Validator::make(['siren' => $siren, 'email' => $email, 'password' => $motDePasse], [
            'siren' => ['required', 'string', new SirenValide],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ])->validate();
        $email = User::normaliserEmail($valides['email']);
        $motDePasseHache = Hash::isHashed($motDePasse) ? $motDePasse : Hash::make($motDePasse);

        if (FournisseurDesComptes::proprietaireDeLAdresse($email) !== null) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]);
        }

        if (Societe::sirenPris($siren)) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_SIREN_PRIS]);
        }

        $consultation = $this->controleSirene->unite($siren);

        if ($consultation['resultat'] !== ResultatVerification::Concluante || $consultation['unite'] === null) {
            throw ValidationException::withMessages(['siren' => $consultation['motif'] ?? self::MESSAGE_REPERTOIRE_INDISPONIBLE]);
        }

        $unite = $consultation['unite'];

        if ($type === TypeDActivite::Cabinet && ControleSirene::exerceLesActivitesComptables($unite) === false) {
            throw ValidationException::withMessages(['type_activite' => ControleSirene::MOTIF_ACTIVITE_NON_COMPTABLE]);
        }

        try {
            $destinataire = $this->destinataireOfficiel->du($siren);
            $identite = $this->controleSirene->identiteDepuisSirene($unite);

            return DB::transaction(function () use ($siren, $type, $identite, $consultation, $unite, $destinataire, $email, $motDePasseHache): Societe {
                $nouvelle = tap((new Societe)->forceFill(['siren' => $siren, 'type_activite' => $type, ...$identite]))->save();

                return app(TenantContext::class)->runAsSociete($nouvelle, function () use ($nouvelle, $consultation, $unite, $destinataire, $email, $motDePasseHache): Societe {
                    $proprietaire = tap((new User([
                        'name' => $destinataire->nom,
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
                        'type_activite' => JournalAudit::valeur($nouvelle->type_activite),
                        'code_activite' => $unite->activitePrincipale,
                        'nomenclature_activite' => $unite->nomenclatureActivite,
                        'raison_sociale' => $nouvelle->raison_sociale,
                        'forme_juridique' => JournalAudit::valeur($nouvelle->forme_juridique),
                        'proprietaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                        'origine' => 'inscription',
                    ], $nouvelle);

                    if ($nouvelle->estUnCabinet()) {
                        $this->noterLActiviteDuCabinet($nouvelle, $proprietaire, $unite);
                    }

                    $this->envoyerCode->premier($nouvelle, $proprietaire, $destinataire);

                    return $nouvelle;
                });
            });
        } catch (VerificationRefuseeException $refus) {
            throw ValidationException::withMessages(['siren' => $refus->getMessage()]);
        } catch (UniqueConstraintViolationException $doublon) {
            // Une autre confirmation a pris ce SIREN ou cette adresse de
            // connexion depuis les contrôles ci-dessus : la même réponse,
            // et rien n'est créé.
            throw match (true) {
                str_contains($doublon->getMessage(), 'users_adresse_de_connexion') || str_contains($doublon->getMessage(), 'users.email') => ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_INUTILISABLE]),
                str_contains($doublon->getMessage(), 'societes_siren_unique') || str_contains($doublon->getMessage(), 'societes.siren') => ValidationException::withMessages(['siren' => self::MESSAGE_SIREN_PRIS]),
                default => $doublon,
            };
        }
    }

    /**
     * La ligne de vérification du cabinet qui naît (voir Cabinet), avec le
     * code d'activité que le répertoire Sirene lui donne à la confirmation :
     * contrôlé quand il est 69.20Z en NAF rév. 2 ; absent ou d'une autre
     * nomenclature, il reste à contrôler par l'éditeur, dans la procédure
     * de l'Ordre (voir ConstaterLInscriptionALOrdreAction). Un autre code de
     * cette nomenclature a déjà refusé l'inscription.
     */
    private function noterLActiviteDuCabinet(Societe $cabinet, User $proprietaire, UniteSirene $unite): void
    {
        $verification = new Cabinet;
        $resultat = $verification->noterSonActivite($unite);
        $verification->save();

        $this->journalAudit->inscrire(ActionAuditee::ActiviteDuCabinetControlee, $proprietaire, null, null, [
            'code_activite' => $unite->activitePrincipale,
            'nomenclature' => $unite->nomenclatureActivite,
            'resultat' => $resultat,
        ], $cabinet);
    }
}
