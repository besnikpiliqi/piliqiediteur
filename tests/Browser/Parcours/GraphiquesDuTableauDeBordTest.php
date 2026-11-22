<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Les chiffres clés et les graphiques du tableau de bord dans le navigateur
 * (décision de l'éditeur du 14/11/2026, modèle « G1 + chiffres de G2 »,
 * lot GRAPH) : chaque carte se dessine avec le Chart.js de Filament, et
 * chaque geste (ajouter, changer le type, retirer) se retient en base,
 * retrouvé après le rechargement de la page, pour chaque rôle qui voit la
 * grille et sur chaque écran qui la montre : l'espace de la société, le
 * panneau d'un magasin (le widget GraphiquesDuMagasin), la page d'un
 * client dans l'espace du cabinet et celle de l'expert-comptable déclaré.
 * La société de test a deux magasins, des ventes, des achats et un avoir
 * (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */

/**
 * La carte de ce graphique est-elle dessinée : son canevas a des pixels
 * peints par Chart.js ? La carte « Écart des prix d'achat », qui n'a pas de
 * canevas, est dessinée quand son `svg` porte ses douze colonnes et, sous
 * chacune, l'étiquette de son mois (décision de l'éditeur du 21/11/2026,
 * D76, B7 : un dessin par carte, sans en affaiblir l'exigence).
 */
function carteDessinee(string $graphique): string
{
    if ($graphique === Graphique::EcartDesPrixDAchat->value) {
        return '(() => { const d = document.querySelector(\'[data-graphique="'.$graphique.'"] svg[role="group"]\'); return d !== null && d.querySelectorAll(\'g[role="button"]\').length === 12 && d.querySelectorAll(\'g[role="button"] > text[font-weight]\').length === 12 && d.getBoundingClientRect().height > 100; })()';
    }

    return '(() => { const c = document.querySelector(\'[data-graphique="'.$graphique.'"] canvas\'); if (! c || c.width === 0) return false; const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data; for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) return true; } return false; })()';
}

/**
 * Sur la grille de la page ouverte, qui montre $cartes cartes : ajoute ce
 * graphique, qui vient en dernière carte, le passe en ce type, le monte
 * d'un rang, puis recharge la page et y retrouve la carte à son rang, avec
 * son type, dessinée. La page défile jusqu'à la grille, que le panneau
 * d'un magasin charge quand elle paraît à l'écran.
 */
function composerLaGrille(Navigation $onglet, int $cartes, string $graphique, string $type): Navigation
{
    $defiler = 'window.scrollTo(0, document.body.scrollHeight), ';
    $rang = 'Array.from(document.querySelectorAll("[data-graphique]")).map((carte) => carte.dataset.graphique).indexOf('.json_encode($graphique).')';
    $carte = '[data-graphique="'.$graphique.'"]';

    $onglet->attendreQue('('.$defiler.'document.querySelectorAll("[data-graphique]").length === '.$cartes.')', "les {$cartes} graphiques par défaut ne sont pas là");
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption($graphique), "l’ajout de {$graphique}")
        ->attendreQue($rang.' === '.$cartes, "{$graphique} n’est pas ajouté en dernière carte");
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="type"]')->selectOption($type), "le passage de {$graphique} en {$type}");
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' [data-geste="monter"]')->click(), "la montée de {$graphique}")
        ->attendreQue($rang.' === '.($cartes - 1), "{$graphique} n’est pas monté d’un rang");

    return $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('('.$defiler.$rang.' === '.($cartes - 1).')', "{$graphique} n’est pas retenu à son rang après le rechargement")
        ->attendreQue('document.querySelector(\''.$carte.' select[data-geste="type"]\').value === "'.$type.'"', "le type {$type} de {$graphique} n’est pas retenu")
        ->attendreQue(carteDessinee($graphique), "{$graphique} n’est pas dessiné");
}

