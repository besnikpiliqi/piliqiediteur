<?php

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use Tests\Browser\Support\Navigation;

/*
 * Les gestes de préférence de la grille des graphiques ne font pas attendre
 * (décision de l'éditeur du 23/11/2026, constat : « quand je change de
 * Barres à Ligne, ça prend beaucoup de temps ») : le type d'un graphique,
 * « Monter », « Descendre », la croix, « Classer par » et le menu
 * « Afficher : » de l'écart des prix d'achat changent l'écran avant la
 * réponse du serveur, que le test retient trois secondes ; le serveur les
 * retient ensuite en arrière-plan, sans redessiner la grille ; un
 * rechargement redonne l'état choisi. Si l'enregistrement échoue (le réseau,
 * une erreur du serveur, un refus), l'écran revient à ce qui est enregistré
 * avec une alerte en français. Le test retient les réponses en enveloppant
 * `fetch` dans la page : le navigateur de Pest n'expose pas l'interception
 * des requêtes de Playwright (page.route).
 */

const CARTE_DU_TYPE = 'ca_par_mois';

const CARTE_DU_CLASSEMENT = 'meilleurs_produits';

const CARTE_DE_L_AFFICHAGE = 'ecart_des_prix_d_achat';

const CARTE_RETIREE = 'ca_par_magasin';

/**
 * Les cartes de la grille de l'espace de la société du propriétaire : le
 * chiffre d'affaires par mois, les meilleurs produits, l'écart des prix
 * d'achat et le chiffre d'affaires par magasin, dans cet ordre.
 */
function gestesPoserLesCartes(object $societe): void
{
    app(PreferencesDesGraphiques::class)->enregistrer($societe->proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
        CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois),
        CarteDuTableauDeBord::parDefaut(Graphique::MeilleursProduits),
        CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat),
        CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMagasin),
    ]));
}

/**
 * Les réponses de `/livewire/update` n'arrivent qu'après $ms millisecondes :
 * la page garde, pour chaque requête, les méthodes qu'elle appelle, si sa
 * réponse est arrivée et si elle porte le HTML de la grille. Le `mode`
 * « reseau » fait échouer la requête (aucune réponse), « erreur » lui donne
 * une erreur 500, « refus » change la valeur du type en une valeur que le
 * serveur ne connaît pas.
 */
function gestesRetenirLesReponses(Navigation $onglet, int $ms, string $mode = 'retard'): void
{
    $onglet->page()->evaluate(<<<'JS'
        ([ms, mode]) => {
            const original = window.fetch;
            window.__retard = { ms, mode, requetes: [] };
            window.fetch = function (ressource, options) {
                const adresse = ressource instanceof Request ? ressource.url : String(ressource);

                if (! adresse.includes('/livewire/update')) {
                    return original.call(this, ressource, options);
                }

                const reglage = window.__retard;
                const releve = { methodes: [], livre: false, html: null };
                reglage.requetes.push(releve);

                try {
                    const corps = JSON.parse(options.body);
                    releve.methodes = corps.components.flatMap((composant) => composant.calls.map((appel) => appel.method));

                    if (reglage.mode === 'refus') {
                        for (const composant of corps.components) {
                            for (const appel of composant.calls) {
                                if (appel.method === 'changerLeType') {
                                    appel.params[1] = 'histogramme';
                                }
                            }
                        }

                        options = { ...options, body: JSON.stringify(corps) };
                    }
                } catch (erreur) {
                    releve.methodes = ['?'];
                }

                if (reglage.mode === 'reseau') {
                    return new Promise((resolve, rejeter) => setTimeout(() => {
                        releve.livre = true;
                        rejeter(new TypeError('Failed to fetch'));
                    }, reglage.ms));
                }

                if (reglage.mode === 'erreur') {
                    return new Promise((resolve) => setTimeout(() => {
                        releve.livre = true;
                        resolve(new Response('Erreur du serveur', { status: 500 }));
                    }, reglage.ms));
                }

                return original.call(this, ressource, options).then((reponse) => reponse.clone().json().then((json) => {
                    releve.html = json.components.some((composant) => composant.effects && 'html' in composant.effects);

                    return new Promise((resolve) => setTimeout(() => {
                        releve.livre = true;
                        resolve(reponse);
                    }, reglage.ms));
                }));
            };
        }
        JS, [$ms, $mode]);
}

/**
 * Ce que la page a demandé au serveur depuis gestesRetenirLesReponses().
 *
 * @return list<array{methodes: list<string>, livre: bool, html: bool|null}>
 */
