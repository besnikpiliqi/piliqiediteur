<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * Les chiffres clés et les graphiques du tableau de bord dans le navigateur
 * (décision de l'éditeur du 14/11/2026, modèle « G1 + chiffres de G2 »,
 * lot GRAPH) : chaque carte se dessine avec le Chart.js de Filament, et
 * chaque geste (ajouter, changer le type, retirer) se retient en base,
 * retrouvé après le rechargement de la page. La société de test a deux
 * magasins, des ventes, des achats et un avoir (voir
 * TestCaseDuNavigateur::remplirLeMagasin()).
 */

/**
 * La carte de ce graphique est-elle dessinée : son canevas a des pixels
 * peints par Chart.js ?
 */
function carteDessinee(string $graphique): string
{
    return '(() => { const c = document.querySelector(\'[data-graphique="'.$graphique.'"] canvas\'); if (! c || c.width === 0) return false; const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data; for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) return true; } return false; })()';
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
