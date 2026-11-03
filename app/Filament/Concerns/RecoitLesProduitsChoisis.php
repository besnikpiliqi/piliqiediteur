<?php

namespace App\Filament\Concerns;

use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Resources\DevisResource;
use Livewire\Attributes\On;

/**
 * Une page qui saisit les lignes d'une vente (caisse, facture, devis,
 * voir AjouteDesLignesDeVente) reçoit les produits que choisit le tableau
 * des produits (voir SelecteurDeProduits) et ceux que scanne la douchette.
 * Ce qui arrive du navigateur est revérifié avant d'ajouter la moindre
 * ligne (voir AjouteDesLignesDeVente::ajouterLesProduitsChoisis()).
 */
trait RecoitLesProduitsChoisis
{
    /**
     * La sélection du tableau des produits : ses lignes s'ajoutent, et la
     * fenêtre du tableau se ferme ; refusée, la fenêtre reste ouverte avec
     * la raison.
     */
    #[On(SelecteurDeProduits::EVENEMENT)]
    public function recevoirLesProduitsChoisis(mixed $selection = null): void
    {
        if (DevisResource::ajouterLesProduitsChoisis($this, $selection) && end($this->mountedFormComponentActions) === 'ajouterUnProduit') {
            $this->unmountFormComponentAction();
        }
    }

    /**
     * Entrée dans le champ de la douchette : le produit dont le code-barres
     * ou la référence est exactement le texte saisi s'ajoute, et le champ
     * se vide pour le scan suivant ; sinon le texte reste, avec la raison
     * (voir AjouteDesLignesDeVente::ajouterLeProduitScanne()).
     */
    public function scannerUneReference(): void
    {
        if (DevisResource::ajouterLeProduitScanne($this, $this->data['douchette'] ?? null)) {
            $this->data['douchette'] = null;
        }
    }
}
