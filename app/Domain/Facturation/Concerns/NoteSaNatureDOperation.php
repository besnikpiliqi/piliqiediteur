<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use Illuminate\Support\Str;

/**
 * La nature d'opération d'un brouillon de facture ou d'un devis (voir
 * NatureOperation) et, pour des biens qui partent hors de France, leur
 * livraison : écrites à chaque enregistrement du document, avec les
 * lignes que la nature fait calculer (voir ContexteDeVente), jamais par
 * mass assignment. Une facture émise les garde telles qu'elle les a
 * signées (voir ProtegeLesDonneesValidees).
 */
trait NoteSaNatureDOperation
{
    /**
     * Note la nature choisie et, quand elle demande une livraison (voir
     * NatureOperation::exigeUneLivraison()), la livraison saisie, sans
     * espaces autour, le pays en majuscules ; sinon, aucune livraison ne
     * reste d'un choix précédent. Ce qui n'est pas fourni reste tel quel :
     * la nature déjà notée (la vente en France pour un nouveau document)
     * et sa livraison. Le transport est celui que la nature impose (le
     * magasin, pour un export ou une livraison vers un DOM), sinon celui
     * saisi.
     *
     * @param  array<string, mixed>  $attributs  nature_operation (NatureOperation ou sa valeur), livraison_adresse, livraison_complement, livraison_code_postal, livraison_ville, livraison_pays, livraison_date et transport_par (TransportPar ou sa valeur)
     */
    public function noterSaNatureDOperation(array $attributs): NatureOperation
    {
        $nature = $attributs['nature_operation'] ?? $this->nature_operation;
        $nature = $nature instanceof NatureOperation ? $nature : NatureOperation::from((string) $nature);
        $livree = $nature->exigeUneLivraison();
        $saisie = fn (string $champ): mixed => match (true) {
            ! $livree => null,
            ! array_key_exists($champ, $attributs) => $this->getAttribute($champ),
            blank($attributs[$champ]) => null,
            default => is_string($attributs[$champ]) ? trim($attributs[$champ]) : $attributs[$champ],
        };
        $transport = $saisie('transport_par');

        $this->forceFill([
            'nature_operation' => $nature,
            'livraison_adresse' => $saisie('livraison_adresse'),
            'livraison_complement' => $saisie('livraison_complement'),
            'livraison_code_postal' => $saisie('livraison_code_postal'),
            'livraison_ville' => $saisie('livraison_ville'),
            'livraison_pays' => ($pays = $saisie('livraison_pays')) === null ? null : Str::upper($pays),
            'livraison_date' => $saisie('livraison_date'),
            'transport_par' => $livree
                ? ($nature->transportImpose() ?? ($transport instanceof TransportPar ? $transport : TransportPar::tryFrom((string) $transport)))
                : null,
        ]);

        return $nature;
    }

    /**
     * La livraison telle que le document l'imprime : l'adresse, fermée par
     * le pays, la date et qui transporte les biens ; rien pour une nature
     * sans livraison.
     *
     * @return list<string>
     */
    public function lignesDeLivraison(): array
    {
        if (! $this->nature_operation->exigeUneLivraison()) {
            return [];
        }

        return array_values(array_filter([
            $this->livraison_adresse,
            $this->livraison_complement,
            trim("{$this->livraison_code_postal} {$this->livraison_ville}"),
            $this->livraison_pays,
            $this->livraison_date === null ? null : 'Livraison le '.Calendrier::afficherJour($this->livraison_date, 'd/m/Y'),
            $this->transport_par?->getLabel(),
        ], filled(...)));
    }
}
