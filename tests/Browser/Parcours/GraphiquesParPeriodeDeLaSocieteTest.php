<?php

use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\CartesParPeriode as Cartes;
use Tests\Browser\Support\SemeLesVentesDeTroisAnnees;

uses(SemeLesVentesDeTroisAnnees::class);

/*
 * Les graphiques par mois, par trimestre ou par année dans l'espace de la
 * société (choix de l'éditeur du 16/11/2026, lot GRAPH-AN ; maquette
 * 01-graph-an.html et décisions de Claude du 22/11/2026, D76) : la barre
 * des filtres et la couleur sous les chiffres clés, le découpage par
 * trimestre et par année, le cumul, l'écart en % sur un an, la tendance, le
 * détail d'une barre jusqu'à la liste de ses documents, et le clavier. Le jeu
 * est celui de SemeLesVentesDeTroisAnnees : une société qui vend depuis 2023,
 * sans rien en 2024, regardée le 10 mars 2026.
 */

test('la barre des filtres met chaque libellé à gauche de son menu, et le chiffre d\'affaires, les achats et le retard ont leur couleur, en clair et en sombre', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cinq cartes ouvertes au départ ne sont pas là');

    expect(Cartes::nombre($onglet, '[data-graphique]'))->toBe(5);
    expect($onglet->page()->evaluate('() => Array.from(document.querySelectorAll("[data-graphique]")).map((carte) => carte.dataset.graphique).join(",")'))
        ->toBe('ventes_et_achats_par_mois,tva_par_mois,ca_par_mois,ca_par_magasin,factures_par_anciennete');

    $onglet->page()->setViewportSize(1920, 1000);
    $onglet->attendreQue('window.innerWidth === 1920', 'la fenêtre n\'est pas à 1 920 px');
    $barre = Cartes::barreDesFiltres($onglet);

    expect(array_column($barre, 'nom'))->toBe(['Période', 'Découper par', 'Magasin', 'Comparer avec l\'année précédente', 'Cumul depuis le début de l\'année', 'Ajouter un graphique']);

    foreach ($barre as $commande) {
        if ($commande['menu']) {
            expect($commande['libelleDroite'])->toBeLessThanOrEqual($commande['commandeGauche'] + 1, "le libellé « {$commande['nom']} » est à gauche de son menu");
            expect(abs($commande['libelleHaut'] + 9 - ($commande['commandeHaut'] + 14)))->toBeLessThan(10, "le libellé « {$commande['nom']} » est sur la ligne de son menu");
        } else {
            expect($commande['commandeDroite'])->toBeLessThanOrEqual($commande['libelleGauche'] + 1, "la case « {$commande['nom']} » est à gauche de son libellé");
        }
    }

    $premieres = array_slice($barre, 0, 5);
    $bord = $onglet->page()->evaluate('() => document.querySelector("[data-filtres]").getBoundingClientRect().right');

    expect(max(array_column($premieres, 'haut')) - min(array_column($premieres, 'haut')))->toBeLessThan(8, 'à 1 920 px, les cinq premières commandes tiennent sur une seule ligne');
    expect($barre[5]['haut'])->toBeGreaterThan($premieres[0]['haut'] + 20, '« Ajouter un graphique » passe seul à la seconde ligne, dans l\'espace de la société');
    expect(abs($barre[5]['droite'] - (float) $bord))->toBeLessThan(2, '« Ajouter un graphique » est à droite');

    $onglet->page()->setViewportSize(1280, 900);
    $onglet->attendreQue('window.innerWidth === 1280', 'la fenêtre n\'est pas à 1 280 px');
    $barre = Cartes::barreDesFiltres($onglet);
    $dernier = end($barre);
    $bord = $onglet->page()->evaluate('() => document.querySelector("[data-filtres]").getBoundingClientRect().right');

    expect($dernier['nom'])->toBe('Ajouter un graphique');
    expect(abs($dernier['droite'] - (float) $bord))->toBeLessThan(2, '« Ajouter un graphique » se range à droite de la barre quand elle passe à la seconde ligne');
    expect(Cartes::debordements($onglet))->toBe([]);

    $onglet->page()->setViewportSize(390, 900);
    $onglet->attendreQue('window.innerWidth === 390', 'la fenêtre n\'est pas à 390 px');

    foreach (Cartes::barreDesFiltres($onglet) as $commande) {
        expect(abs($commande['libelleHaut'] - $commande['commandeHaut']))->toBeLessThan(20, "à 390 px, le libellé « {$commande['nom']} » reste avec sa commande");
    }

    expect(Cartes::debordements($onglet))->toBe([]);

    $couleurs = [
        'clair' => ['positif' => 'rgb(0, 99, 0)', 'negatif' => 'rgb(192, 41, 43)'],
        'sombre' => ['positif' => 'rgb(76, 199, 96)', 'negatif' => 'rgb(255, 148, 144)'],
    ];

    foreach ($couleurs as $mode => $attendues) {
        Cartes::mettreEnMode($onglet, $mode === 'sombre');
        $ca = '[data-chiffre="ca"] [data-ton]';
        $achats = '[data-chiffre="achats"] [data-ton]';
        $retard = '[data-chiffre="restant_du"] [data-ton]';

        expect(Cartes::texte($onglet, $ca))->toBe('+300,0 % sur un an', 'mars 2026 (80,00 €) contre les mêmes jours de mars 2025 (20,00 €)');
        expect([Cartes::couleur($onglet, $ca), Cartes::couleur($onglet, $achats), Cartes::couleur($onglet, $retard)])
            ->toBe([$attendues['positif'], $attendues['negatif'], $attendues['negatif']], "les couleurs sous les chiffres clés, en mode {$mode} : une hausse en vert, une baisse et un retard en rouge");
        expect(Cartes::texte($onglet, $achats))->toBe('−50,0 % sur un an');
        expect(Cartes::texte($onglet, $retard))->toBe('dont 24,00 € en retard');
        expect(Cartes::couleur($onglet, '[data-chiffre="tva"] [data-ton="neutre"]'))->not->toBeIn([$attendues['positif'], $attendues['negatif']], '« collectée − déductible » reste neutre');
    }
});