test('le propriétaire voit les quatre chiffres et les graphiques par défaut, ajoute la marge, passe un graphique en camembert, en retire un, et retrouve tout après le rechargement', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');

    $onglet->voir('CA HT du mois')->voir('Achats HT du mois')->voir('TVA à payer (estimation)')->voir('Restant dû des clients')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre graphiques par défaut ne sont pas là')
        ->attendreQue(carteDessinee('ca_par_magasin'), 'le chiffre d’affaires par magasin n’est pas dessiné');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('marge_par_mois'), 'l’ajout de la marge')
        ->attendreQue('document.querySelector(\'[data-graphique="marge_par_mois"]\') !== null', 'la marge n’est pas ajoutée');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('[data-graphique="ca_par_magasin"] select[data-geste="type"]')->selectOption('camembert'), 'le passage en camembert');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('[data-graphique="tva_par_mois"] [data-geste="retirer"]')->click(), 'le retrait de la TVA')
        ->attendreQue('document.querySelector(\'[data-graphique="tva_par_mois"]\') === null', 'la TVA n’est pas retirée');

    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelector(\'[data-graphique="marge_par_mois"]\') !== null', 'la marge n’est pas retenue')
        ->attendreQue('document.querySelector(\'[data-graphique="tva_par_mois"]\') === null', 'le retrait n’est pas retenu')
        ->attendreQue('document.querySelector(\'[data-graphique="ca_par_magasin"] select[data-geste="type"]\').value === "camembert"', 'le camembert n’est pas retenu')
        ->attendreQue(carteDessinee('ca_par_magasin'), 'le camembert n’est pas dessiné')
        ->voir('Marge brute HT par mois');
});

test('un administrateur à qui le propriétaire coche les graphiques ne voit que les magasins de sa portée', function () {
    $societe = $this->societeComplete();
    $nina = $this->administrateur($societe->magasin, [$societe->magasin->id], [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR], 'Nina Faure');
    app(TenantContext::class)->clear();

    $this->seConnecter($nina, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les trois graphiques par défaut d’un seul magasin ne sont pas là')
        ->voir('CA HT du mois')
        ->attendreQue(carteDessinee('ca_par_mois'), 'le chiffre d’affaires par mois n’est pas dessiné')
        ->attendreQue('document.querySelector(\'select[data-filtre="magasin"]\') === null', 'le filtre des magasins paraît pour un seul magasin')
        ->nePasVoir('Boutique de la Gare');
});

test('un responsable à qui le propriétaire coche les graphiques les voit dans le panneau de son magasin, sans filtre ni comparaison des magasins', function () {
    $societe = $this->societeComplete();
    $lea = $this->membre($societe->magasin, RoleMagasin::Responsable, [PermissionRegistry::GRAPHIQUES_VOIR], ['name' => 'Léa Roux']);
    app(TenantContext::class)->clear();

    // La grille vient après « À faire » et se charge quand elle paraît à
    // l'écran (chargement différé) : la page défile jusqu'à elle.
    $this->seConnecter($lea)
        ->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelectorAll("[data-graphique]").length === 3)', 'les trois graphiques par défaut du magasin ne sont pas là')
        ->voir('CA HT du mois')
        ->attendreQue(carteDessinee('ventes_et_achats_par_mois'), 'les ventes et les achats ne sont pas dessinés')
        ->attendreQue('document.querySelector(\'select[data-filtre="magasin"]\') === null', 'le panneau d’un magasin montre un filtre des magasins')
        ->attendreQue('document.querySelector(\'select[data-filtre="ajouter"] option[value="ca_par_magasin"]\') === null', 'le panneau d’un magasin propose le chiffre d’affaires par magasin');
});

test('le collaborateur à qui le client est confié ouvre la page de ses graphiques depuis la liste des clients', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients')->attendre()->verifier()
        ->cliquerSurLeLien('Graphiques')
        ->attendreQue('location.pathname.endsWith("/graphiques")', 'la page des graphiques du client ne s’ouvre pas')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les trois graphiques par défaut du client ne sont pas là')
        ->voir('CA HT du mois')
        ->attendreQue(carteDessinee('ca_par_mois'), 'le chiffre d’affaires par mois du client n’est pas dessiné');
});

