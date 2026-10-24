<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le propriétaire demande, dans son profil et avec son mot de passe actuel
 * (voir ModifierProfil), une nouvelle adresse de connexion : rien ne change
 * encore. Tout autre compte n'a qu'une adresse de contact, qu'il enregistre
 * directement dans son profil, sans confirmation. La nouvelle adresse
 * attend sur le compte, avec l'empreinte SHA-256 du jeton du lien envoyé à
 * cette adresse, valable 24 heures ;
 * seul ce lien suivi la pose (voir ConfirmerNouvelleAdresseAction). Une
 * nouvelle demande remplace la précédente, dont le lien ne sert plus. Une
 * adresse en attente ne bloque rien : celle qu'une personne tape dans son
 * profil reste libre pour tout autre compte jusqu'à sa confirmation.
 * Chaque lien compte au débit des e-mails de la personne (voir
 * DebitDesEmails) : son profil ne sert pas à écrire en masse à des
 * adresses qu'elle tape.
 */
class DemanderUneNouvelleAdresseAction
{
    public const DUREE_DE_VALIDITE_EN_HEURES = 24;

    public function __construct(private readonly DebitDesEmails $debit) {}

    /**
     * @throws ValidationException
     * @throws LogicException quand le compte n'est pas propriétaire, ou a atteint le débit de ses e-mails
     */
    public function handle(User $compte, string $nouvelleAdresse): void
    {
        if (! $compte->estProprietaire()) {
            throw new LogicException('Seul le propriétaire a une adresse de connexion à confirmer.');
        }

        $nouvelleAdresse = Validator::make(['email' => User::normaliserEmail($nouvelleAdresse)], ['email' => ['required', 'string', 'email', 'max:255']])->validate()['email'];
        $this->debit->verifier($compte);
        $jeton = Str::random(64);
        $expireLe = now()->addHours(self::DUREE_DE_VALIDITE_EN_HEURES);

        $compte->forceFill([
            'nouvelle_adresse' => $nouvelleAdresse,
            'nouvelle_adresse_jeton_hache' => ConfirmerNouvelleAdresseAction::empreinte($jeton),
            'nouvelle_adresse_expire_le' => $expireLe,
        ])->save();

        Mail::to($nouvelleAdresse)->send(new ConfirmationNouvelleAdresseMail(
            rtrim((string) config('app.url'), '/').route('profil.adresse.afficher', ['jeton' => $jeton], absolute: false),
            $expireLe->setTimezone(config('app.timezone'))->format('d/m/Y à H:i'),
        ));
        $this->debit->compter($compte);
    }
}