test('par trimestre, la carte montre quatre blocs de trois mois sur les 12 derniers mois, puis T1 à T4 sur une année ; par année, les années vides du début sont cachées et celle du milieu reste à 0', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'trimestre');
    $onglet->attendreQue('document.querySelectorAll(\'[data-graphique="ca_par_mois"] g.colonne\').length === 4', 'la carte n\'a pas quatre trimestres');

    expect(Cartes::texte($onglet, '[data-graphique="ca_par_mois"] [data-titre]'))->toBe('Chiffre d\'affaires HT par trimestre');
    expect(Cartes::intitules($onglet))->toBe(['Avril 2025 à juin 2025', 'Juillet 2025 à septembre 2025', 'Octobre 2025 à décembre 2025', 'Janvier 2026 à mars 2026'], 'quatre blocs de trois mois, le dernier finissant ce mois-ci');
    expect(Cartes::texte($onglet, '[data-notes-de-l-ecran]'))->toBe('Par trimestre, sur les 12 derniers mois : quatre blocs de trois mois, le dernier finissant ce mois-ci.');

    Cartes::choisir($onglet, 'periode', 'annee_en_cours');
    $onglet->attendreQue('document.querySelectorAll(\'[data-graphique="ca_par_mois"] g.colonne\').length === 4 && document.querySelector(\'[data-graphique="ca_par_mois"] g.colonne\').getAttribute("aria-label").startsWith("1er trimestre 2026")', 'la carte n\'a pas T1 à T4 de 2026');

    expect(Cartes::etiquettes($onglet))->toBe(['T1 2026', 'T2 2026', 'T3 2026', 'T4 2026']);
    expect(array_map(fn (array $colonne): bool => str_ends_with($colonne['libelle'], 'à venir'), Cartes::colonnes($onglet)))->toBe([false, true, true, true], 'Les trimestres à venir ne sont pas des gestes.');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    $onglet->attendreQue('document.querySelectorAll(\'[data-graphique="ca_par_mois"] g.colonne\').length === 4 && document.querySelector(\'[data-graphique="ca_par_mois"] g.colonne\').getAttribute("aria-label").startsWith("Année 2023")', 'la carte n\'a pas les années 2023 à 2026');

    expect(Cartes::intitules($onglet))->toBe(['Année 2023', 'Année 2024', 'Année 2025', 'Année 2026']);
    expect(Cartes::nombre($onglet, '[data-graphique="ca_par_mois"] svg rect.zero'))->toBe(1, '2024, vide entre deux années qui vendent, reste à 0');
    expect(Cartes::texte($onglet, '[data-graphique="ca_par_mois"] [data-note]'))->toContain('6 années plus anciennes sans donnée ne sont pas affichées.');
    expect(Cartes::texte($onglet, '[data-graphique="ca_par_mois"] [data-titre]'))->toBe('Chiffre d\'affaires HT par année');
    expect(Cartes::texte($onglet, '[data-notes-de-l-ecran]'))->toContain('Par année : la période est les 10 dernières années pour tout l\'écran')->toContain('par année, chaque barre est déjà une année');

    $periode = $onglet->page()->evaluate('() => { const menu = document.querySelector(\'select[data-filtre="periode"]\'); return [menu.disabled, menu.selectedOptions[0].textContent.trim()]; }');
    $cases = $onglet->page()->evaluate('() => [document.querySelector(\'input[data-filtre="comparer"]\').disabled, document.querySelector(\'input[data-filtre="cumul"]\').disabled]');

    expect($periode)->toBe([true, '10 dernières années']);
    expect($cases)->toBe([true, true], 'Par année, la comparaison et le cumul se grisent.');

    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelector(\'select[data-filtre="decoupage"]\').value === "annee"', 'le découpage par année n\'est pas retenu');

    expect(Cartes::intitules($onglet))->toBe(['Année 2023', 'Année 2024', 'Année 2025', 'Année 2026']);
});