test('l’expert-comptable déclaré ouvre « Tableau de bord de la société » depuis son menu', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $this->remplirLeMagasin($magasin, $this->membre($magasin, RoleMagasin::Responsable), $this->membre($magasin, RoleMagasin::Vendeur));
    $marc = $this->expertComptable($magasin);

    $this->seConnecter($marc)
        ->cliquerSurLeLien('Tableau de bord de la société')
        ->attendreQue('location.pathname.endsWith("/tableau-de-bord-de-la-societe")', 'la page de l’expert-comptable ne s’ouvre pas')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les graphiques par défaut de l’expert-comptable ne sont pas là')
        ->voir('CA HT du mois')
        ->attendreQue(carteDessinee('ca_par_mois'), 'le chiffre d’affaires par mois n’est pas dessiné');
});

test('un vendeur ne voit ni les chiffres clés ni les graphiques, ni la page de la société', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->vendeur)
        ->voir('Bonjour')
        ->nePasVoir('CA HT du mois')
        ->nePasVoir('Tableau de bord de la société')
        ->attendreQue('document.querySelector("[data-graphique]") === null', 'un graphique paraît au vendeur');
});

test('le propriétaire choisit la période, un magasin et la comparaison, classe les meilleurs produits par quantité, déplace deux cartes, lit les chiffres d’une carte, et retrouve tout après le rechargement', function () {
    $societe = $this->societeComplete();
    $port = (string) $societe->magasin->uuid;
    $ordre = 'Array.from(document.querySelectorAll("[data-graphique]")).map((carte) => carte.dataset.graphique).join(",")';
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre graphiques par défaut ne sont pas là');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="periode"]')->selectOption('annee_en_cours'), 'le choix de l’année en cours');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption($port), 'le choix de la Boutique du Port');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('input[data-filtre="comparer"]')->click(), 'la comparaison avec l’année précédente')
        ->attendreQue('document.querySelector(\'[data-graphique="ca_par_mois"]\').textContent.includes("un an plus tôt")', 'la comparaison n’ajoute pas l’année précédente');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('meilleurs_produits'), 'l’ajout des meilleurs produits');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('[data-graphique="meilleurs_produits"] select[data-geste="mesure"]')->selectOption('quantite'), 'le classement par quantité');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('[data-graphique="meilleurs_produits"] [data-geste="monter"]')->click(), 'la montée des meilleurs produits');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('[data-graphique="ventes_et_achats_par_mois"] [data-geste="descendre"]')->click(), 'la descente des ventes et achats')
        ->attendreQue($ordre.' === "tva_par_mois,ventes_et_achats_par_mois,ca_par_mois,meilleurs_produits,ca_par_magasin"', 'les cartes ne sont pas dans l’ordre voulu');
    $onglet->geste(fn () => $onglet->element('[data-graphique="ca_par_mois"] [data-geste="chiffres"]')->click(), 'l’ouverture des chiffres')
        ->attendreQue('document.querySelector(\'[data-graphique="ca_par_mois"] table\') !== null && document.querySelector(\'[data-graphique="ca_par_mois"] table\').offsetParent !== null', 'le tableau des chiffres ne s’ouvre pas');

    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue($ordre.' === "tva_par_mois,ventes_et_achats_par_mois,ca_par_mois,meilleurs_produits,ca_par_magasin"', 'l’ordre des cartes n’est pas retenu')
        ->attendreQue('document.querySelector(\'select[data-filtre="periode"]\').value === "annee_en_cours"', 'la période n’est pas retenue')
        ->attendreQue('document.querySelector(\'select[data-filtre="magasin"]\').value === '.json_encode($port), 'le magasin n’est pas retenu')
        ->attendreQue('document.querySelector(\'input[data-filtre="comparer"]\').checked', 'la comparaison n’est pas retenue')
        ->attendreQue('document.querySelector(\'[data-graphique="meilleurs_produits"] select[data-geste="mesure"]\').value === "quantite"', 'le classement par quantité n’est pas retenu')
        ->attendreQue(carteDessinee('meilleurs_produits'), 'les meilleurs produits ne sont pas dessinés');
});

