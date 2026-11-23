<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\CarteDeLEcartDesPrixDAchat as Carte;
use Tests\Browser\Support\Navigation;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/*
 * La carte « Écart des prix d'achat » du tableau de bord dans le navigateur
 * (décision de l'éditeur du 21/11/2026, maquette validée, D76) : pour
 * chaque rôle et chaque écran qui voit les graphiques, elle s'ajoute par le
 * menu « Ajouter un graphique », puis se lit comme la maquette (texte,
 * classes, attributs et dessin, comparés au relevé de la maquette) pour les
 * mois 0, 2, 3, 8 et 11 de l'affichage par mois, puis pour les douze mois ;
 * le clic, Entrée et Espace choisissent un mois, sans aucun appel au
 * serveur ni à la base (décision de l'éditeur du 23/11/2026 : le mois est
 * déjà dans la page, voir le test qui le prouve douze fois) ; les liens
 * « Voir… » ouvrent ce que la page contient ; l'affichage se retient, le mois
 * choisi non. Les achats de la maquette (122 achats de 12 produits, par les vraies
 * actions) sont semés par le test dans un seul magasin, sans toucher à la
 * société de test : au 13/11/2026, la période « 12 derniers mois » est celle
 * de la maquette (déc. 2025 à nov. 2026).
 */

const CARTE_DE_L_ECART = Carte::CARTE;

test('le propriétaire ajoute la carte dans l’espace de la société, la lit comme la maquette, choisit un mois au clic, à Entrée et à Espace, ouvre les liens « Voir… », et retrouve l’affichage, pas le mois, après le rechargement', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="'.CARTE_DE_L_ECART.'"]';

    Carte::ajouter($onglet, 4);
    // Les achats de la maquette sont ceux de la Boutique de la Gare : la carte compte ce seul magasin.
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');
    Carte::verifier($onglet, 'espace de la société');

    // Le clavier : Entrée puis Espace sur une colonne qui a le focus, sans appeler le serveur.
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(4))->press('Enter'), 'Entrée sur la colonne du mois 4');
    Carte::attendreLeMoisChoisi($onglet, 4);
    Carte::verifierLaMaquette($onglet, 'avant', 4, 'Entrée');
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(6))->press('Space'), 'Espace sur la colonne du mois 6');
    Carte::attendreLeMoisChoisi($onglet, 6);
    Carte::verifierLaMaquette($onglet, 'avant', 6, 'Espace');
    $onglet->attendreQue('Array.from(document.querySelectorAll(\''.$carte.' svg > g\')).indexOf(document.activeElement) === 6', 'la colonne du mois 6 n’a pas gardé le focus à Espace');

    // Les liens « Voir… » : le texte, aria-expanded et le bloc suivent l'ouverture, sans appeler le serveur.
    // La page écrit les deux affichages : chaque élément de la carte se lit dans celui qu'elle montre.
    $lien = fn (string $nom): string => $carte.' [data-ecart="'.$nom.'"]:visible';
    $ouvert = fn (string $bloc): string => Carte::visible($bloc).' !== undefined';
    $texte = fn (string $nom): string => Carte::visible($nom).'.textContent.replace(/\s+/g, " ").trim()';
    $aria = fn (string $nom): string => Carte::visible($nom).'.getAttribute("aria-expanded")';

    $onglet->attendreQue('!('.$ouvert('chiffres').') && !('.$ouvert('calcul').')', 'les tableaux ne sont pas fermés au départ');
    $onglet->geste(fn () => $onglet->element($lien('voir-chiffres'))->click(), 'l’ouverture des chiffres')
        ->attendreQue($ouvert('chiffres').' && '.$texte('voir-chiffres').' === "Masquer les chiffres" && '.$aria('voir-chiffres').' === "true"', 'les chiffres ne s’ouvrent pas comme la maquette le dit');
    $onglet->geste(fn () => $onglet->element($lien('voir-calcul'))->click(), 'l’ouverture du calcul')
        ->attendreQue($ouvert('calcul').' && '.$texte('voir-calcul').' === "Masquer le calcul du mois choisi" && '.$aria('voir-calcul').' === "true"', 'le calcul du mois ne s’ouvre pas comme la maquette le dit');

    // Un autre mois, puis les douze mois : les liens gardent leur état, le tableau des 12 mois se referme.
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(2))->click(), 'le clic sur le mois 2, tableaux ouverts');
    Carte::attendreLeMoisChoisi($onglet, 2);
    $onglet->attendreQue($ouvert('chiffres').' && '.$ouvert('calcul').' && '.$texte('voir-calcul').' === "Masquer le calcul du mois choisi"', 'un autre mois referme un tableau');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le passage aux douze mois, tableaux ouverts')
        ->attendreQue('!('.$ouvert('voir-chiffres').') && !('.$ouvert('chiffres').')', 'le lien et le tableau des 12 mois ne disparaissent pas')
        ->attendreQue($ouvert('calcul').' && '.$texte('voir-calcul').' === "Masquer le calcul de la période"', 'le calcul de la période n’est pas ouvert, avec son lien « Masquer le calcul de la période »');
    $onglet->geste(fn () => $onglet->element($lien('voir-calcul'))->click(), 'la fermeture du calcul de la période')
        ->attendreQue('!('.$ouvert('calcul').') && '.$texte('voir-calcul').' === "Voir le calcul de la période, achat par achat"', 'le calcul de la période ne se ferme pas');

    // Retour par mois : le mois choisi (2) est gardé et le tableau des 12 mois, refermé au passage aux 12 mois, l'est resté.
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('avant'), 'le retour par mois');
    Carte::attendreLeMoisChoisi($onglet, 2);
    $onglet->attendreQue('!('.$ouvert('chiffres').') && '.$aria('voir-chiffres').' === "false" && '.$texte('voir-chiffres').' === "Voir les chiffres"', 'le tableau des 12 mois ne reste pas refermé');

    // L'affichage choisi revient après le rechargement ; le mois choisi, non.
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(3))->click(), 'le clic sur le mois 3');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le choix des douze mois avant le rechargement');
    $onglet->aller($onglet->chemin())->attendre()->verifier()
        ->attendreQue('document.querySelector(\''.$carte.' select[data-geste="affichage"]\') !== null && document.querySelector(\''.$carte.' select[data-geste="affichage"]\').value === "debut"', 'l’affichage des 12 mois n’est pas retenu');
    Carte::verifierLaMaquette($onglet, 'debut', 11, 'après le rechargement');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('avant'), 'le retour par mois après le rechargement');
    Carte::attendreLeMoisChoisi($onglet, 11);
    Carte::verifierLaMaquette($onglet, 'avant', 11, 'le mois choisi revient au dernier après le rechargement');
});