test('le cumul se dessine en courbe qui ne se clique pas, avec l\'année précédente en pointillés quand « Comparer » est cochée ; l\'écart en % est vert, rouge ou « – » ; la tendance se dessine et se grise avec le cumul', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $carte = Cartes::carte();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cartes ouvertes au départ ne sont pas là');

    Cartes::basculer($onglet, 'input[data-filtre="cumul"]', 'le cumul');
    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la comparaison');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' svg path[stroke-dasharray]\').length > 0', 'l\'année précédente cumulée n\'est pas en pointillés');

    expect(Cartes::nombre($onglet, $carte.' svg g.colonne[role="button"]'))->toBe(0, 'un point de cumul ne se détaille pas');
    expect(Cartes::texte($onglet, '[data-notes-de-l-ecran]'))->toBe('Le cumul se compte depuis le début de l\'année ; l\'année précédente est cumulée en pointillés.');
    expect($onglet->page()->evaluate('() => document.querySelector(\'[data-graphique="ca_par_mois"] input[data-geste="tendance"]\').disabled'))->toBeTrue('Pas de tendance sur un cumul.');
    expect(Cartes::intitules($onglet))->toHaveCount(12, 'Le cumul part du début de l\'année en cours : ses douze mois, ceux à venir compris.');

    Cartes::basculer($onglet, 'input[data-filtre="cumul"]', 'la fin du cumul');
    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la fin de la comparaison');
    Cartes::choisir($onglet, 'decoupage', 'annee');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="mode"]')->selectOption('ecart'), 'le choix de l\'écart en %');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' svg rect[fill="var(--periode-baisse)"]\').length === 1', 'l\'écart en % n\'est pas dessiné');

    expect(Cartes::nombre($onglet, $carte.' svg rect[fill="var(--periode-hausse)"]'))->toBe(1, '2026 : 120,00 € contre 20,00 €');
    expect(Cartes::nombre($onglet, $carte.' svg text.tiret'))->toBe(2, '2023 et 2025 n\'ont pas d\'année d\'avant à comparer : « – »');
    expect(Cartes::couleur($onglet, $carte.' svg rect[fill="var(--periode-hausse)"]', 'fill'))->toBe('rgb(12, 163, 12)');
    expect(Cartes::couleur($onglet, $carte.' svg rect[fill="var(--periode-baisse)"]', 'fill'))->toBe('rgb(208, 59, 59)');
    expect(Cartes::texte($onglet, $carte.' [data-legende]'))->toBe('Au-dessus de 0 : en hausse sur un an En dessous de 0 : en baisse');
    expect($onglet->page()->evaluate('() => document.querySelector(\'[data-graphique="ca_par_mois"] input[data-geste="tendance"]\').disabled'))->toBeTrue('Pas de tendance sur l\'écart.');

    $onglet->geste(fn () => $onglet->element($carte.' [data-geste="chiffres"]')->click(), 'l\'ouverture des chiffres');
    expect(Cartes::texte($onglet, $carte.' [data-chiffres-de-la-carte] tbody tr:nth-child(2)'))->toStartWith('Année 2024 −100,0 %');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="mode"]')->selectOption('montants'), 'le retour aux montants');
    Cartes::basculer($onglet, $carte.' input[data-geste="tendance"]', 'la tendance');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' svg path[stroke="var(--periode-encre)"]\').length === 1', 'la tendance n\'est pas dessinée');

    expect(Cartes::texte($onglet, $carte.' [data-legende]'))->toBe('Chiffre d\'affaires HT Moyenne sur 3 ans');
    expect(Cartes::texte($onglet, $carte.' [data-options]'))->toContain('Ajouter la tendance (moyenne sur 3 ans)');

    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelector(\''.$carte.' input[data-geste="tendance"]\').checked', 'la tendance n\'est pas retenue après le rechargement');
});

