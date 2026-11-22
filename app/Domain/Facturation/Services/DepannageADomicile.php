<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Les mentions d'un devis de dépannage, de réparation ou d'entretien à
 * domicile (arrêté du 24 janvier 2017, décision de Claude T51.6) : un
 * réglage facultatif du magasin, décoché au départ (voir
 * ParametresFactures), qui ne vaut que pour un devis à un particulier. Le
 * devis imprime alors le lieu de l'intervention, « Devis gratuit » ou
 * « Devis payant : X € TTC », et le droit du client de garder les pièces
 * remplacées ; les frais de déplacement se portent sur une ligne à part.
 * Un devis signé chez le client imprime aussi l'information sur le droit
 * de rétractation de quatorze jours et son formulaire (art. L. 221-5 et
 * L. 221-18 du code de la consommation, voir DroitDeRetractation).
 *
 * Un brouillon suit le réglage du magasin d'aujourd'hui ; un devis envoyé
 * imprime celui qu'il a figé à son dernier envoi, avec le prix du devis
 * (voir EmettreDevisAction), quoi que devienne ensuite le magasin.
 */
final readonly class DepannageADomicile
{
    public const PIECES_REMPLACEES = 'Vous pouvez conserver les pièces remplacées.';

    public const REFUS_SANS_LIEU = 'Indiquez le lieu de l\'intervention : un devis de dépannage, de réparation ou d\'entretien à domicile le porte.';

    public const REFUS_D_ENVOI_SANS_LIEU = 'Ce devis de dépannage à domicile n\'indique pas le lieu de l\'intervention : modifiez-le pour l\'indiquer, puis envoyez-le.';

    public const LIEU_MAXIMUM = 500;

    /**
     * @param  int|null  $prixDuDevisEnCentimes  null pour un devis gratuit
     */
    public function __construct(
        public ?string $lieu,
        public ?int $prixDuDevisEnCentimes,
        public bool $signeChezLeClient,
    ) {}

    /**
     * Les mentions que ce devis imprime, null quand il n'en a pas : le
     * devis d'un professionnel, ou d'un magasin qui n'a pas coché le
     * réglage (celui qu'il a figé à son dernier envoi, ou celui
     * d'aujourd'hui pour un brouillon).
     */
    public static function duDevis(Devis $devis): ?self
    {
        if ($devis->estPourUnProfessionnel()) {
            return null;
        }

        $fige = $devis->emetteur_fige !== null;
        $concerne = $fige ? (bool) $devis->depannage_a_domicile : (bool) $devis->magasin->depannage_a_domicile;

        if (! $concerne) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);
        $prix = $fige ? $devis->prix_du_devis_de_depannage : $devis->magasin->prix_du_devis_de_depannage;

        return new self(
            $devis->lieu_d_intervention,
            $prix === null ? null : $calculateur->enCentiemes($prix),
            (bool) $devis->signe_chez_le_client,
        );
    }

    /**
     * Le magasin coche-t-il le réglage pour ce type de client ? Le
     * formulaire du devis ne montre le lieu de l'intervention et la case
     * « Devis signé chez le client » qu'alors.
     */
    public static function concerne(Magasin $magasin, bool $clientProfessionnel): bool
    {
        return ! $clientProfessionnel && (bool) $magasin->depannage_a_domicile;
    }

    /**
     * « Devis gratuit », ou « Devis payant : 35,00 € TTC ».
     */
    public function mentionDuPrixDuDevis(): string
    {
        if ($this->prixDuDevisEnCentimes === null) {
            return 'Devis gratuit';
        }

        return 'Devis payant : '.app(CalculateurLigne::class)->enEuros($this->prixDuDevisEnCentimes).' TTC';
    }
}