test('le propriétaire du cabinet ouvre les graphiques d’un client depuis sa fiche', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->sole()->uuid)->attendre()->verifier()
        ->cliquerSurLeLien('Graphiques')
        ->attendreQue('location.pathname.endsWith("/graphiques")', 'la page des graphiques du client ne s’ouvre pas depuis sa fiche')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 3', 'les graphiques du client ne sont pas là')
        ->voir('CA HT du mois');
});

test('le propriétaire, dans le panneau d’un magasin, ajoute un graphique, en change le type, le monte, et retrouve tout après le rechargement, sans filtre des magasins', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier();

    composerLaGrille($onglet, 3, 'ventes_par_taux', 'camembert')
        ->voir('CA HT du mois')
        ->attendreQue('document.querySelector(\'select[data-filtre="magasin"]\') === null', 'le panneau d’un magasin montre un filtre des magasins au propriétaire');
});

test('un administrateur à qui le propriétaire coche les graphiques compose sa grille dans l’espace de la société puis dans le panneau de son magasin, et retrouve chacune après le rechargement', function () {
    $societe = $this->societeComplete();
    $nina = $this->administrateur($societe->magasin, [$societe->magasin->id], [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR], 'Nina Faure');
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($nina, panneau: 'societe');
    composerLaGrille($onglet, 3, 'avoirs_par_mois', 'lignes');

    $onglet->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier();
    composerLaGrille($onglet, 3, 'ventes_par_jour', 'radar')
        ->attendreQue('document.querySelector(\'[data-graphique="avoirs_par_mois"]\') === null', 'le réglage de l’espace de la société déborde sur le panneau du magasin');
});

test('un responsable à qui le propriétaire coche les graphiques compose sa grille dans le panneau de son magasin et la retrouve après le rechargement', function () {
    $societe = $this->societeComplete();
    $lea = $this->membre($societe->magasin, RoleMagasin::Responsable, [PermissionRegistry::GRAPHIQUES_VOIR], ['name' => 'Léa Roux']);
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($lea)->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier();

    composerLaGrille($onglet, 3, 'particuliers_et_professionnels', 'barres_couchees');
});

test('le propriétaire du cabinet compose la page des graphiques d’un client, la retrouve après le rechargement, puis compose celle du panneau d’un magasin du client', function () {
    $societe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->sole();

    $onglet = $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid.'/graphiques')->attendre()->verifier();
    composerLaGrille($onglet, 3, 'meilleurs_produits', 'barres');

    $onglet->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier();
    composerLaGrille($onglet, 3, 'encaissements_par_mode', 'camembert');
});

test('le collaborateur à qui le client est confié compose la page des graphiques du client, la retrouve après le rechargement, puis compose celle du panneau d’un magasin du client', function () {
    $societe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->sole();

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid.'/graphiques')->attendre()->verifier();
    composerLaGrille($onglet, 3, 'ca_par_an', 'lignes');

    $onglet->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier();
    composerLaGrille($onglet, 3, 'avoirs_par_mois', 'aires');
});

test('l’expert-comptable déclaré compose « Tableau de bord de la société », la retrouve après le rechargement, puis compose la grille du panneau de son magasin', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $this->remplirLeMagasin($magasin, $this->membre($magasin, RoleMagasin::Responsable), $this->membre($magasin, RoleMagasin::Vendeur));
    $marc = $this->expertComptable($magasin);

    $onglet = $this->seConnecter($marc)->aller('/app/'.$magasin->uuid.'/tableau-de-bord-de-la-societe')->attendre()->verifier();
    composerLaGrille($onglet, 3, 'ventes_par_taux', 'barres');

    $onglet->aller('/app/'.$magasin->uuid)->attendre()->verifier();
    composerLaGrille($onglet, 3, 'meilleurs_produits', 'anneau');
});

test('une observatrice ne voit ni les chiffres clés ni les graphiques dans le panneau de son magasin', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->observateur)
        ->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier()
        ->voir('Bonjour')
        ->nePasVoir('CA HT du mois')
        ->nePasVoir('Tableau de bord de la société')
        ->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelector("[data-graphique]") === null && document.querySelector(\'select[data-filtre="ajouter"]\') === null)', 'un graphique paraît à l’observatrice');
});