test('un clic sur une barre découpe la carte plus finement, jusqu\'au jour, dont la liste des documents s\'ouvre avec un tableau par magasin et un lien vers la liste filtrée ; « Revenir » remonte', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $carte = Cartes::carte();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    Cartes::cliquerLaBarre($onglet, 'Année 2026');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 12', 'l\'année 2026 ne se redécoupe pas en douze mois');

    expect(Cartes::texte($onglet, $carte.' [data-fil]'))->toBe('Par année › 2026 Revenir');
    expect(Cartes::texte($onglet, $carte.' [data-note]'))->toContain('Détail de Année 2026 : les montants seulement, sans cumul, comparaison, écart ni tendance.');
    expect($onglet->page()->evaluate('() => [document.querySelector(\'[data-graphique="ca_par_mois"] select[data-geste="mode"]\').disabled, document.querySelector(\'[data-graphique="ca_par_mois"] input[data-geste="tendance"]\').disabled]'))->toBe([true, true]);
    expect(Cartes::texte($onglet, '[data-annonce]'))->toBe('Chiffre d\'affaires HT par mois : détail ouvert sur Année 2026.');

    Cartes::cliquerLaBarre($onglet, 'Mars 2026');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 31', 'mars 2026 ne se redécoupe pas en ses 31 jours');

    expect(Cartes::texte($onglet, $carte.' [data-fil]'))->toBe('Par année › 2026 › Mars 2026 Revenir');
    expect(Cartes::texte($onglet, $carte.' [data-astuce]'))->toBe('Cliquer sur un jour pour ouvrir la liste de ses documents.');
    expect(Cartes::colonnes($onglet)[30])->toMatchArray(['role' => 'img'], 'Le 31 mars, à venir, n\'est pas un geste.');

    Cartes::cliquerLaBarre($onglet, 'Jeudi 5 mars 2026');
    $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour][open]") !== null', 'la liste du jour ne s\'ouvre pas');

    expect(Cartes::texte($onglet, '[data-liste-du-jour] h2'))->toBe('Documents du jeudi 5 mars 2026');
    expect(Cartes::nombre($onglet, '[data-liste-du-magasin]'))->toBe(2, 'Tous mes magasins : un tableau par magasin');
    expect($onglet->page()->evaluate('() => Array.from(document.querySelectorAll("[data-liste-du-magasin]")).map((magasin) => magasin.querySelector("h3").textContent.trim() + " : " + Array.from(magasin.querySelectorAll("tbody tr")).map((ligne) => ligne.textContent.replace(/\s+/g, " ").trim()).join(" | "))'))
        ->toBe([
            'Boutique de la Gare : Factures et tickets 1 20,00 € | Avoirs 0 0,00 €',
            'Boutique du Port : Factures et tickets 1 40,00 € | Avoirs 0 0,00 €',
        ]);

    $onglet->geste(fn () => $onglet->element('dialog[data-liste-du-jour]')->press('Escape'), 'Échap dans la fenêtre');
    $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour]") === null', 'la fenêtre ne se ferme pas à Échap');

    Cartes::cliquerLaBarre($onglet, 'Jeudi 5 mars 2026');
    $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour][open]") !== null', 'la liste du jour ne se rouvre pas');
    $onglet->geste(fn () => $onglet->element('[data-liste-du-magasin]:has(h3:text-is("Boutique du Port")) a[data-liste-lien]:text-is("Factures et tickets")')->click(), 'le lien vers la liste du Port')
        ->attendreQue('location.pathname.endsWith("/factures")', 'le lien n\'ouvre pas la liste des factures du Port')
        ->attendre()->verifier();

    expect($onglet->page()->evaluate('() => decodeURIComponent(location.search)'))->toContain('tableFilters[jour][jour]=2026-03-05');
    $onglet->voir('Jour d\'émission : 05/03/2026');
    expect(Cartes::nombre($onglet, 'tbody tr.fi-ta-row'))->toBe(1, 'La liste du Port, filtrée sur le 5 mars 2026, n\'a que la vente du jour.');
    $onglet->aller('/societe')->attendre();
});