function gestesRequetes(Navigation $onglet): array
{
    $requetes = $onglet->page()->evaluate('() => window.__retard.requetes');

    return is_array($requetes) ? array_values($requetes) : [];
}

/**
 * Le geste que la page vient de faire a changé l'écran (l'expression
 * JavaScript devient vraie) alors que la réponse du serveur n'est pas
 * arrivée : la page a envoyé la requête de ce rang (à partir de 0) et sa
 * réponse est encore retenue. La page attend l'expression et lit les
 * réponses retenues dans le même appel, à chaque image, pour que la lenteur
 * du poste de test ne compte pas : seules les images de la page séparent le
 * geste de ce qu'elle mesure.
 */
function gestesChangeAussitot(Navigation $onglet, string $expression, int $rang, string $geste): void
{
    $mesure = $onglet->page()->evaluate(<<<'JS'
        async ([expression, rang]) => {
            const condition = new Function('return (' + expression + ')');
            const debut = performance.now();
            const requetes = window.__retard.requetes;

            // Livewire regroupe les appels pendant 5 ms avant d'envoyer la requête : l'écran a changé, la requête part ensuite.
            while (! condition() || requetes.length <= rang) {
                if (performance.now() - debut > 10000) {
                    return { vrai: condition(), envoyees: requetes.length, rangLivre: requetes.map((requete) => requete.livre), methodes: requetes.map((requete) => requete.methodes.join('+')) };
                }

                await new Promise((resolve) => requestAnimationFrame(resolve));
            }

            return { vrai: true, ms: Math.round(performance.now() - debut), envoyees: requetes.length, rangLivre: requetes.map((requete) => requete.livre), methodes: requetes.map((requete) => requete.methodes.join('+')) };
        }
        JS, [$expression, $rang]);

    expect($mesure['vrai'] ?? false)->toBeTrue("{$geste} : l'écran ne change pas");
    expect($mesure['envoyees'])->toBeGreaterThan($rang, "{$geste} n'a rien envoyé au serveur")
        ->and($mesure['rangLivre'][$rang])->toBeFalse("{$geste} : le serveur avait déjà répondu quand l'écran a changé (".json_encode($mesure).')');
}

/**
 * Attend toutes les réponses retenues, puis que la page soit au repos.
 */
function gestesAttendreLesReponses(Navigation $onglet): void
{
    $onglet->attendreQue('window.__retard.requetes.length > 0 && window.__retard.requetes.every((requete) => requete.livre)', 'une réponse retenue n’arrive pas')
        ->attendre()
        ->verifier();
}

/**
 * L'ordre des cartes montrées, leur type, la mesure des meilleurs produits
 * et l'affichage de l'écart, tels que la page les montre.
 *
 * @return array{ordre: list<string>, types: array<string, string>, mesure: string|null, affichage: string|null, menu: list<string>}
 */
function gestesEtatDeLaPage(Navigation $onglet): array
{
    $etat = $onglet->page()->evaluate(<<<'JS'
        () => {
            const cartes = Array.from(document.querySelectorAll('[data-grille-cartes] > [data-graphique]')).filter((carte) => carte.checkVisibility());
            const types = {};

            for (const carte of cartes) {
                types[carte.dataset.graphique] = carte.querySelector('select[data-geste="type"]').value;
            }

            const mesure = document.querySelector('[data-graphique="meilleurs_produits"] select[data-geste="mesure"]');
            const affichage = document.querySelector('[data-graphique="ecart_des_prix_d_achat"] select[data-geste="affichage"]');

            return {
                ordre: cartes.map((carte) => carte.dataset.graphique),
                types,
                mesure: mesure === null || ! mesure.closest('[data-graphique]').checkVisibility() ? null : mesure.value,
                affichage: affichage === null || ! affichage.closest('[data-graphique]').checkVisibility() ? null : affichage.value,
                menu: Array.from(document.querySelectorAll('select[data-filtre="ajouter"] option')).filter((option) => option.value !== '' && ! option.hidden && ! option.disabled).map((option) => option.value),
            };
        }
        JS);

    return is_array($etat) ? $etat : [];
}

/**
 * Le graphique de Chart.js de cette carte (ou de cette face) est posé, du
 * type donné, et dessiné.
 */
