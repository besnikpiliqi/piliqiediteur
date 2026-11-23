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
 * nouveau. Le texte du bouton grisé (avec son opacité) et celui de
 * l'info-bulle ouverte au survol se lisent en clair comme en sombre, au
 * contraste AA de 4,5:1 : le balayage des pages au repos ne voit jamais
 * l'info-bulle, qui n'existe qu'au survol, et tient un élément
 * aria-disabled pour hors de la règle (relecture du lot écrans). Le vendeur
 * de la Boutique du Port a déjà fermé sa caisse (voir
 * TestCaseDuNavigateur::remplirLeMagasin()).
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
 * Le contraste d'un texte de la page contre son fond réel, composé comme
 * le fait le balayage du contraste (voir tests/Browser/Support/contraste.js)
 * mais avec l'opacité de l'élément appliquée au texte comme à son fond,
 * puisque le bouton grisé s'éclaircit en entier : le texte du bouton dont
 * l'attribut est « data-balayage-contraste », ou celui de l'info-bulle qui
 * porte cette phrase.
 *
 * @return array{rapport: float, texte: string, fond: string, sombre: bool}
 */
function contrasteDuTexte(Navigation $onglet, string $genre, string $valeur): array
{
    return $onglet->page()->evaluate(<<<'JS'
        ({ genre, valeur }) => {
            const lire = (couleur) => {
                const parties = /^rgba?\(([^)]+)\)$/.exec(couleur);

                if (parties === null) {
                    return null;
                }

                const nombres = parties[1].split(/[\s,/]+/).filter((partie) => partie !== '').map(Number);

                return { r: nombres[0], g: nombres[1], b: nombres[2], a: nombres.length > 3 ? nombres[3] : 1 };
            };
            const dessus = (haut, bas) => ({
                r: haut.r * haut.a + bas.r * (1 - haut.a),
                g: haut.g * haut.a + bas.g * (1 - haut.a),
                b: haut.b * haut.a + bas.b * (1 - haut.a),
                a: 1,
            });
            const luminance = ({ r, g, b }) => {
                const canal = (v) => (v / 255 <= 0.03928 ? v / 255 / 12.92 : ((v / 255 + 0.055) / 1.055) ** 2.4);

                return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b);
            };
            const rapport = (premiere, seconde) => {
                const [claire, sombre] = [luminance(premiere), luminance(seconde)].sort((x, y) => y - x);

                return (claire + 0.05) / (sombre + 0.05);
            };
            const enRgb = ({ r, g, b }) => 'rgb(' + [r, g, b].map(Math.round).join(', ') + ')';
            const sombre = document.documentElement.classList.contains('dark');
            const toile = sombre ? { r: 18, g: 18, b: 18, a: 1 } : { r: 255, g: 255, b: 255, a: 1 };
            const fondDe = (element) => {
                const couches = [];

                for (let noeud = element; noeud instanceof Element; noeud = noeud.parentElement) {
                    const couleur = lire(getComputedStyle(noeud).backgroundColor);

                    if (couleur !== null && couleur.a > 0) {
                        couches.push(couleur);

                        if (couleur.a >= 1) {
                            break;
                        }
                    }
                }

                return couches.reduceRight((fond, couche) => dessus(couche, fond), toile);
            };
            const element = genre === 'bouton'
                ? document.querySelector(valeur)
                : [...document.querySelectorAll('[data-tippy-root] .tippy-content')].find((contenu) => contenu.textContent.trim() === valeur);

            if (! element) {
                return null;
            }

            const style = getComputedStyle(element);
            const derriere = element.parentElement ? fondDe(element.parentElement) : toile;
            const propre = lire(style.backgroundColor);
            const fondDuTexte = propre !== null && propre.a > 0 ? dessus(propre, derriere) : derriere;
            const opacite = Number(style.opacity);
            const texte = dessus({ ...dessus(lire(style.color), fondDuTexte), a: opacite }, derriere);
            const fond = dessus({ ...fondDuTexte, a: opacite }, derriere);

            return { rapport: rapport(texte, fond), texte: enRgb(texte), fond: enRgb(fond), sombre };
        }
        JS, ['genre' => $genre, 'valeur' => $valeur]);
}

/**
 * Le bouton grisé et son info-bulle ouverte au survol se lisent en clair et
 * en sombre : le contraste de chacun atteint 4,5:1 dans les deux modes
 * (le mode demandé est bien celui de la page), puis la page revient en
 * clair.
 */
function verifierLeContrasteDuBoutonGrise(Navigation $onglet, string $adresse, string $bouton, string $phrase): void
{
    foreach (['dark', 'light'] as $mode) {
        $onglet->page()->evaluate('(mode) => localStorage.setItem("theme", mode)', $mode);
        $onglet->aller($adresse);
        $onglet->attendreQue('document.documentElement.classList.contains("dark") === '.($mode === 'dark' ? 'true' : 'false'), "la page ne passe pas en mode {$mode}");

        $grise = contrasteDuTexte($onglet, 'bouton', $bouton);
        expect($grise)->not->toBeNull("Le bouton grisé est dans la page ({$mode}).")
            ->and($grise['rapport'])->toBeGreaterThanOrEqual(4.5, "Le texte du bouton grisé se lit ({$mode}) : {$grise['texte']} sur {$grise['fond']}.");

        $onglet->geste(fn () => $onglet->element($bouton)->hover(), "le survol du bouton grisé ({$mode})")
            ->attendreQue('[...document.querySelectorAll("[data-tippy-root] .tippy-box")].some((boite) => boite.textContent.trim() === '.Navigation::texte($phrase).' && boite.getAttribute("data-state") === "visible" && getComputedStyle(boite).opacity === "1")', "l'info-bulle du bouton grisé n'est pas ouverte ({$mode})");

        $bulle = contrasteDuTexte($onglet, 'info-bulle', $phrase);
        expect($bulle)->not->toBeNull("L'info-bulle est dans la page ({$mode}).")
            ->and($bulle['rapport'])->toBeGreaterThanOrEqual(4.5, "Le texte de l'info-bulle se lit ({$mode}) : {$bulle['texte']} sur {$bulle['fond']}.");
    }
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

    verifierLeContrasteDuBoutonGrise($vendeur, $adresse, $bouton, PHRASE_RIEN_A_FERMER);
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

    verifierLeContrasteDuBoutonGrise($responsable, $adresse, $bouton, PHRASE_AUCUNE_CAISSE_DE_VENDEUR);
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
