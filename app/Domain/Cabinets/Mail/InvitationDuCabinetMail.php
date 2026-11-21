<?php

namespace App\Domain\Cabinets\Mail;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'invitation d'un cabinet d'expertise comptable à une entreprise pas
 * encore inscrite, envoyée à l'adresse que le cabinet a saisie (voir
 * InviterUneEntrepriseAction et EnvoiDesLiensDesCabinets) : le cabinet qui
 * invite, le SIREN invité, ce que fait l'acceptation (la personne choisit
 * son mot de passe et le type d'activité, définitif ; la société est
 * validée sans courrier ni QR code ; ses données légales sont lues au
 * registre national), les autorisations par défaut, le lien, qui permet
 * aussi de refuser, et son expiration. Jamais d'identifiant de connexion.
 */
class InvitationDuCabinetMail extends Mailable
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
        return new Envelope(subject: "{$this->cabinet} vous invite sur ".Reglage::texte('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation-du-cabinet', with: [
            'lien' => $this->lien,
            'cabinet' => $this->cabinet,
            'villeDuCabinet' => $this->villeDuCabinet,
            'siren' => $this->siren,
            'expireLe' => $this->expireLe,
            'autorisations' => PermissionRegistry::libellesDe(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)),
        ]);
    }
}
