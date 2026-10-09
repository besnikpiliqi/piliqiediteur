<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un brouillon n'a aucune existence légale ni fiscale (voir
 * FacturePolicy::delete()) : contrairement à une facture émise, le modifier
 * en place -- y compris ses lignes -- ne pose aucun problème de
 * conservation. Ça évite à l'utilisateur de devoir tout ressaisir pour
 * corriger une erreur de saisie.
 *
 * Dès l'émission, cette action redevient interdite (voir
 * FacturePolicy::update()) : une facture numérotée se corrige par un avoir,
 * jamais en la réécrivant.
 *
 * Comme CreerFactureAction, elle reçoit le client déjà résolu par une
 * requête limitée au magasin, jamais un client_id brut venu du formulaire.
 */
class ModifierFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Facture $facture, Client $client, array $lignes, array $attributs = []): Facture
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            throw new LogicException('Seule une facture brouillon peut être modifiée.');
        }

        return DB::transaction(function () use ($facture, $client, $lignes, $attributs) {
            $facture->update([...$attributs, 'client_id' => $client->getKey()]);

            $facture->lignes()->delete();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $facture->lignes()->create($ligne);
            }

            $facture->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $facture->fresh('lignes');
        });
    }
}