test('cliquer sur chacun des douze mois, puis Entrée et Espace, change le mois affiché sans aucune requête : ni la page, ni le serveur, ni la base ne sont appelés, le mois garde le focus et reste annoncé, et la période ou le magasin le remettent au départ', function () {
    // La cloche regarde le serveur d'elle-même à son rythme : une heure, pour qu'aucune requête que le test n'a pas faite ne parte pendant les gestes.
    config(['piliqi.intervalle_des_notifications_en_secondes' => 3600]);
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="'.CARTE_DE_L_ECART.'"]';
    $calcul = EcartDesPrixDAchat::calculer(LectureDesFixtures::verite()['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());
    $focus = fn (): string => 'Array.from(document.querySelectorAll(\''.$carte.' svg > g\')).indexOf(document.activeElement)';
    $ordre = [5, 0, 11, 3, 8, 1, 10, 2, 9, 4, 7, 6];

    Carte::ajouter($onglet, 4);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');

    // Le contrôle du contrôle : un geste qui appelle le serveur, lui, est vu par les trois compteurs.
    $appel = $onglet->activiteDuGeste(fn () => $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le passage aux douze mois'));
    expect($appel['lancees'])->toBe(1)
        ->and($appel['reponses'])->toHaveCount(1)
        ->and($appel['sql'])->not->toBe([]);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('avant'), 'le retour à l’affichage par mois');

    // Le calcul achat par achat de chaque mois est déjà dans la page : « Voir le calcul… » s'ouvre sans serveur non plus.
    $onglet->gesteSansServeur(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'l’ouverture du calcul');
    Carte::verifierLesTextes($onglet, $calcul, 11, true, 'au départ');

    // Les douze mois, dans un ordre qui n'est pas celui du calendrier, au clic, le calcul ouvert : le texte change à chaque mois et c'est la lecture de ce mois.
    foreach ($ordre as $mois) {
        $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne($mois))->click(), "le clic sur la colonne du mois {$mois}");
        Carte::attendreLeMoisChoisi($onglet, $mois);
        Carte::verifierLesTextes($onglet, $calcul, $mois, true, 'au clic');
        Carte::verifierLeMois($onglet, $mois, 'au clic, sans serveur');
        $onglet->attendreQue($focus().' === '.$mois, "la colonne du mois {$mois} n’a pas le focus après le clic");
    }

    // Entrée, puis Espace, sur une colonne qui a le focus, le calcul refermé : même chose, la carte entière est la maquette, et Espace ne fait pas défiler la page.
    $onglet->gesteSansServeur(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'la fermeture du calcul');

    foreach ($ordre as $rang => $mois) {
        $touche = $rang % 2 === 0 ? 'Enter' : 'Space';
        $onglet->geste(fn () => $onglet->element(Carte::colonne($mois))->focus(), "le focus sur la colonne du mois {$mois}");
        $onglet->page()->evaluate('() => window.scrollTo(0, 120)');
        $defilement = $onglet->page()->evaluate('() => window.scrollY');

        $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne($mois))->press($touche), "{$touche} sur la colonne du mois {$mois}");
        Carte::attendreLeMoisChoisi($onglet, $mois);
        Carte::verifierLesTextes($onglet, $calcul, $mois, false, $touche);
        Carte::verifierLaMaquette($onglet, 'avant', $mois, $touche.', sans serveur');
        $onglet->attendreQue($focus().' === '.$mois, "la colonne du mois {$mois} n’a pas gardé le focus après {$touche}");
        expect($onglet->page()->evaluate('() => window.scrollY'))->toBe($defilement, "{$touche} sur la colonne du mois {$mois} fait défiler la page");
    }

    $onglet->gesteSansServeur(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'la réouverture du calcul');

    // Un geste qui appelle le serveur garde le mois choisi : le calcul ouvert aussi.
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(2))->click(), 'le clic sur la colonne du mois 2');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le passage aux douze mois');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('avant'), 'le retour par mois');
    Carte::attendreLeMoisChoisi($onglet, 2);
    Carte::verifierLesTextes($onglet, $calcul, 2, true, 'après le menu « Afficher : »');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('input[data-filtre="comparer"]')->click(), 'la comparaison avec l’année précédente');
    Carte::attendreLeMoisChoisi($onglet, 2);
    Carte::verifierLesTextes($onglet, $calcul, 2, true, 'après la comparaison');

    // La période ou le magasin remettent le mois au dernier mois qui n'est pas à venir (CLAUDE.md, « Graphiques du tableau de bord »).
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="periode"]')->selectOption('annee_en_cours'), 'la période « année civile en cours »');
    Carte::attendreLeMoisChoisi($onglet, 10);
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(1))->click(), 'le clic sur la colonne du mois 1');
    Carte::attendreLeMoisChoisi($onglet, 1);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption('Tous mes magasins'), 'le choix de tous les magasins');
    Carte::attendreLeMoisChoisi($onglet, 10);
    $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(7))->click(), 'le clic sur la colonne du mois 7');
    Carte::attendreLeMoisChoisi($onglet, 7);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="periode"]')->selectOption('douze_derniers_mois'), 'le retour aux douze derniers mois');
    Carte::attendreLeMoisChoisi($onglet, 11);
    Carte::verifierLesTextes($onglet, $calcul, 11, true, 'au retour aux douze derniers mois');
});