/**
 * Les défauts de l'en-tête de chaque carte affichée, à la largeur de la
 * fenêtre (constat de l'éditeur du 15/11/2026) : le choix du type,
 * « Monter », « Descendre » et la croix n'ont pas le même haut (à 2 px
 * près), un élément de l'en-tête dépasse la croix à droite, le titre
 * chevauche une commande, la carte déborde, ou la page défile de côté.
 *
 * @return list<string>
 */
function defautsDesEnTetes(Navigation $onglet): array
{
    return $onglet->page()->evaluate(<<<'JS'
        () => {
            const defauts = [];
            const croise = (a, b) => a.left < b.right - 0.5 && b.left < a.right - 0.5 && a.top < b.bottom - 0.5 && b.top < a.bottom - 0.5;

            for (const carte of document.querySelectorAll('[data-graphique]')) {
                const nom = carte.dataset.graphique;
                const tete = carte.querySelector('[data-tete]');
                const titre = carte.querySelector('[data-titre]').getBoundingClientRect();
                const commandes = {
                    type: carte.querySelector('[data-geste="type"]').closest('.fi-input-wrp').getBoundingClientRect(),
                    monter: carte.querySelector('[data-geste="monter"]').getBoundingClientRect(),
                    descendre: carte.querySelector('[data-geste="descendre"]').getBoundingClientRect(),
                    croix: carte.querySelector('[data-geste="retirer"]').getBoundingClientRect(),
                };
                const hauts = Object.values(commandes).map((boite) => boite.top);

                if (Math.max(...hauts) - Math.min(...hauts) > 2) {
                    defauts.push(nom + ' : hauts des commandes ' + hauts.map((haut) => Math.round(haut)).join(', '));
                }

                for (const element of tete.querySelectorAll('*')) {
                    const boite = element.getBoundingClientRect();

                    if (boite.width > 0 && boite.right > commandes.croix.right + 0.5) {
                        defauts.push(nom + ' : ' + element.tagName.toLowerCase() + ' dépasse la croix à droite');
                    }
                }

                for (const [commande, boite] of Object.entries(commandes)) {
                    if (croise(titre, boite)) {
                        defauts.push(nom + ' : le titre chevauche ' + commande);
                    }
                }

                if (carte.scrollWidth > carte.clientWidth + 1 || tete.getBoundingClientRect().right > carte.getBoundingClientRect().right + 0.5) {
                    defauts.push(nom + ' : la carte déborde');
                }
            }

            if (document.documentElement.scrollWidth > window.innerWidth + 1) {
                defauts.push('la page défile de côté (' + document.documentElement.scrollWidth + ' px pour ' + window.innerWidth + ')');
            }

            return defauts;
        }
        JS);
}

test('l’en-tête de chacun des dix-neuf graphiques garde le type, « Monter », « Descendre » et la croix sur sa première ligne, la croix à droite, à 1 280, 1 024 et 390 px de large', function () {
    $societe = $this->societeComplete();
    app(PreferencesDesGraphiques::class)->enregistrer($societe->proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord(
        array_map(fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique), Graphique::cases()),
    ));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === '.count(Graphique::cases()), 'les dix-neuf graphiques ne sont pas là');

    foreach ([1280, 1024, 390] as $largeur) {
        $onglet->page()->setViewportSize($largeur, 900);
        $onglet->attendreQue('window.innerWidth === '.$largeur, "la fenêtre n’est pas à {$largeur} px");

        expect(defautsDesEnTetes($onglet))->toBe([], "à {$largeur} px");
    }
});

/*
 * Quand la fenêtre rétrécit, Chart.js ne redimensionne un graphique qu'à
 * l'image suivante : la grille, rétrécie et mesurée dans le même geste,
 * garde chaque canevas dans sa carte, sans aucun débordement passager
 * (CI rouge de d15af36, à 1 280 px : les dix-huit cartes débordaient
 * quand la mesure tombait avant le redimensionnement). Un dessin par
 * carte : un canevas pour chacune des dix-huit cartes de Chart.js, le
 * `svg` de l'écart des prix d'achat pour la dix-neuvième, qui suit sa
 * carte sans attendre.
 */
