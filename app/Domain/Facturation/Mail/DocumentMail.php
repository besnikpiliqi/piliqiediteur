<?php

namespace App\Domain\Facturation\Mail;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use LogicException;

/**
 * L'e-mail qui porte un devis, une facture, un avoir ou un reçu de
 * paiement à son client, PDF en pièce jointe (voir EnvoyerDocumentJob). Il part de l'adresse du
 * logiciel, au nom du magasin, et les réponses vont au magasin. Celui
 * d'un devis envoyé porte le lien secret par lequel le client y répond en
 * ligne (voir LienDuDevis) ; la confirmation de sa réponse lui renvoie le
 * devis accepté ou refusé.
 *
 * Il présente le vendeur que son PDF imprime (voir
 * DocumentEnvoyable::emetteur()) : celui qu'une facture ou un avoir a
 * signé à son émission, celui qu'un devis a figé à son dernier envoi. Son
 * nom est celui de l'expéditeur, de l'objet (voir
 * EnvoiDocument::objetPour()), de l'en-tête et de la signature ; sa
 * dénomination (suivie de « EI » pour un entrepreneur individuel, voir
 * Emetteur::denominationImprimee()), son adresse et son SIRET sont ceux du
 * pied. Seuls les moyens de joindre le magasin restent ceux
 * d'aujourd'hui, pour que le client joigne le magasin tel qu'il est
 * quand il lit l'e-mail : l'adresse à laquelle il répond, le téléphone et
 * l'adresse e-mail du pied.
 *
 * Jamais mis en file lui-même : c'est EnvoyerDocumentJob qui l'est, et qui
 * constate son envoi.
 */
class DocumentMail extends Mailable
{
    private readonly Facture|Devis|Avoir|RecuPaiement $document;

    private readonly Emetteur $emetteur;

    /**
     * L'émetteur se lit ici, au départ de l'e-mail (voir
     * EnvoyerDocumentJob), dans le magasin de l'envoi : pour une facture
     * ou un avoir, dans son enregistrement signé au registre fiscal. Un
     * envoi dont le document a été supprimé depuis (un devis brouillon) ne
     * part pas : EnvoyerDocumentJob le constate avant, sans e-mail.
     *
     * @throws DocumentSansEnregistrementSigneException
     * @throws LogicException
     */
    public function __construct(
        public readonly EnvoiDocument $envoi,
        private readonly string $pdf,
        private readonly string $nomFichier,
        private readonly ?string $lienDeReponse = null,
    ) {
        $this->document = $envoi->document ?? throw new LogicException('Le document de cet envoi n\'existe plus : l\'e-mail ne part pas.');
        $this->emetteur = $this->document->emetteur();
    }