test('le propriétaire ajoute la carte dans le panneau d’un magasin, la lit comme la maquette, sans nom de magasin ni filtre', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->autreMagasin->uuid)->attendre()->verifier();

    Carte::ajouter($onglet, 3);
    Carte::verifier($onglet, 'panneau d’un magasin');
    $onglet->attendreQue('document.querySelector(\'select[data-filtre="magasin"]\') === null', 'le panneau d’un magasin montre un filtre des magasins')
        ->attendreQue('!document.querySelector(\'[data-graphique="'.CARTE_DE_L_ECART.'"]\').textContent.includes("·")', 'le panneau d’un magasin nomme un magasin');
});

test('un administrateur à qui le propriétaire coche les graphiques voit la carte de son magasin comme la maquette', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $nina = $this->administrateur($societe->magasin, [$societe->autreMagasin->id], [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR], 'Nina Faure');
    app(TenantContext::class)->clear();
    $this->travelTo(self::aParis('2026-11-13 10:00:00'));

    $onglet = $this->seConnecter($nina, panneau: 'societe');

    Carte::ajouter($onglet, 3);
    Carte::verifier($onglet, 'administrateur');
});

test('un responsable à qui le propriétaire coche les graphiques voit la carte dans le panneau de son magasin comme la maquette', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $lea = $this->membre($societe->autreMagasin, RoleMagasin::Responsable, [PermissionRegistry::GRAPHIQUES_VOIR], ['name' => 'Léa Roux']);
    app(TenantContext::class)->clear();
    $this->travelTo(self::aParis('2026-11-13 10:00:00'));

    $onglet = $this->seConnecter($lea)->aller('/app/'.$societe->autreMagasin->uuid)->attendre()->verifier();

    Carte::ajouter($onglet, 3);
    Carte::verifier($onglet, 'responsable');
});

