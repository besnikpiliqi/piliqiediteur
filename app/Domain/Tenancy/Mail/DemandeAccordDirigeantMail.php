<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La demande d'accord adressée à un dirigeant (voir
 * DemanderAccordDUnDirigeantAction) : qui la fait, pour quelle entreprise,
 * le lien pour l'examiner (valable sept jours), et l'information que lui
 * doit l'article 14 du RGPD, ses données venant du registre national des
 * entreprises et non de lui : qui les traite, pourquoi, d'où elles
 * viennent, combien de temps elles se gardent et comment s'y opposer.
 */
class DemandeAccordDirigeantMail extends Mailable
{
    public function __construct(
        public readonly string $nomDuDirigeant,
        public readonly string $nomDuDemandeur,
        public readonly string $societe,
        public readonly string $siren,
        public readonly string $lien,
        public readonly string $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Demande d'accord pour gérer le compte {$this->societe} sur ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.demande-accord-dirigeant', with: [
            'nomDuDirigeant' => $this->nomDuDirigeant,
            'nomDuDemandeur' => $this->nomDuDemandeur,
            'societe' => $this->societe,
            'siren' => $this->siren,
            'lien' => $this->lien,
            'expireLe' => $this->expireLe,
            'editeur' => config('editeur.email') ?: config('app.name'),
        ]);
    }
}
