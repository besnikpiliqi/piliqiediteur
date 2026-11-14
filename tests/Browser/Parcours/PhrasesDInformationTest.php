<?php

use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\FactureResource;

/*
 * Les phrases d'information décidées par Claude (D76) dans le navigateur :
 * ce que PILIQI ne gère pas encore se lit là où la personne le chercherait
 * — la ristourne sans retour de marchandise dans « Avoirs fournisseur » et
 * sa fenêtre de création, l'acompte reçu avant la facture dans les
 * formulaires de facture et de devis, la société membre d'un assujetti
 * unique sur la page d'inscription.
 */

test('le responsable lit, dans la liste des avoirs fournisseur et dans la fenêtre « Créer un avoir fournisseur », qu’une ristourne sans retour de marchandise ne se saisit pas encore', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs')
        ->voir(AvoirFournisseurResource::INFORMATION_RISTOURNE)
        ->cliquerSur('Créer un avoir fournisseur')
        ->voir('Facture d\'achat');

    expect($onglet->element('.fi-modal-window:has-text("'.AvoirFournisseurResource::INFORMATION_RISTOURNE.'")')->count())->toBe(1);
});

test('le vendeur lit, dans les formulaires d’une nouvelle facture et d’un nouveau devis, qu’un acompte reçu avant la facture ne s’enregistre pas encore', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->vendeur)
        ->aller('/app/'.$societe->magasin->uuid.'/factures/create')
        ->voir(FactureResource::INFORMATION_ACOMPTE)
        ->aller('/app/'.$societe->magasin->uuid.'/devis/create')
        ->voir(FactureResource::INFORMATION_ACOMPTE);
});

test('la page d’inscription dit qu’une société membre d’un assujetti unique ne vend pas encore dans PILIQI', function () {
    $this->naviguer('/app/register')->voir(InscriptionSociete::INFORMATION_ASSUJETTI_UNIQUE);
});