test('aucune carte ne déborde pendant que Chart.js redimensionne son graphique, la grille rétrécie gardant chaque dessin dans sa carte', function () {
    $societe = $this->societeComplete();
    app(PreferencesDesGraphiques::class)->enregistrer($societe->proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord(
        array_map(fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique), Graphique::cases()),
    ));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique] canvas").length === '.(count(Graphique::cases()) - 1).' && '.carteDessinee(Graphique::EcartDesPrixDAchat->value), 'les dix-neuf graphiques ne sont pas dessinés (dix-huit canevas et le dessin de l’écart des prix d’achat)');

    $debordent = $onglet->page()->evaluate(<<<'JS'
        () => {
            const grille = document.querySelector('.piliqi-graphiques__grille');
            grille.style.width = Math.round(grille.clientWidth * 0.7) + 'px';
            const debordent = [...document.querySelectorAll('[data-graphique]')]
                .filter((carte) => carte.scrollWidth > carte.clientWidth + 1)
                .map((carte) => carte.dataset.graphique);
            grille.style.width = '';

            return debordent;
        }
        JS);

    expect($debordent)->toBe([]);
});

test('le menu « Période » d’une société dont l’exercice finit en décembre n’a pas de choix « Exercice » et dit « (exercice en cours) » ; celui d’une société dont l’exercice finit en juin a les cinq choix', function () {
    $decembre = $this->societeComplete();
    $periodes = '() => Array.from(document.querySelectorAll(\'select[data-filtre="periode"] option\')).map((option) => option.textContent.trim())';

    $onglet = $this->seConnecter($decembre->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelector(\'select[data-filtre="periode"]\') !== null', 'le menu « Période » n’est pas là');
    expect($onglet->page()->evaluate($periodes))->toBe(['12 derniers mois', 'Année 2026 (exercice en cours)', 'Année 2025 (exercice précédent)']);

    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $proprietaire = $this->proprietaireDe($magasin);
    app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $magasin->societe()->firstOrFail(), ['mois_fin_exercice' => 6]);
    $this->choisirSonMotDePasse($proprietaire);
    app(TenantContext::class)->clear();
    $this->travelTo(self::instantDuTest());

    $juin = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelector(\'select[data-filtre="periode"]\') !== null', 'le menu « Période » de la société qui clôture en juin n’est pas là');
    expect($juin->page()->evaluate($periodes))->toBe(['12 derniers mois', 'Année 2026', 'Année 2025', 'Exercice en cours (juil. 2025 à juin 2026)', 'Exercice précédent (juil. 2024 à juin 2025)']);
});

test('après l’ajout d’un graphique, le menu « Ajouter un graphique » revient sur « — Choisir — » sans proposer ce graphique, et rechoisir « — Choisir — » ne fait rien, sans message', function () {
    $societe = $this->societeComplete();
    $menu = 'document.querySelector(\'select[data-filtre="ajouter"]\')';
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre graphiques par défaut ne sont pas là');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('marge_par_mois'), 'l’ajout de la marge')
        ->attendreQue('document.querySelector(\'[data-graphique="marge_par_mois"]\') !== null', 'la marge n’est pas ajoutée')
        ->attendreQue($menu.'.value === "" && '.$menu.'.selectedOptions[0].textContent.trim() === "— Choisir —"', 'le menu ne revient pas sur « — Choisir — »')
        ->attendreQue($menu.'.querySelector(\'option[value="marge_par_mois"]\') === null', 'le menu propose encore la marge, déjà affichée');
    $reglages = PreferencesDuTableauDeBord::query()->withoutGlobalScopes()->where('user_id', $societe->proprietaire->id)->sole()->reglages;

    $onglet->geste(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption(''), 'le choix de « — Choisir — »')
        ->attendreQue($menu.'.value === ""', 'le menu ne reste pas sur « — Choisir — »')
        ->verifier()
        ->nePasVoir('Ce choix n\'existe pas dans le tableau de bord')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 5', 'les cinq cartes ne restent pas affichées');
    expect(PreferencesDuTableauDeBord::query()->withoutGlobalScopes()->where('user_id', $societe->proprietaire->id)->sole()->reglages)->toBe($reglages);
});
