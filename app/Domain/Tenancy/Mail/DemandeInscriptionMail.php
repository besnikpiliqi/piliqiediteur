<?php

namespace App\Domain\Tenancy\Mail;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien qui confirme une demande d'inscription (voir InscriptionSociete),
 * envoyé à l'adresse du futur propriétaire : rien n'est créé avant qu'il
 * ne l'ouvre et ne confirme (voir ConfirmerInscriptionController). Le lien
 * se construit depuis APP_URL, jamais depuis l'adresse de la requête. Il
 * rappelle le type d'activité choisi, qui peut encore changer sur la page
 * du lien et devient définitif à la confirmation (voir TypeDActivite).
 */
class DemandeInscriptionMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly CarbonInterface $expireLe,
        public readonly TypeDActivite $type,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez l\'inscription de votre société');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.inscription-demande',
            with: [
                'lien' => $this->lien,
                'expireLe' => Calendrier::deParis()->afficher($this->expireLe, 'd/m/Y à H:i'),
                'libelleDuType' => $this->type->getLabel(),
            ],
        );
    }
}