function gestesGraphiqueDessine(string $carte, string $type, string $face = ''): string
{
    $portee = '[data-graphique="'.$carte.'"]'.($face === '' ? '' : ' [data-face="'.$face.'"]');

    return '(() => { const pose = document.querySelector(\''.$portee.' [data-graphique-pose]\'); const c = document.querySelector(\''.$portee.' canvas\'); if (! pose || ! c || pose.getAttribute("data-graphique-pose") !== "'.$type.'" || c.width === 0) return false; const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data; for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) return true; } return false; })()';
}

/**
 * Aucune des requêtes que le test retient n'a ramené le HTML de la grille.
 */
function gestesToutesSansHtml(Navigation $onglet): bool
{
    return array_filter(gestesRequetes($onglet), fn (array $requete): bool => $requete['html'] === true) === [];
}

test('le type, « Monter », « Descendre », la croix, « Classer par » et « Afficher : » changent l’écran avant la réponse du serveur, retenue trois secondes ; le serveur les retient ensuite sans redessiner la grille, et un rechargement les redonne', function () {
    // La cloche regarde le serveur d'elle-même à son rythme : une heure, pour qu'aucune requête que le test n'a pas faite ne parte.
    config(['piliqi.intervalle_des_notifications_en_secondes' => 3600]);
    $societe = $this->societeComplete();
    gestesPoserLesCartes($societe);
    $carte = fn (string $graphique): string => '[data-graphique="'.$graphique.'"]';

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre cartes ne sont pas là')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'barres'), 'le chiffre d’affaires par mois n’est pas dessiné en barres')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_CLASSEMENT, 'barres_couchees', 'ca'), 'les meilleurs produits ne sont pas dessinés')
        ->attendreQue('document.querySelectorAll("[data-graphique] canvas").length === 3', 'une carte à canevas a plus d’un graphique');
    gestesRetenirLesReponses($onglet, 3000);

    expect(gestesEtatDeLaPage($onglet)['ordre'])->toBe([CARTE_DU_TYPE, CARTE_DU_CLASSEMENT, CARTE_DE_L_AFFICHAGE, CARTE_RETIREE]);

    $ordreDesCartes = fn (array $ordre): string => 'Array.from(document.querySelectorAll("[data-grille-cartes] > [data-graphique]")).filter((c) => c.checkVisibility()).map((c) => c.dataset.graphique).join() === "'.implode(',', $ordre).'"';

    // Le type : Barres devient Lignes, dessiné de nouveau dans la page, avant toute réponse.
    $onglet->element($carte(CARTE_DU_TYPE).' select[data-geste="type"]')->selectOption('lignes');
    gestesChangeAussitot($onglet, gestesGraphiqueDessine(CARTE_DU_TYPE, 'lignes'), 0, 'Le type');
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet))->toHaveCount(1)
        ->and(gestesRequetes($onglet)[0]['methodes'])->toBe(['changerLeType'])
        ->and(gestesRequetes($onglet)[0]['html'])->toBeFalse('La réponse du type porte le HTML de la grille');

    // « Monter » : les meilleurs produits passent devant, et leur « Monter » n'est plus offert.
    $onglet->element($carte(CARTE_DU_CLASSEMENT).' [data-geste="monter"]')->click();
    gestesChangeAussitot($onglet, $ordreDesCartes([CARTE_DU_CLASSEMENT, CARTE_DU_TYPE, CARTE_DE_L_AFFICHAGE, CARTE_RETIREE]).' && document.querySelector(\''.$carte(CARTE_DU_CLASSEMENT).' [data-geste="monter"]\').disabled && ! document.querySelector(\''.$carte(CARTE_DU_TYPE).' [data-geste="monter"]\').disabled', 1, '« Monter »');
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet))->toHaveCount(2)
        ->and(gestesRequetes($onglet)[1]['methodes'])->toBe(['monter'])
        ->and(gestesRequetes($onglet)[1]['html'])->toBeFalse();

    // « Descendre ».
    $onglet->element($carte(CARTE_DE_L_AFFICHAGE).' [data-geste="descendre"]')->click();
    gestesChangeAussitot($onglet, $ordreDesCartes([CARTE_DU_CLASSEMENT, CARTE_DU_TYPE, CARTE_RETIREE, CARTE_DE_L_AFFICHAGE]), 2, '« Descendre »');
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet)[2]['methodes'])->toBe(['descendre'])
        ->and(gestesRequetes($onglet)[2]['html'])->toBeFalse();

    // « Classer par » : le classement par quantité, déjà dans la page, remplace celui du chiffre d'affaires, avec son graphique.
    $onglet->element($carte(CARTE_DU_CLASSEMENT).' select[data-geste="mesure"]')->selectOption('quantite');
    gestesChangeAussitot($onglet, 'document.querySelector(\''.$carte(CARTE_DU_CLASSEMENT).' [data-face="quantite"]\').checkVisibility() && ! document.querySelector(\''.$carte(CARTE_DU_CLASSEMENT).' [data-face="ca"]\').checkVisibility() && document.querySelectorAll(\''.$carte(CARTE_DU_CLASSEMENT).' canvas\').length === 1', 3, '« Classer par »');
    $onglet->attendreQue(gestesGraphiqueDessine(CARTE_DU_CLASSEMENT, 'barres_couchees', 'quantite'), 'le classement par quantité n’est pas dessiné dans la page');
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet)[3]['methodes'])->toBe(['changerLaMesure'])
        ->and(gestesRequetes($onglet)[3]['html'])->toBeFalse();

    // « Afficher : » : les douze mois, déjà dans la page, remplacent l'affichage par mois, avec leur titre.
    $onglet->element($carte(CARTE_DE_L_AFFICHAGE).' select[data-geste="affichage"]')->selectOption('debut');
    $montre = fn (string $affichage): string => 'getComputedStyle(document.querySelector(\''.$carte(CARTE_DE_L_AFFICHAGE).' [data-ecart-affichage="'.$affichage.'"]\')).display !== "none"';
    gestesChangeAussitot($onglet, $montre('debut').' && ! '.$montre('avant').' && document.querySelector(\''.$carte(CARTE_DE_L_AFFICHAGE).' [data-titre]\').textContent.includes("cumulé sur 12 mois")', 4, '« Afficher : »');
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet)[4]['methodes'])->toBe(['changerLAffichage'])
        ->and(gestesRequetes($onglet)[4]['html'])->toBeFalse();

    // La croix : la carte disparaît aussitôt et le menu « Ajouter un graphique » la propose de nouveau.
    expect(gestesEtatDeLaPage($onglet)['menu'])->not->toContain(CARTE_RETIREE);
    $onglet->element($carte(CARTE_RETIREE).' [data-geste="retirer"]')->click();
    gestesChangeAussitot($onglet, '! document.querySelector(\''.$carte(CARTE_RETIREE).'\').checkVisibility() && document.querySelector(\'select[data-filtre="ajouter"] option[value="'.CARTE_RETIREE.'"]\').hidden === false', 5, 'La croix');
    expect(gestesEtatDeLaPage($onglet)['menu'])->toContain(CARTE_RETIREE);
    gestesAttendreLesReponses($onglet);
    expect(gestesRequetes($onglet)[5]['methodes'])->toBe(['retirerLeGraphique'])
        ->and(gestesToutesSansHtml($onglet))->toBeTrue('Un geste de préférence a redessiné la grille');

    $avant = gestesEtatDeLaPage($onglet);
    expect($avant['ordre'])->toBe([CARTE_DU_CLASSEMENT, CARTE_DU_TYPE, CARTE_DE_L_AFFICHAGE])
        ->and($avant['types'][CARTE_DU_TYPE])->toBe('lignes')
        ->and($avant['mesure'])->toBe('quantite')
        ->and($avant['affichage'])->toBe('debut');

    // Rechargé : ce que le serveur a retenu, comme la page le montrait, et le dessin du type retenu.
    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les trois cartes retenues ne sont pas là')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'lignes'), 'le type lignes n’est pas retenu')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_CLASSEMENT, 'barres_couchees', 'quantite'), 'le classement par quantité n’est pas retenu');
    expect(gestesEtatDeLaPage($onglet))->toBe($avant);
});