    public function envelope(): Envelope
    {
        $nom = $this->emetteur->nom;
        $emailDuMagasin = $this->envoi->magasin->email;

        return new Envelope(
            from: new Address(Reglage::texte('mail.from.address'), $nom),
            replyTo: $emailDuMagasin ? [new Address($emailDuMagasin, $nom)] : [],
            subject: $this->envoi->objet,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.document',
            with: [
                'nom' => $this->emetteur->nom,
                'introduction' => $this->introduction(),
                'paragraphes' => $this->paragraphesDuMessage(),
                'lienDeReponse' => $this->lienDeReponse,
                'coordonnees' => $this->coordonnees(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->nomFichier)->withMime('application/pdf'),
        ];
    }

    /**
     * Ce que contient la pièce jointe. Une relance n'en a pas : son message
     * dit déjà tout de la facture (voir EnvoyerDocumentAction::relancer()).
     * Une facture ne demande d'être réglée avant son échéance que s'il en
     * reste quelque chose à payer quand l'e-mail part : une vente au
     * comptoir, soldée dès son émission, ou une facture réglée depuis, se
     * dit déjà réglée. Seule une facture modifiée directement en base après
     * son émission, que signale la vérification de l'intégrité, peut
     * n'avoir aucune échéance (voir Facture::estEchueEtImpayee()) : une
     * facture numérotée hors de son émission, sans enregistrement signé,
     * ne part pas (voir le constructeur).
     */
    private function introduction(): ?string
    {
        if ($this->envoi->nature === NatureEnvoi::Relance) {
            return null;
        }

        $document = $this->document;

        return match (true) {
            $document instanceof Devis && $this->envoi->nature === NatureEnvoi::Acceptation => "Nous avons bien reçu votre acceptation de notre devis {$document->numero} d'un montant de ".self::montant($document).'. Vous trouverez ci-joint le devis que vous avez accepté. Merci de votre confiance.',
            $document instanceof Devis && $this->envoi->nature === NatureEnvoi::Refus => "Nous avons bien reçu votre refus de notre devis {$document->numero}. Vous trouverez ci-joint le devis concerné, pour mémoire.",
            $document instanceof RecuPaiement => $this->introductionDuRecu($document),
            $document instanceof Facture && ! $document->estPourUnProfessionnel() => "Veuillez trouver ci-joint votre ticket de caisse {$document->numero} d'un montant de ".self::montant($document).'.',
            $document instanceof Facture && $document->montantRestantEnCentimes() <= 0 => "Veuillez trouver ci-joint notre facture {$document->numero} d'un montant de ".self::montant($document).', déjà réglée.',
            $document instanceof Facture => "Veuillez trouver ci-joint notre facture {$document->numero} d'un montant de ".self::montant($document)
                .($document->date_echeance ? ', à régler au plus tard le '.Calendrier::afficherJour($document->date_echeance, 'd/m/Y').'.' : '.'),
            $document instanceof Devis => "Veuillez trouver ci-joint notre devis {$document->numero} d'un montant de ".self::montant($document)
                .($document->date_validite ? ', valable jusqu\'au '.Calendrier::afficherJour($document->date_validite, 'd/m/Y').'.' : '.'),
            $document instanceof Avoir => "Veuillez trouver ci-joint notre avoir {$document->numero} d'un montant de ".self::montant($document).'.',
        };
    }

    /**
     * Le total TTC d'une vente, d'un devis ou d'un avoir, tel qu'il
     * s'imprime : « 1 234,50 € TTC ».
     */
    private static function montant(Facture|Devis|Avoir $document): string
    {
        $calculateur = app(CalculateurLigne::class);

        return $calculateur->enEuros($calculateur->enCentiemes($document->total_ttc)).' TTC';
    }

    /**
     * Ce que dit un reçu de paiement : le montant reçu, ou annulé par une
     * contre-passation, et les documents qu'il règle ; un reçu n'est pas
     * une facture.
     */
    private function introductionDuRecu(RecuPaiement $recu): string
    {
        $calculateur = app(CalculateurLigne::class);
        $montant = $calculateur->enEuros(abs($recu->totalEnCentimes()));
        $documents = $recu->lignes->map(function (LigneRecuPaiement $ligne): string {
            $facture = ValeurRequise::de($ligne->facture, 'la facture d\'une ligne du reçu');

            return "{$facture->libelleDocument()} {$facture->numero}";
        })->implode(', ');

        return $recu->totalEnCentimes() < 0
            ? "Veuillez trouver ci-joint notre reçu {$recu->numero} de la contre-passation d'un paiement de {$montant} ({$documents}). Ce reçu n'est pas une facture."
            : "Veuillez trouver ci-joint notre reçu {$recu->numero} pour votre paiement de {$montant} ({$documents}). Ce reçu n'est pas une facture.";
    }

    /**
     * Le pied de l'e-mail, une ligne par sujet, sans ce qui n'est pas
     * renseigné : le vendeur que le PDF imprime (dénomination, adresse),
     * le moyen de joindre le magasin aujourd'hui (téléphone, adresse
     * e-mail), puis le SIRET du vendeur.
     *
     * @return list<string>
     */
    private function coordonnees(): array
    {
        $emetteur = $this->emetteur;
        $magasin = $this->envoi->magasin;
        $joindre = fn (array $morceaux): string => implode(' · ', ValeursLues::textes(array_filter($morceaux, filled(...))));

        return array_values(array_filter([
            $joindre([$emetteur->denominationImprimee() ?: $emetteur->nom, $emetteur->adresse, trim("{$emetteur->codePostal} {$emetteur->ville}")]),
            $joindre([$magasin->telephone ? "Tél. {$magasin->telephone}" : null, $magasin->email]),
            $emetteur->siret ? "SIRET {$emetteur->siret}" : '',
        ], fn (string $ligne): bool => $ligne !== ''));
    }

    /**
     * Le message de l'envoi, un paragraphe par ligne, chacun imprimé hors
     * Markdown (voir la vue mail.document) : il arrive tel que l'expéditeur
     * l'a écrit, sans lien, titre ni mise en forme qu'il n'aurait pas
     * voulus.
     *
     * @return list<string>
     */
    private function paragraphesDuMessage(): array
    {
        $lignes = preg_split('/\R/', (string) $this->envoi->message);

        return array_values(collect($lignes !== false ? $lignes : throw new LogicException('Le message de l\'envoi ne se découpe pas en paragraphes.'))
            ->map(fn (string $ligne): string => trim($ligne))
            ->filter(fn (string $ligne): bool => $ligne !== '')
            ->all());
    }
}
