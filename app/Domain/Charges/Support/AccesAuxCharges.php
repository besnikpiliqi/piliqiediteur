<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qu'un compte peut faire des charges d'une société (voir
 * DroitsSurLesCharges, qui seul le construit) : voir et ajouter celles de
 * « Toute la société », et celles de ces magasins (tous pour le
 * propriétaire), les valider (et donc les corriger après leur validation,
 * les renvoyer au client et les supprimer avant), et gérer les types de
 * charge de la société, réglage de la société que seuls son propriétaire
 * et l'administrateur coché touchent. Le comptable est le cabinet lié ou
 * l'expert-comptable déclaré : la charge qu'il ajoute naît validée.
 */
final readonly class AccesAuxCharges
{
    /**
     * @param  list<int>|null  $magasins  les magasins dont il voit et ajoute les charges ; null pour tous
     */
    public function __construct(
        public User $compte,
        public Societe $societe,
        public bool $chargesDeLaSociete,
        public ?array $magasins,
        public ?ValideePar $valideComme,
        public bool $gereLesTypes,
    ) {}

    public function valide(): bool
    {
        return $this->valideComme !== null;
    }

    /**
     * Est-il le comptable de la société : son cabinet lié, ou
     * l'expert-comptable qu'elle a déclaré ?
     */
    public function estComptable(): bool
    {
        return in_array($this->valideComme, [ValideePar::Cabinet, ValideePar::ExpertComptable], true);
    }

    public function origine(): OrigineDeLaCharge
    {
        return $this->estComptable() ? OrigineDeLaCharge::Comptable : OrigineDeLaCharge::Client;
    }

    /**
     * Voit-il les charges de ce magasin, ou de « Toute la société » pour
     * null ?
     */
    public function voitLeMagasin(?int $magasin): bool
    {
        return $magasin === null ? $this->chargesDeLaSociete : $this->magasins === null || in_array($magasin, $this->magasins, true);
    }

    public function voit(Charge $charge): bool
    {
        return $charge->societe_id === $this->societe->getKey() && $this->voitLeMagasin($charge->magasin_id);
    }

    /**
     * Les charges qu'il voit, parmi celles de cette requête, déjà limitée à
     * la société par son scope ou par la requête elle-même.
     *
     * @param  Builder<Charge>  $requete
     * @return Builder<Charge>
     */
    public function filtrer(Builder $requete): Builder
    {
        $requete->where('charges.societe_id', $this->societe->getKey());

        if ($this->magasins === null) {
            return $this->chargesDeLaSociete ? $requete : $requete->whereNotNull('charges.magasin_id');
        }

        $magasins = $this->magasins;

        return $requete->where(function (Builder $visibles) use ($magasins): void {
            $visibles->whereIn('charges.magasin_id', $magasins === [] ? [0] : $magasins);

            if ($this->chargesDeLaSociete) {
                $visibles->orWhereNull('charges.magasin_id');
            }
        });
    }
}
