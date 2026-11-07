<?php

namespace App\Domain\Achats\Concerns;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;

/**
 * Un taux de TVA d'une facture d'achat ou d'un avoir fournisseur
 * (décision de l'éditeur du 02/11/2026, T1, voir TvaDuFournisseur) : sa
 * base, la TVA retenue (celle du document du fournisseur), la TVA que
 * PILIQI calcule, gardée pour la preuve, la TVA que la personne a saisie,
 * et la confirmation d'un écart au-delà de la tolérance -- son auteur, son
 * nom au moment de l'opération, son instant en UTC et l'empreinte de
 * l'écart confirmé. Seul TvaDuFournisseur l'écrit.
 */
trait EstUnTauxDuFournisseur
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'ecart_confirme_par' => 'ecart_confirme_par_nom',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taux_tva' => 'decimal:2',
            'base_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_tva_calcule' => 'decimal:2',
            'montant_tva_saisi' => 'decimal:2',
            'ecart_confirme_le' => Instant::class,
            'ordre' => 'integer',
        ];
    }

    /**
     * Un écart au-delà de la tolérance y a-t-il été confirmé ?
     */
    public function ecartConfirme(): bool
    {
        return $this->ecart_confirme_empreinte !== null;
    }
}