test('le propriétaire du cabinet et le collaborateur à qui le client est confié voient la carte sur la page des graphiques du client comme la maquette', function (string $compte) {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->sole();
    $this->travelTo(self::aParis('2026-11-13 10:00:00'));

    $onglet = $this->seConnecter($societe->{$compte}, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid.'/graphiques')->attendre()->verifier();

    Carte::ajouter($onglet, 3);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');
    Carte::verifier($onglet, 'page du client');
})->with(['propriétaire du cabinet' => 'proprietaireDuCabinet', 'collaborateur' => 'collaborateur']);

test('l’expert-comptable déclaré voit la carte sur « Tableau de bord de la société » comme la maquette', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $marc = $this->expertComptable($magasin);
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $magasin, self::responsableDeTest($magasin));

    $onglet = $this->seConnecter($marc)->aller('/app/'.$magasin->uuid.'/tableau-de-bord-de-la-societe')->attendre()->verifier();

    Carte::ajouter($onglet, 3);
    Carte::verifier($onglet, 'expert-comptable déclaré');
});

test('un vendeur, une observatrice, un responsable sans la case et une société de services n’ont ni la carte ni son menu', function () {
    $societe = $this->societeComplete();

    foreach ([$societe->vendeur, $societe->observateur, $societe->responsable] as $compte) {
        $this->seConnecter($compte)->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier()
            ->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelector("[data-graphique]") === null && document.querySelector(\'select[data-filtre="ajouter"]\') === null)', "{$compte->name} voit un graphique");
    }

    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Conseil Vasseur', 'type' => TypeDActivite::Services]));
    $proprietaire = $this->proprietaireDe($magasin);
    $this->choisirSonMotDePasse($proprietaire);
    app(TenantContext::class)->clear();
    $this->travelTo(self::instantDuTest());

    $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelector(\'select[data-filtre="ajouter"]\') !== null', 'le menu « Ajouter un graphique » n’est pas là')
        ->attendreQue('document.querySelector(\'select[data-filtre="ajouter"] option[value="'.CARTE_DE_L_ECART.'"]\') === null', 'une société de services se voit proposer l’écart des prix d’achat')
        ->attendreQue('document.querySelector(\'[data-graphique="'.CARTE_DE_L_ECART.'"]\') === null', 'une société de services voit la carte')
        ->nePasVoir('Écart des prix d’achat');
});

