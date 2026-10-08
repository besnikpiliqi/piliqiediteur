<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Contacts\Models\Fournisseur;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une facture d'achat en brouillon, sans aucun impact sur le
 * stock (voir ValiderFactureAchatAction). Ses lignes, et les produits
 * qu'elles créent ou corrigent, sont enregistrés par
 * ConstructeurLigneAchat.
 */
class CreerFactureAchatAction
{
    public function __construct(private readonly ConstructeurLigneAchat $constructeurLignes) {}

    /**
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Fournisseur $fournisseur, array $lignes, array $attributs = []): FactureAchat
    {
        return DB::transaction(function () use ($fournisseur, $lignes, $attributs) {
            $factureAchat = FactureAchat::create([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]);

            $this->constructeurLignes->enregistrer($factureAchat, $lignes);

            return $factureAchat->fresh('lignes');
        });
    }
}
