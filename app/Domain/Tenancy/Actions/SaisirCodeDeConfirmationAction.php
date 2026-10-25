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
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * La saisie du code d'un courrier recommandé, sur la page publique que le
 * QR code du courrier ouvre (voir CodeDuCourrierController), sans être
 * connecté.
 *
 * Le code se cherche par le SIREN saisi parmi les codes actifs du motif de
 * la page (la seule lecture hors de toute société, avant qu'aucune ne soit
 * ouverte, limitée à ce SIREN et à ce motif : un seul code au plus, voir
 * CodeConfirmation), puis se compare à son empreinte. Un SIREN inconnu,
 * sans courrier en cours de ce motif ou un code faux reçoivent la même
 * réponse (MESSAGE_CODE_FAUX), après le même calcul d'empreinte ; une
 * saisie fausse se compte sur le code et s'inscrit au journal d'audit de
 * sa société, sans jamais l'annuler (un tiers ne bloque pas le courrier du
 * représentant légal) : les pages limitent ensemble les saisies par
 * adresse IP (SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP), et un code de douze
 * caractères (60 bits) ne se devine pas.
 *
 * Le code d'un courrier de vérification (voir handle()) se saisit avec une
 * adresse e-mail et un mot de passe. C'est la seule preuve qui valide la
 * société (voir ValiderLaSocieteAction, PreuveDeValidation::CodeDuCourrier),
 * et il fait de la personne qui le saisit le propriétaire de la société :
 * - l'adresse et le mot de passe de l'inscription disent que c'est la
 *   personne qui l'a faite : ses comptes restent ;
 * - sinon elle choisit son adresse e-mail et son mot de passe (voir
 *   RegleDuMotDePasse) : un nouveau compte de propriétaire est créé, à son
 *   nom tel que le courrier l'a nommée (le représentant légal), avec son
 *   identifiant ; tous les comptes créés avant dans la société, celui de
 *   la personne inscrite compris (qui devient administrateur), en sont
 *   retirés, leurs sessions fermées, à l'initiative du nouveau
 *   propriétaire (voir JournalDesDroits, ProprieteReprise). L'adresse
 *   choisie, refusée si elle est déjà l'adresse de connexion d'un autre
 *   propriétaire (sans rien dire d'autre que le refus), n'est pas encore
 *   son adresse de connexion : elle attend sur le nouveau compte, et le
 *   lien qui la confirme part une fois la société validée (voir
 *   DemanderUneNouvelleAdresseAction) ; il se suit connecté à ce compte,
 *   et la confirmation revérifie qu'aucun autre propriétaire ne l'a prise
 *   (voir ConfirmerNouvelleAdresseAction). D'ici là, ni la connexion par
 *   adresse ni « Mot de passe oublié » ne la reconnaissent : la personne
 *   se connecte avec son identifiant et son mot de passe. L'adresse de
 *   l'inscription avec un autre mot de passe est refusée
 *   (MESSAGE_MOT_DE_PASSE_DE_L_INSCRIPTION), sans compter le code.
 * Tout se fait en une transaction, sous les verrous des registres de tous
 * les magasins de la société, puis de sa ligne, puis du code : le code
 * sert une seule fois (deux saisies simultanées du bon code n'en servent
 * qu'une), son contrôle s'inscrit au registre des vérifications, et la
 * société est validée. Un magasin créé entre la lecture de leur liste et
 * ces verrous fait tout reprendre ; un échec de la validation (un SIRET
 * réservé entre-temps ailleurs) laisse le code valable et prévient
 * l'éditeur. Ensuite, la démonstration se ferme, le propriétaire est
 * prévenu (voir ValiderLaSocieteAction::apresLaValidation()) et le lien de
 * l'adresse choisie par une autre personne part (voir
 * envoyerLeLienDeLAdresseChoisie()) : un envoi qui échoue ne défait rien
 * et n'empêche pas la page de connecter la personne et de lui montrer son
 * identifiant ; l'adresse en attente s'efface, et le lien se redemande
 * depuis le profil.
 *
 * Le code d'un courrier d'une procédure de l'éditeur (voir
 * saisirUnCodeDeProcedure()) se saisit seul, sur la page de son motif : il
 * prouve que le courrier a été remis à l'adresse officielle du siège. Il
 * sert une seule fois, son contrôle s'inscrit au registre des
 * vérifications avec son motif, et l'éditeur est prévenu, qui poursuit la
 * procédure ; il ne valide ni ne change rien d'autre, et ne connecte
 * personne.
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
     * jetée, comparée quand aucun code n'est en cours pour le SIREN saisi
     * et le motif de la page : la réponse vient après le même calcul.
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
        private readonly DemanderUneNouvelleAdresseAction $adresseEnAttente,
    ) {}

    /**
     * Le code d'un courrier de vérification, avec l'adresse e-mail et le
     * mot de passe de la personne qui le saisit.
     *
     * @return User le propriétaire de la société validée, que la page connecte
     *
     * @throws ValidationException
     */
    public function handle(string $siren, string $saisie, string $email, #[SensitiveParameter] string $motDePasse, #[SensitiveParameter] string $confirmation, string $adresseIp): User
    {
        $this->limiterLesSaisies($adresseIp);
        $code = $this->codeJuste($siren, MotifCode::Verification, $saisie);
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
            [$proprietaire, $jeton] = $this->servir($societe, $code, $memePersonne ? null : ['email' => $email, 'password' => Hash::make($motDePasse), 'nom' => (string) ($code->destinataire['nom'] ?? $email)]);
        } catch (SiretDejaReserveException) {
            $this->alerteEditeur->alerter($societe, 'Validation impossible à la saisie du code du courrier', [
                'Le code du courrier est juste, mais l\'un des magasins de la société a un SIRET déjà réservé par une autre société : rien n\'a été validé, et le code reste valable.',
            ]);

            throw ValidationException::withMessages(['code' => self::MESSAGE_VALIDATION_IMPOSSIBLE]);
        }

        $societe = $societe->fresh();
        $this->validerLaSociete->apresLaValidation($societe, $proprietaire);

        if ($jeton !== null) {
            $this->envoyerLeLienDeLAdresseChoisie($societe, $proprietaire, $jeton);
        }

        return $proprietaire;
    }

    /**
     * Envoie, une fois la société validée, le lien de l'adresse que la
     * nouvelle propriétaire a choisie. Un envoi qui échoue (une messagerie
     * en panne) est signalé sans rien défaire : l'adresse en attente et son
     * lien s'effacent, pour que le profil ne dise pas qu'un lien est parti,
     * et la personne, que la page connecte quand même et à qui elle montre
     * son identifiant, redemande le lien depuis son profil (voir
     * CodeDuCourrierController).
     */
    private function envoyerLeLienDeLAdresseChoisie(Societe $societe, User $proprietaire, string $jeton): void
    {
        try {
            $this->adresseEnAttente->envoyer($proprietaire, $jeton);
        } catch (Throwable $erreur) {
            report($erreur);
            app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(fn () => ConfirmerNouvelleAdresseAction::oublierLAdresseEnAttente($proprietaire, $jeton)));
            $proprietaire->refresh();
        }
    }

    /**
     * Le code d'un courrier d'une procédure de l'éditeur, saisi seul sur la
     * page de son motif : servi sous le verrou de sa ligne, dans sa
     * société, puis l'éditeur est prévenu.
     *
     * @throws ValidationException
     */
    public function saisirUnCodeDeProcedure(string $siren, MotifCode $motif, string $saisie, string $adresseIp): void
    {
        if ($motif === MotifCode::Verification) {
            throw new LogicException('Le code d\'un courrier de vérification se saisit avec une adresse e-mail et un mot de passe.');
        }

        $this->limiterLesSaisies($adresseIp);
        $code = $this->codeJuste($siren, $motif, $saisie);
        $societe = Societe::query()->findOrFail($code->societe_id);

        app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($societe, $code): void {
            $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->first();

            if ($verrouille === null || ! $verrouille->estActif()) {
                throw ValidationException::withMessages(['code' => self::MESSAGE_CODE_FAUX]);
            }

            $this->noterLeCodeJuste($societe, $verrouille, null);
        }));

        $this->alerteEditeur->alerter($societe, 'Code d\'un courrier de l\'éditeur saisi', [
            "Le code du courrier recommandé « {$motif->getLabel()} » (référence {$code->reference_envoi}) a été saisi sur sa page : le courrier a été remis à l'adresse officielle du siège, et la procédure peut se poursuivre.",
        ]);
    }

    /**
     * Les saisies de code des pages, ensemble, par adresse IP.
     *
     * @throws ValidationException
     */
    private function limiterLesSaisies(string $adresseIp): void
    {
        $cle = 'saisie-code-ip:'.sha1($adresseIp);

        if (RateLimiter::tooManyAttempts($cle, self::SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP)) {
            throw ValidationException::withMessages(['code' => self::MESSAGE_TROP_DE_SAISIES]);
        }

        RateLimiter::hit($cle, 3600);
    }

    /**
     * Le code actif de ce SIREN et de ce motif que désigne la saisie ;
     * sinon le code actif de ce SIREN et de ce motif compte un essai faux,
     * et la réponse ne dit rien de plus.
     *
     * @throws ValidationException
     */
    private function codeJuste(string $siren, MotifCode $motif, string $saisie): CodeConfirmation
    {
        $actifs = preg_match('/^[0-9]{9}$/D', $siren) !== 1 ? new Collection : CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->where('siren', $siren)
            ->where('motif', $motif->value)
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
     * Sert ce code, verrouillé et actif, dans la transaction de l'appelant
     * : son usage, au journal d'audit de sa société, et le contrôle de
     * l'adresse officielle qu'il prouve, au registre des vérifications,
     * avec son motif.
     */
    private function noterLeCodeJuste(Societe $societe, CodeConfirmation $verrouille, ?User $auteur): void
    {
        $verrouille->forceFill(['utilise_le' => now()])->save();
        $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $auteur, null, null, ['motif' => $verrouille->motif->value, 'resultat' => 'juste', 'tentatives' => $verrouille->tentatives + 1], $verrouille);
        $this->registreDesVerifications->noter($societe, TypeVerification::Courrier, ResultatVerification::Concluante, [
            'siren' => $verrouille->siren,
            'envoi_id' => $verrouille->getKey(),
            'motif' => $verrouille->motif->value,
            'source_adresse' => $verrouille->destinataire['adresse']['source'] ?? null,
            'reference_envoi' => $verrouille->reference_envoi,
        ], $auteur, fournisseur: 'recommande', reference: $verrouille->reference_envoi);
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
     * @return array{0: User, 1: string|null} le propriétaire, et le jeton du lien de l'adresse en attente sur son nouveau compte
     *
     * @throws ValidationException
     * @throws SiretDejaReserveException
     */
    private function servir(Societe $societe, CodeConfirmation $code, ?array $nouveauCompte): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $code, $nouveauCompte): array {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get();
                $resultat = $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $code, $nouveauCompte, $magasins): array|string {
                    $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

                    if (array_map(intval(...), Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->pluck('id')->all()) !== array_map(intval(...), $magasins->modelKeys())) {
                        return self::MAGASINS_CHANGES;
                    }

                    $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->first();

                    if ($verrouille === null || ! $verrouille->estActif() || ! $verrouillee->attendSonCode()) {
                        throw ValidationException::withMessages(['code' => self::MESSAGE_CODE_FAUX]);
                    }

                    [$proprietaire, $jeton] = $nouveauCompte === null
                        ? [User::query()->where('societe_id', $verrouillee->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->lockForUpdate()->firstOrFail(), null]
                        : $this->reprendreLaSociete($verrouillee, $nouveauCompte);

                    $this->noterLeCodeJuste($verrouillee, $verrouille, $proprietaire);
                    $this->validerLaSociete->valider($verrouillee, $proprietaire, PreuveDeValidation::CodeDuCourrier);

                    return [$proprietaire, $jeton];
                });

                if (is_array($resultat)) {
                    return $resultat;
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
     * administrateur, sans portée ni autorisation, et sa date de
     * confirmation d'adresse s'efface (son adresse n'est plus qu'un
     * contact) ; le nouveau propriétaire est créé, avec son identifiant,
     * sans adresse de connexion : celle qu'il a choisie attend la
     * confirmation de son lien ; chaque compte d'avant est retiré par lui,
     * ses droits supprimés, ses notifications effacées, ses sessions
     * fermées, et le lien « Mot de passe oublié » de l'ancienne adresse de
     * connexion s'efface.
     *
     * @param  array{email: string, password: string, nom: string}  $nouveauCompte
     * @return array{0: User, 1: string} le nouveau propriétaire, et le jeton du lien de son adresse en attente
     */
    private function reprendreLaSociete(Societe $verrouillee, array $nouveauCompte): array
    {
        $comptes = User::query()->where('societe_id', $verrouillee->getKey())->where('statut', '<>', StatutCompte::Retire->value)->orderBy('id')->get()->all();
        $magasins = Magasin::query()->where('societe_id', $verrouillee->getKey())->orderBy('id')->get();

        $resultat = $this->journalDesDroits->modifier(null, ActionAuditee::ProprieteReprise, $comptes, $magasins, function (?User $systeme, array $relus) use ($verrouillee, $nouveauCompte): array {
            $inscrit = collect($relus)->first(fn (User $compte): bool => $compte->estProprietaire());

            if ($inscrit !== null) {
                $inscrit->forceFill([
                    'role_societe' => RoleSociete::Administrateur,
                    'email_verified_at' => null,
                    'nouvelle_adresse' => null,
                    'nouvelle_adresse_jeton_hache' => null,
                    'nouvelle_adresse_expire_le' => null,
                ])->save();
                $this->jetonsDuLien->effacer((string) $inscrit->email);
            }

            $nouveau = (new User([
                'name' => $nouveauCompte['nom'],
                'password' => $nouveauCompte['password'],
            ]))->forceFill([
                'societe_id' => $verrouillee->getKey(),
                'role_societe' => RoleSociete::Proprietaire,
                'statut' => StatutCompte::Actif,
            ]);
            $jeton = $this->adresseEnAttente->mettreEnAttente($nouveau, $nouveauCompte['email']);
            $nouveau->save();
            $this->generateur->attribuer($nouveau, motDePasseHache: $nouveauCompte['password']);

            foreach ($relus as $compte) {
                ChangerStatutCompteAction::retirerLesDroits($compte);
                $compte->forceFill($compte->nouveauStatut(StatutCompte::Retire, $nouveau))->save();
                $compte->effacerToutesSesNotifications();
                $this->sessions->fermer($compte);
            }

            return ['compte' => $nouveau, 'jeton' => $jeton];
        });

        return [$resultat['compte'], $resultat['jeton']];
    }
}
