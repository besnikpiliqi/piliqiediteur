<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Support\MotDePasseProvisoire;
use App\Domain\Tenancy\Support\Reglage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Le mot de passe provisoire d'un compte que la société crée, ou dont elle
 * redonne l'accès en l'absence de la personne (décision de Claude D76,
 * T36.1 à T36.4, d'après la recommandation de la CNIL sur les mots de
 * passe : un secret créé par l'organisme est tiré au hasard, limité dans le
 * temps et changé à la première utilisation) :
 * - tiré au hasard (random_int de PHP, jamais la source des identifiants :
 *   voir SourceDeHasard), jamais l'identifiant du compte ni rien qui s'en
 *   déduise : douze caractères en trois groupes de quatre, lettres
 *   minuscules et chiffres sans ceux qu'on confond (« k7qp-2mzr-xa4d »),
 *   près de 60 bits ;
 * - montré une seule fois, à qui le remet en main propre : la base n'en
 *   garde que l'empreinte (le mot de passe du compte), jamais le clair, et
 *   ni l'e-mail, ni le journal d'audit, ni un journal technique ne le
 *   portent ;
 * - de courte durée : 72 heures (piliqi.mots_de_passe.provisoire_duree_en_heures) ;
 * - à usage unique : il ne vaut que pour la première connexion, qui mène
 *   la personne droit au choix de son mot de passe (voir
 *   ExigerSonPropreMotDePasse), et ne vaut plus ni après avoir servi ni
 *   après son délai ; l'accès se redonne alors, sans jamais bloquer un
 *   compte pour de bon ;
 * - accepté depuis un poste reconnu d'un magasin du compte seulement (voir
 *   PostesReconnus).
 * Ce service ne fait que tirer le mot de passe et dire les colonnes de
 * users à écrire pour chacun des états du mot de passe d'un compte (voir
 * EtatDuMotDePasse) ; les actions les écrivent sous le verrou du compte.
 */
final class MotsDePasseProvisoires
{
    /**
     * Les caractères d'un mot de passe provisoire : ni « 0 », « o », « 1 »,
     * « l », « i », qu'on confond sur un papier.
     */
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const GROUPES = 3;

    public const CARACTERES_PAR_GROUPE = 4;

    public const MESSAGE_CELUI_QUI_A_ETE_REMIS = 'Ce mot de passe est le mot de passe provisoire qui a été remis : choisissez-en un autre, que vous seul connaîtrez.';

    /**
     * Combien d'heures vaut un mot de passe provisoire.
     */
    public static function dureeEnHeures(): int
    {
        return Reglage::entier('piliqi.mots_de_passe.provisoire_duree_en_heures');
    }