test('« Revenir » et le fil du détail remontent d\'un niveau, le détail se referme avec le découpage et ne se retient pas ; la valeur du stock n\'ouvre aucune liste', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $carte = Cartes::carte();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    Cartes::cliquerLaBarre($onglet, 'Année 2026');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 12', 'l\'année ne se redécoupe pas');
    Cartes::cliquerLaBarre($onglet, 'Mars 2026');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 31', 'le mois ne se redécoupe pas');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' [data-geste="revenir"]')->click(), '« Revenir »');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 12', '« Revenir » ne remonte pas à l\'année');

    expect(Cartes::texte($onglet, $carte.' [data-fil]'))->toBe('Par année › 2026 Revenir');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' [data-fil-niveau="0"]')->click(), 'le fil « Par année »');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 4 && document.querySelector(\''.$carte.' [data-fil]\') === null', 'le fil ne remonte pas à la carte entière');

    Cartes::cliquerLaBarre($onglet, 'Année 2026');
    Cartes::choisir($onglet, 'decoupage', 'mois');
    $onglet->attendreQue('document.querySelector(\''.$carte.' [data-fil]\') === null', 'le détail ne se referme pas avec le découpage');

    Cartes::cliquerLaBarre($onglet, 'Mars 2026');
    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelector(\''.$carte.' [data-fil]\') === null', 'le détail est retenu après le rechargement');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('valeur_du_stock'), 'l\'ajout de la valeur du stock');
    $stock = Cartes::carte('valeur_du_stock');
    Cartes::cliquerLaBarre($onglet, 'Mars 2026', 'valeur_du_stock');
    $onglet->attendreQue('document.querySelectorAll(\''.$stock.' g.colonne\').length === 31', 'le stock ne se redécoupe pas en jours');

    expect(Cartes::nombre($onglet, $stock.' g.colonne[role="button"]'))->toBe(0, 'Un jour de la valeur du stock ne se clique pas : aucun document à ouvrir.');
    expect(Cartes::nombre($onglet, $stock.' [data-astuce]'))->toBe(0);
});

