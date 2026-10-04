<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un devis reste librement modifiable, lignes comprises, tant qu'il n'a
 * jamais été transformé en facture -- peu importe son propre statut
 * (brouillon, envoyé, accepté, refusé) : rien n'interdit légalement de
 * corriger ou de relancer un devis non encore transformé en vente réelle.
 * Dès qu'une facture en a été créée (voir CreerFactureDepuisDevisAction),
 * il devient définitivement figé : voir Devis::estModifiable().
 */
class ModifierDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Devis $devis, array $lignes, array $attributs = []): Devis
    {
        if (! $devis->estModifiable()) {
            throw new LogicException('Ce devis ne peut plus être modifié : il a déjà été transformé en facture.');
        }

        return DB::transaction(function () use ($devis, $lignes, $attributs) {
            $devis->update($attributs);
            $devis->lignes()->delete();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $devis->fresh('lignes');
        });
    }
}