    /**
     * Un mot de passe provisoire tiré au hasard, valable jusqu'à dans
     * dureeEnHeures() heures.
     */
    public function tirer(): MotDePasseProvisoire
    {
        $groupes = [];

        foreach (range(1, self::GROUPES) as $rang) {
            $groupe = '';

            foreach (range(1, self::CARACTERES_PAR_GROUPE) as $position) {
                $groupe .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groupes[] = $groupe;
        }

        return new MotDePasseProvisoire(implode('-', $groupes), CarbonImmutable::now()->addHours(self::dureeEnHeures()));
    }

    /**
     * Consomme le mot de passe provisoire de ce compte, à sa première
     * connexion : d'un seul geste atomique, seulement tant qu'il n'a pas
     * servi, que son délai n'est pas passé et que la personne n'a pas choisi
     * son mot de passe. Vrai pour la seule connexion qui l'a consommé ;
     * deux connexions simultanées avec le même mot de passe provisoire n'en
     * ouvrent qu'une.
     */
    public function consommer(User $compte): bool
    {
        return User::query()
            ->whereKey($compte->getKey())
            ->whereNull('mot_de_passe_choisi_le')
            ->whereNull('mot_de_passe_provisoire_utilise_le')
            ->where('mot_de_passe_provisoire_expire_le', '>', Instant::enBase(now()))
            ->update(['mot_de_passe_provisoire_utilise_le' => Instant::enBase(now())]) === 1;
    }

    /**
     * Ce mot de passe est-il celui qui a été remis à la personne, son mot de
     * passe provisoire encore en cours (valable, utilisé ou expiré) ? Il ne
     * se garde jamais : qui l'a remis le connaît.
     */
    public static function estCeluiQuiAEteRemis(User $compte, #[SensitiveParameter] string $motDePasse): bool
    {
        return ! $compte->aChoisiSonMotDePasse()
            && $compte->mot_de_passe_provisoire_expire_le !== null
            && (Hash::check($motDePasse, $compte->password) || Hash::check(self::normaliser($motDePasse), $compte->password));
    }

    /**
     * Refuse un mot de passe choisi qui est celui que la création du compte
     * ou « Redonner l'accès » a remis.
     *
     * @throws ValidationException
     */
    public static function exigerUnAutreQueCeluiQuiAEteRemis(User $compte, #[SensitiveParameter] string $motDePasse, string $champ): void
    {
        if (self::estCeluiQuiAEteRemis($compte, $motDePasse)) {
            throw ValidationException::withMessages([$champ => self::MESSAGE_CELUI_QUI_A_ETE_REMIS]);
        }
    }

    /**
     * Les comptes actifs qui n'ont pas encore choisi leur mot de passe, hors
     * les experts-comptables de la société, qui le choisissent par leur lien
     * (voir CompteExpertComptable) : le mot de passe provisoire n'a pas
     * servi, a expiré, ou n'a pas fini d'être choisi ; ou, pour un
     * collaborateur d'un cabinet, aucun mot de passe n'existe encore.
     *
     * @param  Builder<User>  $comptes
     * @return Builder<User>
     */
    public static function pasEncoreChoisis(Builder $comptes): Builder
    {
        return $comptes
            ->whereNull('users.mot_de_passe_choisi_le')
            ->where('users.statut', StatutCompte::Actif->value)
            ->where('users.role_societe', '<>', RoleSociete::ExpertComptable->value);
    }

    /**
     * Les comptes actifs dont le mot de passe provisoire vaut encore pour
     * une première connexion : il n'a pas servi et son délai n'est pas passé.
     * Ils ne s'ouvrent que depuis un poste reconnu (voir PostesReconnus).
     *
     * @param  Builder<User>  $comptes
     * @return Builder<User>
     */
    public static function provisoiresValables(Builder $comptes): Builder
    {
        return $comptes
            ->whereNull('users.mot_de_passe_choisi_le')
            ->whereNull('users.mot_de_passe_provisoire_utilise_le')
            ->where('users.mot_de_passe_provisoire_expire_le', '>', Instant::enBase(now()))
            ->where('users.statut', StatutCompte::Actif->value);
    }

    /**
     * Le mot de passe provisoire tel que la personne le tape : sans
     * espaces autour ni majuscules, qu'on ne distingue pas à la main.
     */
    public static function normaliser(#[SensitiveParameter] string $saisie): string
    {
        return mb_strtolower(trim($saisie));
    }

    /**
     * Les colonnes d'un compte qui reçoit ce mot de passe provisoire : le
     * clair devient son empreinte, la personne n'a pas (ou plus) choisi le
     * sien, et le provisoire n'a pas servi.
     *
     * @return array<string, mixed>
     */
    public static function colonnesDuProvisoire(MotDePasseProvisoire $provisoire): array
    {
        return [
            'password' => $provisoire->valeur(),
            'mot_de_passe_choisi_le' => null,
            'mot_de_passe_provisoire_expire_le' => $provisoire->expireLe,
            'mot_de_passe_provisoire_utilise_le' => null,
        ];
    }

    /**
     * Les colonnes d'un compte dont la personne vient de choisir le mot de
     * passe : plus rien de provisoire.
     *
     * @return array<string, mixed>
     */
    public static function colonnesDuMotDePasseChoisi(#[SensitiveParameter] string $motDePasse): array
    {
        return [
            'password' => $motDePasse,
            'mot_de_passe_choisi_le' => now(),
            'mot_de_passe_provisoire_expire_le' => null,
            'mot_de_passe_provisoire_utilise_le' => null,
        ];
    }

    /**
     * Les colonnes d'un compte qui n'a aucun mot de passe utilisable : un
     * mot de passe tiré au hasard que personne ne connaît, pour un
     * collaborateur d'un cabinet qui choisit le sien en présence de son
     * propriétaire, ou un expert-comptable de la société qui le choisit par
     * son lien.
     *
     * @return array<string, mixed>
     */
    public static function colonnesSansMotDePasse(): array
    {
        return [
            'password' => Str::random(64),
            'mot_de_passe_choisi_le' => null,
            'mot_de_passe_provisoire_expire_le' => null,
            'mot_de_passe_provisoire_utilise_le' => null,
        ];
    }
}