test('plusieurs gestes rapides s’enregistrent dans l’ordre, et le redessin d’un autre geste (la période) ne défait pas le dernier', function () {
    config(['piliqi.intervalle_des_notifications_en_secondes' => 3600]);
    $societe = $this->societeComplete();
    gestesPoserLesCartes($societe);
    $carte = fn (string $graphique): string => '[data-graphique="'.$graphique.'"]';
    $ordre = 'Array.from(document.querySelectorAll("[data-grille-cartes] > [data-graphique]")).filter((c) => c.checkVisibility()).map((c) => c.dataset.graphique).join()';

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre cartes ne sont pas là')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'barres'), 'le chiffre d’affaires par mois n’est pas dessiné');
    gestesRetenirLesReponses($onglet, 3000);

    // Cinq gestes à la suite, avant que le serveur ait répondu au premier.
    $onglet->element($carte(CARTE_DU_TYPE).' select[data-geste="type"]')->selectOption('aires');
    $onglet->element($carte(CARTE_DU_CLASSEMENT).' [data-geste="monter"]')->click();
    $onglet->element($carte(CARTE_DU_CLASSEMENT).' select[data-geste="mesure"]')->selectOption('quantite');
    $onglet->element($carte(CARTE_RETIREE).' [data-geste="retirer"]')->click();
    $onglet->element($carte(CARTE_DE_L_AFFICHAGE).' select[data-geste="affichage"]')->selectOption('debut');
    $onglet->attendreQue($ordre.' === "'.implode(',', [CARTE_DU_CLASSEMENT, CARTE_DU_TYPE, CARTE_DE_L_AFFICHAGE]).'"', 'les gestes rapides ne sont pas tous dans la page')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'aires'), 'le type aires n’est pas dessiné');
    $voulu = gestesEtatDeLaPage($onglet);
    expect(count(array_filter(gestesRequetes($onglet), fn (array $requete): bool => $requete['livre'])))->toBe(0, 'Le serveur avait répondu avant la fin des cinq gestes');

    gestesAttendreLesReponses($onglet);
    $appels = array_merge(...array_column(gestesRequetes($onglet), 'methodes'));
    expect($appels)->toBe(['changerLeType', 'monter', 'changerLaMesure', 'retirerLeGraphique', 'changerLAffichage'], 'Les gestes s’enregistrent dans l’ordre où ils sont faits')
        ->and(gestesToutesSansHtml($onglet))->toBeTrue();
    expect(gestesEtatDeLaPage($onglet))->toBe($voulu, 'La page garde ce que la personne a fait');

    // La période redessine la grille (la réponse est retenue trois secondes) : « Descendre » la première carte pendant ce temps.
    $avantLaPeriode = count(gestesRequetes($onglet));
    $onglet->element('select[data-filtre="periode"]')->selectOption('annee_en_cours');
    $onglet->element($carte(CARTE_DU_CLASSEMENT).' [data-geste="descendre"]')->click();
    $onglet->attendreQue($ordre.' === "'.implode(',', [CARTE_DU_TYPE, CARTE_DU_CLASSEMENT, CARTE_DE_L_AFFICHAGE]).'"', '« Descendre » n’est pas dans la page pendant que la période se redessine');
    $onglet->attendreQue('window.__retard.requetes.length > '.$avantLaPeriode.' && window.__retard.requetes[window.__retard.requetes.length - 1].livre', 'la réponse de la période n’arrive pas');

    // Le redessin de la période est arrivé, avec l'ordre d'avant « Descendre » : la page garde « Descendre ».
    $onglet->attendreQue($ordre.' === "'.implode(',', [CARTE_DU_TYPE, CARTE_DU_CLASSEMENT, CARTE_DE_L_AFFICHAGE]).'"', 'le redessin de la période défait « Descendre »');
    expect(gestesRequetes($onglet)[$avantLaPeriode]['html'])->toBeTrue('La période redessine la grille')
        ->and(gestesEtatDeLaPage($onglet)['types'][CARTE_DU_TYPE])->toBe('aires');
    gestesAttendreLesReponses($onglet);

    $apres = gestesEtatDeLaPage($onglet);
    expect($apres['ordre'])->toBe([CARTE_DU_TYPE, CARTE_DU_CLASSEMENT, CARTE_DE_L_AFFICHAGE])
        ->and($apres['mesure'])->toBe('quantite')
        ->and($apres['affichage'])->toBe('debut');

    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les trois cartes retenues ne sont pas là')
        ->attendreQue('document.querySelector(\'select[data-filtre="periode"]\').value === "annee_en_cours"', 'la période n’est pas retenue');
    expect(gestesEtatDeLaPage($onglet))->toBe($apres, 'Le rechargement ne redonne pas ce que la page montrait');

    // Deux gestes qui redessinent, envoyés avec un geste de préférence dans la même requête, redessinent la grille.
    $onglet->page()->evaluate('() => { const grille = document.querySelector("[data-grille-cartes]").closest("[wire\\\\:id]"); const wire = window.Livewire.find(grille.getAttribute("wire:id")); wire.monter("'.CARTE_DU_CLASSEMENT.'"); wire.comparer(true); }');
    $onglet->attendreQue('document.querySelector(\'input[data-filtre="comparer"]\').checked', 'la comparaison n’est pas dans la page')
        ->attendre()
        ->verifier();
    $onglet->attendreQue('Array.from(document.querySelectorAll("[data-grille-cartes] > [data-graphique]")).map((c) => c.dataset.graphique)[0] === "'.CARTE_DU_CLASSEMENT.'"', 'le geste de préférence envoyé avec la comparaison n’est pas retenu');
});

