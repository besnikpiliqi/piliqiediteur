<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\PreuveDeValidation;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\GenerateurDIdentifiants;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La saisie du code d'un courrier recommandé de vérification, sur la page
 * publique que le QR code du courrier ouvre (voir CodeDuCourrierController),
 * sans être connecté : le SIREN, le code, une adresse e-mail et un mot de
 * passe. C'est la seule preuve qui valide la société (voir
 * ValiderLaSocieteAction, PreuveDeValidation::CodeDuCourrier).
 *
 * Le code se cherche par le SIREN saisi parmi les codes de vérification
 * actifs (la seule lecture hors de toute société, avant qu'aucune ne soit
 * ouverte, limitée à ce SIREN), puis se compare à leur empreinte. Un SIREN
 * inconnu, sans courrier en cours ou un code faux reçoivent la même
 * réponse (MESSAGE_CODE_FAUX), après le même calcul d'empreinte ; une
 * saisie fausse se compte sur le code et s'inscrit au journal d'audit de
 * sa société, sans jamais l'annuler (un tiers ne bloque pas le courrier du
 * représentant légal) : la page limite les saisies par adresse IP
 * (SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP), et un code de douze caractères
 * (60 bits) ne se devine pas.
 *
 * Le bon code fait de la personne qui le saisit le propriétaire de la
 * société :
 * - l'adresse et le mot de passe de l'inscription disent que c'est la
 *   personne qui l'a faite : ses comptes restent ;
 * - sinon elle choisit son adresse e-mail (celle d'aucun autre
 *   propriétaire) et son mot de passe (voir RegleDuMotDePasse) : un
 *   nouveau compte de propriétaire est créé, à son nom tel que le courrier
 *   l'a nommée (le représentant légal), avec son identifiant ; tous les
 *   comptes créés avant dans la société, celui de la personne inscrite
 *   compris (qui devient administrateur), en sont retirés, leurs sessions
 *   fermées, à l'initiative du nouveau propriétaire (voir
 *   JournalDesDroits, ProprieteReprise). L'adresse de l'inscription avec
 *   un autre mot de passe est refusée (MESSAGE_MOT_DE_PASSE_DE_L_INSCRIPTION),
 *   sans compter le code.
 * Tout se fait en une transaction, sous les verrous des registres de tous
 * les magasins de la société, puis de sa ligne, puis du code : le code
 * sert une seule fois (deux saisies simultanées du bon code n'en servent
 * qu'une), son contrôle s'inscrit au registre des vérifications, et la
 * société est validée. Un magasin créé entre la lecture de leur liste et
 * ces verrous fait tout reprendre ; un échec de la validation (un SIRET
 * réservé entre-temps ailleurs) laisse le code valable et prévient
 * l'éditeur. Ensuite, la démonstration se ferme et le propriétaire est
 * prévenu (voir ValiderLaSocieteAction::apresLaValidation()).
 */
class SaisirCodeDeConfirmationAction
{
    public const SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP = 10;

    public const MESSAGE_CODE_FAUX = 'Ce SIREN et ce code ne correspondent à aucun courrier en cours.';

    public const MESSAGE_TROP_DE_SAISIES = 'Trop de saisies depuis cette adresse en une heure : réessayez plus tard.';

    public const MESSAGE_MOT_DE_PASSE_DE_L_INSCRIPTION = 'Ce n\'est pas le mot de passe de l\'inscription faite avec cette adresse : saisissez-le, ou choisissez une autre adresse.';

    public const MESSAGE_VALIDATION_IMPOSSIBLE = 'Votre société n\'a pas pu être validée : l\'éditeur a été prévenu et vous contactera. Votre code reste valable.';

    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

    private const MAGASINS_CHANGES = 'magasins_changes';

    /**
     * L'empreinte bcrypt (coût 12, celui de la production) d'une valeur
     * jetée, comparée quand aucun code n'est en cours pour le SIREN saisi :
     * la réponse vient après le même calcul.
     */
    private const EMPREINTE_FICTIVE = '$2y$12$DsTIOz2uxq3ZOvTZ5/8UueFYjy/ASR8QynhJHg6feOU/F7UBP1FRi';

    public function __construct(
        private readonly CodesDeConfirmation $codes,
        private readonly RegistreFiscal $registre,
        private readonly RegistreDesVerifications $registreDesVerifications,
        private readonly JournalAudit $journalAudit,
        private readonly JournalDesDroits $journalDesDroits,
        private readonly GenerateurDIdentifiants $generateur,
        private readonly SessionsDesComptes $sessions,
        private readonly JetonsDuLien $jetonsDuLien,
        private readonly ValiderLaSocieteAction $validerLaSociete,
        private readonly AlerteEditeur $alerteEditeur,
    ) {}

    /**
     * @return User le propriétaire de la société validée, que la page connecte
     *
     * @throws ValidationException
     */
    public function handle(string $siren, string $saisie, string $email, #[SensitiveParameter] string $motDePasse, #[SensitiveParameter] string $confirmation, string $adresseIp): User
    {
        $cle = 'saisie-code-ip:'.sha1($adresseIp);

        if (RateLimiter::tooManyAttempts($cle, self::SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP)) {
            throw ValidationException::withMessages(['code' => self::MESSAGE_TROP_DE_SAISIES]);
        }

        RateLimiter::hit($cle, 3600);
        $code = $this->codeJuste($siren, $saisie);
        $societe = Societe::query()->findOrFail($code->societe_id);
        $email = User::normaliserEmail($email);
        $inscrit = $societe->proprietaire()->first();
        $memePersonne = $inscrit !== null && $inscrit->email === $email;

        if ($memePersonne && ! Hash::check($motDePasse, $inscrit->password)) {
            throw ValidationException::withMessages(['password' => self::MESSAGE_MOT_DE_PASSE_DE_L_INSCRIPTION]);
        }

        if (! $memePersonne) {
            $this->exigerUnNouveauCompte($email, $motDePasse, $confirmation);
        }

        try {
            $proprietaire = $this->servir($societe, $code, $memePersonne ? null : ['email' => $email, 'password' => Hash::make($motDePasse), 'nom' => (string) ($code->destinataire['nom'] ?? $email)]);
        } catch (SiretDejaReserveException) {
            $this->alerteEditeur->alerter($societe, 'Validation impossible à la saisie du code du courrier', [
                'Le code du courrier est juste, mais l\'un des magasins de la société a un SIRET déjà réservé par une autre société : rien n\'a été validé, et le code reste valable.',
            ]);

            throw ValidationException::withMessages(['code' => self::MESSAGE_VALIDATION_IMPOSSIBLE]);
        }

        $societe = $societe->fresh();
        $this->validerLaSociete->apresLaValidation($societe, $proprietaire);

        return $proprietaire;
    }

    /**
     * Le code de vérification actif de ce SIREN que désigne la saisie ;
     * sinon chaque code actif de ce SIREN compte un essai faux, et la
     * réponse ne dit rien de plus.
     *
     * @throws ValidationException
     */
    private function codeJuste(string $siren, string $saisie): CodeConfirmation
    {
        $actifs = preg_match('/^[0-9]{9}$/D', $siren) !== 1 ? new Collection : CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->where('siren', $siren)
            ->where('motif', MotifCode::Verification->value)
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where('expire_le', '>', now())
            ->orderBy('id')
            ->get();

        if ($actifs->isEmpty()) {
            $this->codes->correspond($saisie, self::EMPREINTE_FICTIVE);

            throw ValidationException::withMessages(['code' => self::MESSAGE_CODE_FAUX]);
        }

        $juste = $actifs->first(fn (CodeConfirmation $code): bool => $this->codes->correspond($saisie, $code->code_hache));

        if ($juste === null) {
            $actifs->each(fn (CodeConfirmation $code) => $this->compterUnEssaiFaux($code));

            throw ValidationException::withMessages(['code' => self::MESSAGE_CODE_FAUX]);
        }

        return $juste;
    }

    /**
     * Compte un essai faux sur ce code, dans sa société, et l'inscrit à son
     * journal d'audit.
     */
    private function compterUnEssaiFaux(CodeConfirmation $code): void
    {
        app(TenantContext::class)->runAsSociete(Societe::query()->findOrFail($code->societe_id), fn () => DB::transaction(function () use ($code): void {
            $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->firstOrFail();
            $verrouille->forceFill(['tentatives' => $verrouille->tentatives + 1])->save();
            $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, null, null, null, ['motif' => $verrouille->motif->value, 'resultat' => 'faux', 'tentatives' => $verrouille->tentatives], $verrouille);
        }));
    }

    /**
     * L'adresse et le mot de passe choisis par une autre personne que celle
     * qui a inscrit la société.
     *
     * @throws ValidationException
     */
    private function exigerUnNouveauCompte(string $email, #[SensitiveParameter] string $motDePasse, #[SensitiveParameter] string $confirmation): void
    {
        Validator::make(['email' => $email, 'password' => $motDePasse, 'password_confirmation' => $confirmation], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => [...RegleDuMotDePasse::regles(null), 'confirmed'],
        ], attributes: ['email' => 'adresse e-mail', 'password' => 'mot de passe'])->validate();

        if (FournisseurDesComptes::proprietaireDeLAdresse($email) !== null) {
            throw ValidationException::withMessages(['email' => CreerSocieteAction::MESSAGE_ADRESSE_INUTILISABLE]);
        }
    }

    /**
     * Sert le code et valide la société, sous les verrous, en reprenant
     * tant qu'un magasin apparaît entre la lecture de leur liste et le
     * verrou de la société, quelques fois au plus.
     *
     * @param  array{email: string, password: string, nom: string}|null  $nouveauCompte  le compte que choisit une autre personne que celle qui a inscrit la société
     *
     * @throws ValidationException
     * @throws SiretDejaReserveException
     */
    private function servir(Societe $societe, CodeConfirmation $code, ?array $nouveauCompte): User
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $code, $nouveauCompte): User {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get();
                $proprietaire = $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $code, $nouveauCompte, $magasins): User|string {
                    $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

                    if (array_map(intval(...), Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->pluck('id')->all()) !== array_map(intval(...), $magasins->modelKeys())) {
                        return self::MAGASINS_CHANGES;
                    }

                    $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->first();

                    if ($verrouille === null || ! $verrouille->estActif() || ! $verrouillee->attendSonCode()) {
                        throw ValidationException::withMessages(['code' => self::MESSAGE_CODE_FAUX]);
                    }

                    $proprietaire = $nouveauCompte === null
                        ? User::query()->where('societe_id', $verrouillee->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->lockForUpdate()->firstOrFail()
                        : $this->reprendreLaSociete($verrouillee, $nouveauCompte);

                    $verrouille->forceFill(['utilise_le' => now()])->save();
                    $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $proprietaire, null, null, ['motif' => $verrouille->motif->value, 'resultat' => 'juste', 'tentatives' => $verrouille->tentatives + 1], $verrouille);
                    $this->registreDesVerifications->noter($verrouillee, TypeVerification::Courrier, ResultatVerification::Concluante, [
                        'siren' => $verrouille->siren,
                        'envoi_id' => $verrouille->getKey(),
                        'motif' => $verrouille->motif->value,
                        'source_adresse' => $verrouille->destinataire['adresse']['source'] ?? null,
                        'reference_envoi' => $verrouille->reference_envoi,
                    ], $proprietaire, fournisseur: 'recommande', reference: $verrouille->reference_envoi);
                    $this->validerLaSociete->valider($verrouillee, $proprietaire, PreuveDeValidation::CodeDuCourrier);

                    return $proprietaire;
                });

                if ($proprietaire instanceof User) {
                    return $proprietaire;
                }
            }

            throw ValidationException::withMessages(['code' => self::MESSAGE_VALIDATION_IMPOSSIBLE]);
        });
    }

    /**
     * Une autre personne que celle qui a inscrit la société saisit le code
     * : elle en devient le propriétaire, par un nouveau compte, et tous les
     * comptes créés avant en sont retirés, sous les verrous déjà pris (voir
     * JournalDesDroits::modifier(), qui s'inscrit au JET 130 de chaque
     * magasin et au journal d'audit). Dans cet ordre, à cause de l'index
     * d'un seul propriétaire par société : la personne inscrite devient
     * administrateur, sans portée ni autorisation ; le nouveau propriétaire
     * est créé, avec son identifiant ; chaque compte d'avant est retiré par
     * lui, ses droits supprimés, ses notifications effacées, ses sessions
     * fermées, et le lien « Mot de passe oublié » de l'ancienne adresse de
     * connexion s'efface.
     *
     * @param  array{email: string, password: string, nom: string}  $nouveauCompte
     */
    private function reprendreLaSociete(Societe $verrouillee, array $nouveauCompte): User
    {
        $comptes = User::query()->where('societe_id', $verrouillee->getKey())->where('statut', '<>', StatutCompte::Retire->value)->orderBy('id')->get()->all();
        $magasins = Magasin::query()->where('societe_id', $verrouillee->getKey())->orderBy('id')->get();

        $resultat = $this->journalDesDroits->modifier(null, ActionAuditee::ProprieteReprise, $comptes, $magasins, function (?User $systeme, array $relus) use ($verrouillee, $nouveauCompte): array {
            $inscrit = collect($relus)->first(fn (User $compte): bool => $compte->estProprietaire());

            if ($inscrit !== null) {
                $inscrit->forceFill([
                    'role_societe' => RoleSociete::Administrateur,
                    'nouvelle_adresse' => null,
                    'nouvelle_adresse_jeton_hache' => null,
                    'nouvelle_adresse_expire_le' => null,
                ])->save();
                $this->jetonsDuLien->effacer((string) $inscrit->email);
            }

            $nouveau = tap((new User([
                'name' => $nouveauCompte['nom'],
                'email' => $nouveauCompte['email'],
                'password' => $nouveauCompte['password'],
            ]))->forceFill([
                'societe_id' => $verrouillee->getKey(),
                'role_societe' => RoleSociete::Proprietaire,
                'statut' => StatutCompte::Actif,
            ]))->save();
            $this->generateur->attribuer($nouveau, motDePasseHache: $nouveauCompte['password']);

            foreach ($relus as $compte) {
                ChangerStatutCompteAction::retirerLesDroits($compte);
                $compte->forceFill($compte->nouveauStatut(StatutCompte::Retire, $nouveau))->save();
                $compte->effacerToutesSesNotifications();
                $this->sessions->fermer($compte);
            }

            return ['compte' => $nouveau];
        });

        return $resultat['compte'];
    }
}
