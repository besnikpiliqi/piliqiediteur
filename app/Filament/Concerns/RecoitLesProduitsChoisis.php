<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Resources\DevisResource;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportEvents\Event;

/**
 * Une page qui saisit les lignes d'une vente (caisse, facture, devis,
 * voir AjouteDesLignesDeVente) reçoit les produits que choisit le tableau
 * des produits (voir SelecteurDeProduits) et ceux que scanne la douchette.
 * Ce qui arrive du navigateur est revérifié avant d'ajouter la moindre
 * ligne, et un produit déjà sur une ligne en augmente la quantité (voir
 * AjouteDesLignesDeVente::ajouterLesProduitsChoisis()).
 */
trait RecoitLesProduitsChoisis
{
    /**
     * Le nombre d'envois du tableau des produits dont la page garde le
     * jeton : un envoi répété ne suit que de près le premier.
     */
    private const ENVOIS_GARDES = 20;

    /**
     * Les jetons des derniers envois du tableau des produits déjà ajoutés
     * (voir SelecteurDeProduits::ajouterLaSelection()) : un envoi répété,
     * parti avant que la page ne réponde, n'ajoute rien de plus.
     *
     * @var list<string>
     */
    #[Locked]
    public array $envoisDuTableauAjoutes = [];

    /**
     * La sélection du tableau des produits : ses lignes s'ajoutent, le
     * tableau se vide (voir SelecteurDeProduits::selectionAjoutee()) et sa
     * fenêtre se ferme ; refusée, la fenêtre reste ouverte avec la raison,
     * et la sélection aussi, pour en retirer le produit refusé. Un envoi
     * déjà ajouté, répété par un second clic, ne fait que fermer la fenêtre.
     */
    #[On(SelecteurDeProduits::EVENEMENT)]
    public function recevoirLesProduitsChoisis(mixed $selection = null, mixed $envoi = null): void
    {
        $envoi = is_string($envoi) && $envoi !== '' ? $envoi : null;

        if ($envoi === null || ! in_array($envoi, $this->envoisDuTableauAjoutes, true)) {
            if (! DevisResource::ajouterLesProduitsChoisis($this, $selection)) {
                return;
            }

            if ($envoi !== null) {
                $this->envoisDuTableauAjoutes = array_slice([...$this->envoisDuTableauAjoutes, $envoi], -self::ENVOIS_GARDES);
                ValeursLues::instance($this->dispatch(SelecteurDeProduits::SELECTION_AJOUTEE, envoi: $envoi), Event::class)->to(SelecteurDeProduits::class);
            }
        }

        $actions = ValeurRequise::de($this->mountedFormComponentActions, 'les actions ouvertes du formulaire');

        if (end($actions) === 'ajouterUnProduit') {
            $this->unmountFormComponentAction();
        }
    }

    /**
     * Le prix, le taux ou la remise d'une ligne de service vient d'être
     * saisi : deux lignes du même service devenues identiques n'en font
     * plus qu'une (voir AjouteDesLignesDeVente::reunirLesLignesIdentiques()).
     */
    public function reunirLesLignesIdentiques(): void
    {
        DevisResource::reunirLesLignesIdentiques($this);
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
