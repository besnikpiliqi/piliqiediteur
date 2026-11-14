<?php

use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * Un nom de magasin piégé (constat C37 des audits du 07/11/2026) : la
 * société renomme l'un de ses deux magasins réels en un fragment de HTML,
 * accepté comme tout nom de 150 caractères au plus ; dans le menu des
 * magasins, survolé puis ouvert, il s'affiche en texte et rien ne
 * s'exécute, pour le propriétaire comme pour le collaborateur du cabinet
 * lié à qui ce client est confié.
 */

const NOM_PIEGE = '<img src=x onerror="window.__xss=1">';

/**
 * Le second magasin de la société, renommé par son propriétaire.
 */
function renommerEnNomPiege(User $proprietaire, Magasin $magasin): void
{
    app(ModifierEtablissementAction::class)->handle($proprietaire, $magasin, ['nom' => NOM_PIEGE]);
    expect($magasin->fresh()->nom)->toBe(NOM_PIEGE);
}

/**
 * Survole puis ouvre le menu des magasins, qui montre le nom piégé en
 * texte, sans que rien ne se soit exécuté.
 */
function ouvrirLeMenuDesMagasins(Navigation $onglet): void
{
    $onglet->geste(fn () => $onglet->element('.fi-tenant-menu-trigger')->hover(), 'le survol du menu des magasins')
        ->cliquer('.fi-tenant-menu-trigger')
        ->voir(NOM_PIEGE);

    expect($onglet->page()->evaluate('() => typeof window.__xss'))->toBe('undefined')
        ->and($onglet->page()->evaluate('() => document.querySelectorAll(".fi-tenant-menu img[src=\'x\']").length'))->toBe(0);
}

test('le propriétaire voit un nom de magasin piégé en texte dans le menu des magasins, sans qu’il s’exécute', function () {
    $societe = $this->societeComplete();
    renommerEnNomPiege($societe->proprietaire, $societe->autreMagasin);

    ouvrirLeMenuDesMagasins($this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid));
});

test('le collaborateur du cabinet lié voit un nom de magasin piégé de son client en texte, sans qu’il s’exécute', function () {
    $societe = $this->societeComplete();
    renommerEnNomPiege($societe->proprietaire, $societe->autreMagasin);

    ouvrirLeMenuDesMagasins($this->seConnecter($societe->collaborateur)->aller('/app/'.$societe->magasin->uuid));
});