test('la carte d’une société sans aucun achat dit « Aucun achat ce mois-ci », et les liens « Voir… » et le menu y fonctionnent sans erreur', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="'.CARTE_DE_L_ECART.'"]';

    Carte::ajouter($onglet, 4);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare')
        ->attendreQue(Carte::visible('pastille').'.textContent.trim() === "Aucun achat ce mois-ci"', 'la carte ne dit pas « Aucun achat ce mois-ci »')
        ->attendreQue('!document.querySelector(\''.$carte.' svg\').outerHTML.includes("NaN")', 'le dessin d’une carte sans achat porte un « NaN »')
        ->attendreQue('document.querySelectorAll(\''.$carte.' svg > g\').length === 12', 'le dessin n’a pas ses douze colonnes');
    $onglet->geste(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'l’ouverture du calcul')
        ->attendreQue(Carte::visible('calcul-corps').'.textContent.includes("Aucun achat validé ce mois-là.")', 'le calcul ne dit pas qu’aucun achat n’est validé');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le passage aux douze mois')
        ->attendreQue(Carte::visible('pastille').'.textContent.trim() === "Prix inchangés"', 'les douze mois sans achat ne disent pas « Prix inchangés »');
});

/**
 * Ce que la carte ouverte déborde, à la largeur de la fenêtre : la page qui
 * défile de côté, la carte ou son dessin ou son menu qui sortent d'elle, un
 * tableau qui n'a pas son cadre défilant (un tableau large défile dans son
 * cadre, jamais dans la page).
 *
 * @return list<string>
 */
function defautsDeDebordementDeLaCarte(Navigation $onglet): array
{
    $defauts = $onglet->page()->evaluate(<<<'JS'
        () => {
            const defauts = [];
            const carte = document.querySelector('[data-graphique="ecart_des_prix_d_achat"]');
            const boite = carte.getBoundingClientRect();

            if (document.documentElement.scrollWidth > window.innerWidth + 1) {
                defauts.push('la page défile de côté (' + document.documentElement.scrollWidth + ' px pour ' + window.innerWidth + ')');
            }

            if (carte.scrollWidth > carte.clientWidth + 1) {
                defauts.push('la carte déborde (' + carte.scrollWidth + ' px pour ' + carte.clientWidth + ')');
            }

            for (const [nom, element] of [
                ['le menu « Afficher : »', carte.querySelector('[data-ecart="menu"]')],
                ['le dessin', carte.querySelector('[data-ecart="graphique"]')],
                ['les légendes', carte.querySelector('[data-ecart="legende-net"]')],
                ['les cartes de chiffres', carte.querySelector('[data-ecart="stat-base"]')],
            ]) {
                if (element !== null && element.getBoundingClientRect().right > boite.right + 0.5) {
                    defauts.push(nom + ' sort de la carte');
                }
            }

            for (const cadre of carte.querySelectorAll('table')) {
                const defiler = cadre.parentElement;
                const vue = defiler.getBoundingClientRect();

                if (vue.width > 0 && (vue.right > boite.right + 0.5 || getComputedStyle(defiler).overflowX !== 'auto')) {
                    defauts.push('un tableau ne défile pas dans son cadre');
                }
            }

            return defauts;
        }
        JS);

    return array_values(array_filter(is_array($defauts) ? $defauts : [], is_string(...)));
}

test('la carte, ses tableaux ouverts, ne déborde pas à 1 280, 1 024 et 390 px de large : un tableau large défile dans son cadre, jamais la page', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="'.CARTE_DE_L_ECART.'"]';

    Carte::ajouter($onglet, 4);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');
    $onglet->geste(fn () => $onglet->element($carte.' [data-ecart="voir-chiffres"]:visible')->click(), 'l’ouverture des chiffres');
    $onglet->geste(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'l’ouverture du calcul');

    foreach (['avant', 'debut'] as $affichage) {
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption($affichage), "l’affichage {$affichage}");

        foreach ([1280, 1024, 390] as $largeur) {
            $onglet->page()->setViewportSize($largeur, 900);
            $onglet->attendreQue('window.innerWidth === '.$largeur, "la fenêtre n’est pas à {$largeur} px");

            expect(defautsDeDebordementDeLaCarte($onglet))->toBe([], "affichage {$affichage}, à {$largeur} px");
        }
    }
});
