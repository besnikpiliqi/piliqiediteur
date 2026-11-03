<?php

namespace App\Domain\Cabinets\Mail;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La demande de rattachement d'un cabinet d'expertise comptable à une
 * société déjà inscrite, envoyée à l'adresse que le cabinet a saisie (voir
 * InviterUneEntrepriseAction et EnvoiDesLiensDesCabinets) : le cabinet qui
 * demande, le SIREN invité et rien d'autre de la société (ni sa
 * dénomination, ni son propriétaire) ; seul son propriétaire, connecté à
 * son compte, y répond, sur la page de rattachement, qui lui propose
 * d'accepter avec les autorisations par défaut, d'accepter en les
 * ajustant, ou de refuser ; le lien et son expiration.
 */
class DemandeDeRattachementMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly string $cabinet,
        public readonly ?string $villeDuCabinet,
        public readonly string $siren,
        public readonly string $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->cabinet} demande à suivre votre entreprise sur ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.demande-de-rattachement', with: [
            'lien' => $this->lien,
            'cabinet' => $this->cabinet,
            'villeDuCabinet' => $this->villeDuCabinet,
            'siren' => $this->siren,
            'expireLe' => $this->expireLe,
            'autorisations' => array_map(fn (string $cle): string => PermissionRegistry::libellesDuCabinet(TypeDActivite::Commerce)[$cle], PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)),
        ]);
    }
}