test('une barre se choisit au clavier (Entrée), son info-bulle suit la souris ou le focus et se ferme à Échap', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $carte = Cartes::carte();
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    // Playwright fait défiler la page jusqu'à la barre avant de la survoler, et le défilement ferme l'info-bulle : le second survol, sans défilement, la montre.
    $onglet->element(Cartes::colonne('Année 2026'))->hover();
    $onglet->attendre();
    $onglet->element(Cartes::colonne('Année 2026'))->hover();
    $onglet->attendreQue('document.querySelector("[data-info-bulle-des-barres]") !== null && ! document.querySelector("[data-info-bulle-des-barres]").hidden', 'l\'info-bulle ne suit pas la souris');

    expect(Cartes::texte($onglet, '[data-info-bulle-des-barres]'))->toStartWith('Année 2026Chiffre d\'affaires HT : 120,00');

    $onglet->element($carte.' [data-geste="chiffres"]')->hover();
    $onglet->attendreQue('document.querySelector("[data-info-bulle-des-barres]").hidden', 'l\'info-bulle ne se ferme pas quand la souris part');

    $onglet->element(Cartes::colonne('Année 2025'))->focus();
    $onglet->attendreQue('! document.querySelector("[data-info-bulle-des-barres]").hidden', 'l\'info-bulle ne s\'ouvre pas au focus');

    expect(Cartes::texte($onglet, '[data-info-bulle-des-barres]'))->toStartWith('Année 2025Chiffre d\'affaires HT : 20,00');

    $onglet->element(Cartes::colonne('Année 2025'))->press('Escape');
    $onglet->attendreQue('document.querySelector("[data-info-bulle-des-barres]").hidden', 'l\'info-bulle ne se ferme pas à Échap');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element(Cartes::colonne('Année 2025'))->press('Enter'), 'Entrée sur la barre de 2025');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 12', 'Entrée n\'ouvre pas le détail de l\'année');

    expect(Cartes::texte($onglet, $carte.' [data-fil]'))->toBe('Par année › 2025 Revenir');
});

test('dans une société dont l\'exercice finit en juin, la case du cumul et la carte parlent d\'exercice, et par année chaque barre est un exercice', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $proprietaire = $this->proprietaireDe($magasin);
    app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $magasin->societe()->firstOrFail(), ['mois_fin_exercice' => 6]);
    $this->choisirSonMotDePasse($proprietaire);
    app(TenantContext::class)->clear();
    $this->travelTo(self::instantDuTest());
    $carte = Cartes::carte();

    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelector("[data-graphique]") !== null', 'les cartes ne sont pas là');

    expect($onglet->element('input[data-filtre="cumul"]')->locator('xpath=..')->innerText())->toBe('Cumul depuis le début de l\'exercice');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    $onglet->attendreQue('document.querySelector(\''.$carte.' [data-titre]\').textContent.trim() === "Chiffre d\'affaires HT par exercice"', 'la carte ne parle pas d\'exercice');

    expect(Cartes::texte($onglet, '[data-notes-de-l-ecran]'))->toContain('Par exercice : la période est les 10 dernières années pour tout l\'écran')->toContain('chaque barre est un exercice.');
});
