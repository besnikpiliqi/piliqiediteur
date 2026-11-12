<?php

namespace App\Domain\Tenancy\Mail;

use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien envoyé à une adresse de connexion en attente (voir
 * DemanderUneNouvelleAdresseAction) : elle ne sert qu'une fois ce lien
 * suivi, dans les 24 heures (voir ConfirmerNouvelleAdresseAction). Une
 * nouvelle adresse demandée dans le profil remplace alors l'ancienne ; la
 * première adresse d'un propriétaire qui n'en a encore aucune (celle
 * choisie en saisissant le code du courrier) se confirme en suivant le
 * lien connecté à ce compte, avec son identifiant. Pour un expert-comptable
 * de la société ($adresseDeConnexion faux), l'adresse n'est pas une
 * adresse de connexion : elle reçoit les liens que le propriétaire de la
 * société lui envoie.
 */
class ConfirmationNouvelleAdresseMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly string $expireLe,
        public readonly bool $premiereAdresse = false,
        public readonly bool $adresseDeConnexion = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match (true) {
            ! $this->adresseDeConnexion => 'Confirmez votre nouvelle adresse sur '.Reglage::texte('app.name'),
            $this->premiereAdresse => 'Confirmez votre adresse de connexion à '.Reglage::texte('app.name'),
            default => 'Confirmez votre nouvelle adresse de connexion à '.Reglage::texte('app.name'),
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.confirmation-nouvelle-adresse', with: [
            'lien' => $this->lien,
            'expireLe' => $this->expireLe,
            'premiereAdresse' => $this->premiereAdresse,
            'adresseDeConnexion' => $this->adresseDeConnexion,
        ]);
    }
}
