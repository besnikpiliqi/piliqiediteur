<?php

namespace App\Domain\Cabinets\Mail;

use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien de 7 jours où un expert-comptable de la société choisit son mot
 * de passe (voir EnvoiDesLiensDesExpertsComptables), envoyé sans file
 * d'attente à l'adresse que le propriétaire a saisie : le premier, ou celui
 * d'un accès redonné. Il ne contient jamais l'identifiant de connexion ni
 * un mot de passe : la personne découvre son identifiant sur la page du
 * lien, une fois son mot de passe choisi.
 */
class LienDuMotDePasseDeLExpertComptableMail extends Mailable
{
    /**
     * @param  list<string>  $autorisations  les libellés des autorisations de la déclaration
     */
    public function __construct(
        public readonly string $lien,
        public readonly string $nom,
        public readonly string $societe,
        public readonly string $siren,
        public readonly string $cabinet,
        public readonly string $expireLe,
        public readonly array $autorisations,
        public readonly ?string $accesRedonnePar = null,
    ) {}

    public function envelope(): Envelope
    {
        $application = Reglage::texte('app.name');

        return new Envelope(subject: $this->accesRedonnePar === null
            ? "{$this->societe} vous ouvre l'accès à ses magasins sur {$application}"
            : "Nouveau lien pour votre accès à {$this->societe} sur {$application}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lien-du-mot-de-passe-de-l-expert-comptable', with: [
            'lien' => $this->lien,
            'nom' => $this->nom,
            'societe' => $this->societe,
            'siren' => $this->siren,
            'cabinet' => $this->cabinet,
            'expireLe' => $this->expireLe,
            'autorisations' => $this->autorisations,
            'accesRedonnePar' => $this->accesRedonnePar,
        ]);
    }
}
