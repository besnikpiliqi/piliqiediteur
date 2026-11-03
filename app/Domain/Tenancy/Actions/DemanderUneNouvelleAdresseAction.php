<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Une adresse de connexion du propriétaire ne sert qu'une fois confirmée
 * par le lien envoyé à cette adresse, valable 24 heures (voir
 * ConfirmerNouvelleAdresseAction) : jusque-là rien ne change. Elle attend
 * sur le compte, avec l'empreinte SHA-256 du jeton de son lien (voir
 * mettreEnAttente()), dans deux cas :
 * - le propriétaire demande, dans son profil et avec son mot de passe
 *   actuel (voir ModifierProfil et handle()), une nouvelle adresse de
 *   connexion, ou, s'il n'en a encore aucune, un nouveau lien pour celle
 *   qu'il a choisie, le premier ayant expiré ou n'ayant pas pu partir ;
 * - une autre personne que celle qui a inscrit la société saisit le code
 *   du courrier et devient propriétaire avec l'adresse qu'elle choisit
 *   (voir SaisirCodeDeConfirmationAction), qui attend sur son nouveau
 *   compte, le lien partant une fois la société validée (voir envoyer()).
 * Tout autre compte n'a qu'une adresse de contact, qu'il enregistre
 * directement dans son profil, sans confirmation. Le propriétaire d'une
 * inscription close n'a plus d'adresse de connexion et n'en demande plus
 * : son compte ne s'ouvre qu'avec son identifiant (voir
 * CloreLInscriptionAction). La demande relit sa société sous un verrou
 * partagé, avant d'écrire le compte, dans le même ordre que la clôture
 * (la société, puis le compte) : une clôture en cours la fait attendre,
 * puis la refuse ; une demande en cours fait attendre la clôture, qui
 * efface ensuite l'adresse en attente.
 *
 * Une nouvelle demande remplace la précédente, dont le lien ne sert plus.
 * Une adresse en attente ne bloque rien : celle qu'une personne tape reste
 * libre pour tout autre compte jusqu'à sa confirmation, qui revérifie
 * qu'elle n'est l'adresse de connexion d'aucun autre propriétaire. Chaque
 * lien compte au débit des e-mails de la personne (voir DebitDesEmails) :
 * son profil ne sert pas à écrire en masse à des adresses qu'elle tape.
 */
class DemanderUneNouvelleAdresseAction
{
    public const DUREE_DE_VALIDITE_EN_HEURES = 24;

    public const MESSAGE_INSCRIPTION_CLOSE = 'L\'inscription de votre société est close : votre compte n\'a plus d\'adresse de connexion et s\'ouvre seulement avec votre identifiant.';

    public function __construct(private readonly DebitDesEmails $debit) {}

    /**
     * La demande du profil, en une transaction (celle de l'appelant, s'il
     * en a une), sous le verrou partagé de la ligne de sa société.
     *
     * @throws ValidationException
     * @throws LogicException quand le compte n'est pas propriétaire, que son inscription est close, ou qu'il a atteint le débit de ses e-mails
     */
    public function handle(User $compte, string $nouvelleAdresse): void
    {
        if (! $compte->estProprietaire()) {
            throw new LogicException('Seul le propriétaire a une adresse de connexion à confirmer.');
        }

        DB::transaction(function () use ($compte, $nouvelleAdresse): void {
            if (Societe::query()->whereKey($compte->societe_id)->sharedLock()->value('close_le') !== null) {
                throw new LogicException(self::MESSAGE_INSCRIPTION_CLOSE);
            }

            $nouvelleAdresse = Validator::make(['email' => User::normaliserEmail($nouvelleAdresse)], ['email' => ['required', 'string', 'email', 'max:255']])->validate()['email'];
            $this->debit->verifier($compte);
            $jeton = $this->mettreEnAttente($compte, $nouvelleAdresse);
            $compte->save();
            $this->envoyer($compte, $jeton);
        });
    }

    /**
     * Pose sur ce compte de propriétaire, sans l'enregistrer, cette adresse
     * (normalisée et validée par l'appelant) en attente de confirmation,
     * avec l'empreinte du jeton de son lien et la fin de sa validité.
     *
     * @return string le jeton du lien, à envoyer par envoyer() une fois le compte enregistré
     */
    public function mettreEnAttente(User $compte, string $adresse): string
    {
        if (! $compte->estProprietaire()) {
            throw new LogicException('Seul le propriétaire a une adresse de connexion à confirmer.');
        }

        $jeton = Str::random(64);
        $compte->forceFill([
            'nouvelle_adresse' => $adresse,
            'nouvelle_adresse_jeton_hache' => ConfirmerNouvelleAdresseAction::empreinte($jeton),
            'nouvelle_adresse_expire_le' => now()->addHours(self::DUREE_DE_VALIDITE_EN_HEURES),
        ]);

        return $jeton;
    }

    /**
     * Envoie le lien de ce jeton à l'adresse en attente du compte, et le
     * compte au débit de ses e-mails. Le message dit s'il remplacera une
     * adresse de connexion, ou s'il faut le suivre connecté au compte, qui
     * n'en a encore aucune.
     */
    public function envoyer(User $compte, string $jeton): void
    {
        Mail::to((string) $compte->nouvelle_adresse)->send(new ConfirmationNouvelleAdresseMail(
            rtrim((string) config('app.url'), '/').route('profil.adresse.afficher', ['jeton' => $jeton], absolute: false),
            Calendrier::deParis()->afficher($compte->nouvelle_adresse_expire_le, 'd/m/Y à H:i'),
            premiereAdresse: ! $compte->aUneAdresseDeConnexion(),
        ));
        $this->debit->compter($compte);
    }
}
