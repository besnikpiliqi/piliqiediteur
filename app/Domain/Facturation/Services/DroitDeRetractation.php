<?php

namespace App\Domain\Facturation\Services;

/**
 * L'information d'un particulier sur son droit de rétractation de
 * quatorze jours, avec le formulaire type, d'après les modèles des
 * annexes du code de la consommation (art. R. 221-1 et R. 221-3) :
 * - la page du lien d'un devis envoyé à un particulier et l'e-mail qui
 *   confirme son acceptation en ligne (décision de Claude T86 : un contrat
 *   conclu à distance, art. L. 221-18), où le client peut demander que la
 *   prestation commence avant la fin du délai (art. L. 221-25) ;
 * - le PDF d'un devis de dépannage à domicile signé chez le client (T51.6 :
 *   un contrat conclu hors établissement, art. L. 221-5 et L. 221-9).
 * Rien de tout cela pour un client professionnel.
 *
 * Le professionnel à qui la rétractation se notifie est le magasin qui
 * envoie le devis, tel que le devis l'imprime (voir Devis::emetteur()) :
 * son nom, son adresse, son téléphone et son adresse e-mail.
 */
final readonly class DroitDeRetractation
{
    public const TITRE = 'Votre droit de rétractation';

    public const TITRE_DU_FORMULAIRE = 'Formulaire de rétractation';

    public const CASE_DU_DEBUT_ANTICIPE = 'Je demande que la prestation commence avant la fin du délai de rétractation de quatorze jours.';

    public const AIDE_DU_DEBUT_ANTICIPE = 'Facultatif. Si vous vous rétractez ensuite, vous devrez payer la part de la prestation déjà faite.';

    public function __construct(private Emetteur $emetteur) {}

    /**
     * L'information sur le droit, un paragraphe par ligne.
     *
     * @return list<string>
     */
    public function paragraphes(): array
    {
        return [
            'Vous avez le droit de vous rétracter de ce contrat sans donner de motif dans un délai de quatorze jours. Ce délai court à compter du jour de votre acceptation pour une prestation de services, et du jour où vous, ou un tiers que vous désignez, prenez physiquement possession du bien pour une vente de biens.',
            "Pour exercer ce droit, notifiez-nous votre décision de vous rétracter par une déclaration dénuée d'ambiguïté (par exemple une lettre envoyée par la poste ou un e-mail), à : {$this->coordonnees()}. Vous pouvez utiliser le formulaire de rétractation ci-dessous, mais ce n'est pas obligatoire. Pour que le délai soit respecté, il suffit d'envoyer votre décision avant qu'il expire.",
            'En cas de rétractation, nous vous rembourserons tous les paiements reçus de vous, frais de livraison compris, au plus tard quatorze jours après le jour où nous sommes informés de votre décision, par le moyen de paiement que vous avez utilisé, sauf si vous convenez expressément d\'un autre moyen, sans frais pour vous.',
            'Si vous avez demandé que la prestation commence avant la fin du délai de rétractation, vous devrez nous payer un montant proportionnel à ce qui vous a été fourni jusqu\'au moment où vous nous avez informés de votre rétractation, par rapport à l\'ensemble de la prestation prévue.',
            'Ce droit ne s\'applique pas aux travaux d\'entretien ou de réparation à réaliser en urgence à votre domicile et expressément demandés par vous, dans la limite des pièces de rechange et des travaux strictement nécessaires pour répondre à l\'urgence (art. L. 221-28 du code de la consommation).',
        ];
    }

    /**
     * Le formulaire type de rétractation, une ligne par ligne imprimée.
     *
     * @return list<string>
     */
    public function formulaire(?string $numeroDuDevis): array
    {
        return [
            '(Veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat.)',
            "À l'attention de {$this->coordonnees()} :",
            'Je/nous (*) vous notifie/notifions (*) par la présente ma/notre (*) rétractation du contrat pour la vente du bien (*)/pour la prestation de services (*) ci-dessous'.($numeroDuDevis === null ? ' :' : " (devis n° {$numeroDuDevis}) :"),
            'Commandé le (*)/reçu le (*) :',
            'Nom du (des) consommateur(s) :',
            'Adresse du (des) consommateur(s) :',
            'Signature du (des) consommateur(s) (uniquement en cas de notification du présent formulaire sur papier) :',
            'Date :',
            '(*) Rayez la mention inutile.',
        ];
    }

    /**
     * Ce que dit la confirmation d'une acceptation en ligne de la demande
     * du client : la prestation commence avant la fin du délai, ou après.
     */
    public static function reponseALaDemande(bool $debutAnticipe): string
    {
        return $debutAnticipe
            ? 'Vous avez demandé que la prestation commence avant la fin du délai de rétractation : si vous vous rétractez ensuite, vous devrez payer la part de la prestation déjà faite.'
            : 'Vous n\'avez pas demandé que la prestation commence avant la fin du délai de rétractation.';
    }

    /**
     * Le magasin qui envoie le devis : son nom, son adresse, son téléphone
     * et son adresse e-mail, sans ce qui n'est pas renseigné.
     */
    private function coordonnees(): string
    {
        $emetteur = $this->emetteur;

        return implode(', ', array_values(array_filter([
            $emetteur->nom,
            $emetteur->adresse,
            $emetteur->complementAdresse,
            trim("{$emetteur->codePostal} {$emetteur->ville}"),
            filled($emetteur->telephone) ? "tél. {$emetteur->telephone}" : null,
            $emetteur->email,
        ], filled(...))));
    }
}
