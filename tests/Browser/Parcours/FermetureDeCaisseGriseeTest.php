<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * « Fermer ma caisse » et « Fermer la caisse d'un vendeur » dans le
 * navigateur (décision de l'éditeur du 18/11/2026) : tant que la caisse n'a
 * ni vente, ni encaissement, ni contre-passation à compter, le bouton se
 * montre grisé (aria-disabled, sans l'attribut disabled, qui empêcherait
 * l'info-bulle de s'ouvrir), l'info-bulle « Aucune vente ni encaissement à
 * fermer. » s'ouvre au survol, un clic n'ouvre aucune fenêtre et rappelle
 * la même phrase (c'est ce que lit un téléphone, où l'info-bulle ne
 * s'ouvre qu'au toucher ; Playwright, qui tient un bouton aria-disabled
 * pour désactivé, ne le clique ici que de force, comme le fait un doigt ou
 * une souris) ; une vente le rend normal, sa fermeture le grise de
 * nouveau. Le vendeur de la Boutique du Port a déjà fermé sa
 * caisse (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */

const PHRASE_RIEN_A_FERMER = 'Aucune vente ni encaissement à fermer.';

const PHRASE_AUCUNE_CAISSE_DE_VENDEUR = 'Aucune caisse de vendeur à fermer : aucune vente ni encaissement.';

/**
 * Le vendeur vend une bougie à la caisse, payée en espèces : sa caisse
 * s'ouvre.
 */
function vendreUneBougieAuComptoirDuVendeur(SocieteDeTest $societe): void
{
    app(TenantContext::class)->runAs($societe->magasin, fn () => app(EnregistrerVenteCaisseAction::class)->handle(
        $societe->vendeur,
        Client::query()->where('est_comptoir', true)->sole(),
        [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]],
        new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 1800)]),
    ));
}

/**
 * Les relevés de caisse que le vendeur a fermés dans le magasin.
 */
function nombreDeRelevesDuVendeur(SocieteDeTest $societe): int
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): int => ClotureCaissePersonnelle::query()->where('vendeur_id', $societe->vendeur->id)->count());
}

/**
 * Le bouton d'en-tête de cette action de la page des relevés.
 */
function boutonDeLaCaisse(string $action): string
{
    return 'button[wire\\:click*='.Navigation::texte($action).']:not(.fi-modal *)';
}

test('« Fermer ma caisse » est grisé avec son info-bulle tant que rien n’est à fermer, normal après une vente, et se grise de nouveau une fois la caisse fermée', function () {
    $societe = $this->societeComplete();
    $adresse = '/app/'.$societe->magasin->uuid.'/cloture-caisse-personnelles';
    $bouton = boutonDeLaCaisse('fermer_ma_caisse');
    $vendeur = $this->seConnecter($societe->vendeur)->aller($adresse)->attendre()->verifier();

    expect($vendeur->element($bouton)->getAttribute('aria-disabled'))->toBe('true')
        ->and($vendeur->element($bouton)->getAttribute('disabled'))->toBeNull()
        ->and($vendeur->element($bouton)->getAttribute('aria-description'))->toBe(PHRASE_RIEN_A_FERMER)
        ->and($vendeur->element($bouton)->innerText())->toContain('Fermer ma caisse');

    $vendeur->geste(fn () => $vendeur->element($bouton)->hover(), 'le survol de « Fermer ma caisse »')
        ->voir(PHRASE_RIEN_A_FERMER);
    $vendeur->geste(fn () => $vendeur->element($bouton)->click(['force' => true]), 'le clic sur « Fermer ma caisse » grisé');
    expect($vendeur->fenetreOuverte())->toBeFalse();
    $vendeur->voir(PHRASE_RIEN_A_FERMER);
    expect(nombreDeRelevesDuVendeur($societe))->toBe(1);

    vendreUneBougieAuComptoirDuVendeur($societe);
    $vendeur->aller($adresse)->attendre()->verifier();

    expect($vendeur->element($bouton)->getAttribute('aria-disabled'))->toBeNull();
    $vendeur->cliquer($bouton);
    expect($vendeur->fenetreOuverte())->toBeTrue();
    $vendeur->soumettre('Confirmer')
        ->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null', 'la caisse ne se ferme pas');

    expect(nombreDeRelevesDuVendeur($societe))->toBe(2);
    $vendeur->attendreQue('document.querySelector('.Navigation::texte($bouton).')?.getAttribute("aria-disabled") === "true"', 'le bouton ne se grise pas après la fermeture');
});

test('« Fermer la caisse d’un vendeur » est grisé avec son info-bulle tant qu’aucun vendeur n’a de caisse ouverte, puis normal quand l’un d’eux vend', function () {
    $societe = $this->societeComplete();
    $adresse = '/app/'.$societe->magasin->uuid.'/cloture-caisse-personnelles';
    $bouton = boutonDeLaCaisse('fermer_la_caisse_d_un_vendeur');
    $responsable = $this->seConnecter($societe->responsable)->aller($adresse)->attendre()->verifier();

    expect($responsable->element($bouton)->getAttribute('aria-disabled'))->toBe('true')
        ->and($responsable->element($bouton)->getAttribute('aria-description'))->toBe(PHRASE_AUCUNE_CAISSE_DE_VENDEUR);

    $responsable->geste(fn () => $responsable->element($bouton)->hover(), 'le survol de « Fermer la caisse d’un vendeur »')
        ->voir(PHRASE_AUCUNE_CAISSE_DE_VENDEUR);
    $responsable->geste(fn () => $responsable->element($bouton)->click(['force' => true]), 'le clic sur « Fermer la caisse d’un vendeur » grisé');
    expect($responsable->fenetreOuverte())->toBeFalse();
    $responsable->voir(PHRASE_AUCUNE_CAISSE_DE_VENDEUR);

    vendreUneBougieAuComptoirDuVendeur($societe);
    $responsable->aller($adresse)->attendre()->verifier();

    expect($responsable->element($bouton)->getAttribute('aria-disabled'))->toBeNull();
    $responsable->cliquer($bouton);
    expect($responsable->fenetreOuverte())->toBeTrue();
});