test('si l’enregistrement échoue (réseau coupé, erreur du serveur, refus), l’écran revient à ce qui est enregistré et une alerte en français le dit', function (string $mode, string $alerte, string $ou, bool $aussiMonter) {
    config(['piliqi.intervalle_des_notifications_en_secondes' => 3600]);
    $societe = $this->societeComplete();
    gestesPoserLesCartes($societe);
    $carte = fn (string $graphique): string => '[data-graphique="'.$graphique.'"]';
    $reglagesAvant = PreferencesDuTableauDeBord::query()->withoutGlobalScopes()->where('user_id', $societe->proprietaire->id)->sole()->reglages;

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre cartes ne sont pas là')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'barres'), 'le chiffre d’affaires par mois n’est pas dessiné');
    gestesRetenirLesReponses($onglet, 1500, $mode);
    $depart = gestesEtatDeLaPage($onglet);

    // Le type change aussitôt, la carte monte aussitôt ; puis l'enregistrement échoue.
    $onglet->element($carte(CARTE_DU_TYPE).' select[data-geste="type"]')->selectOption('lignes');
    $onglet->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'lignes'), 'le type ne change pas aussitôt dans la page');
    expect(gestesRequetes($onglet))->not->toBe([])
        ->and(gestesRequetes($onglet)[0]['livre'])->toBeFalse();

    $onglet->attendreQue('window.__retard.requetes.every((requete) => requete.livre)', 'la réponse retenue n’arrive pas')
        ->attendreQue('Array.from(document.querySelectorAll('.json_encode($ou, JSON_UNESCAPED_UNICODE).')).some((alerte) => alerte.checkVisibility() && alerte.textContent.includes('.json_encode($alerte, JSON_UNESCAPED_UNICODE).'))', 'l’alerte « '.$alerte.' » ne paraît pas')
        ->attendreQue(gestesGraphiqueDessine(CARTE_DU_TYPE, 'barres'), 'le graphique ne revient pas en barres')
        ->attendreQue('document.querySelector(\''.$carte(CARTE_DU_TYPE).' select[data-geste="type"]\').value === "barres"', 'le menu du type ne revient pas sur « Barres »');
    expect(gestesEtatDeLaPage($onglet))->toBe($depart)
        ->and($onglet->page()->evaluate('() => document.getElementById("livewire-error") === null'))->toBeTrue('La fenêtre d’erreur de Livewire s’est ouverte');

    // « Monter » échoue de même quand le serveur n'est pas joint : la carte revient à sa place.
    if ($aussiMonter) {
        $onglet->element($carte(CARTE_DU_CLASSEMENT).' [data-geste="monter"]')->click();
        $onglet->attendreQue('Array.from(document.querySelectorAll("[data-grille-cartes] > [data-graphique]"))[0].dataset.graphique === "'.CARTE_DU_CLASSEMENT.'"', '« Monter » ne change pas aussitôt la page');
        $onglet->attendreQue('window.__retard.requetes.length === 2 && window.__retard.requetes.every((requete) => requete.livre)', 'la réponse de « Monter » n’arrive pas')
            ->attendreQue('Array.from(document.querySelectorAll("[data-grille-cartes] > [data-graphique]"))[0].dataset.graphique === "'.CARTE_DU_TYPE.'"', 'la carte ne revient pas à sa place');
        expect(gestesEtatDeLaPage($onglet))->toBe($depart);
    }

    // Rien n'a été enregistré.
    expect(PreferencesDuTableauDeBord::query()->withoutGlobalScopes()->where('user_id', $societe->proprietaire->id)->sole()->reglages)->toBe($reglagesAvant);
    $onglet->aller($onglet->chemin())->attendre();
    expect(gestesEtatDeLaPage($onglet))->toBe($depart);
})->with([
    'réseau coupé' => ['reseau', 'L’enregistrement n’a pas abouti', '[data-alerte-de-la-grille]', true],
    'erreur du serveur' => ['erreur', 'L’enregistrement n’a pas abouti', '[data-alerte-de-la-grille]', true],
    'refus du serveur' => ['refus', 'Ce choix n\'existe pas dans le tableau de bord : rien n\'a changé.', '.fi-no-notification', false],
]);
