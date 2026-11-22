<?php

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\ChargesDeChaqueEtat;
use Tests\Browser\Support\ContrasteDesPages;
use Tests\Browser\Support\Navigation;

/*
 * Chaque écran, chaque fenêtre, chaque menu déroulant ouvert, chaque
 * carte et chaque pastille se lit aussi bien en mode clair qu'en mode
 * sombre (décision de l'éditeur du 15/11/2026, lot DATEOP) : pour chaque
 * panneau et un compte qui en voit le plus, chaque page que ses liens
 * atteignent, une fiche par sorte d'adresse, se lit en clair puis en
 * sombre, et aucun texte visible ni aucune option d'un menu déroulant n'y
 * est sous le contraste AA (voir ContrasteDesPages et contraste.js).
 */

beforeEach(function () {
    Storage::fake(PiecesDesCharges::DISQUE);
});

/**
 * Lit chaque page de ce panneau dans les deux modes et échoue en nommant
 * chaque texte sous le seuil ; rend les pages lues.
 *
 * @param  list<string>  $prefixes
 * @return list<string>
 */
function lireEnClairEtEnSombre(Navigation $onglet, string $depart, array $prefixes): array
{
    $contraste = new ContrasteDesPages($onglet);
    $pages = $contraste->lire($depart, $prefixes);

    expect($contraste->defauts())->toBe([], 'Textes sous le contraste AA :'.PHP_EOL.implode(PHP_EOL, $contraste->defauts()));

    return $pages;
}

test('chaque page de l’espace de la société se lit en clair et en sombre, pour son propriétaire', function () {
    $societe = $this->societeComplete();
    ChargesDeChaqueEtat::deposer($societe);
    $espace = '/societe/'.$societe->societe->uuid;

    $pages = lireEnClairEtEnSombre($this->seConnecter($societe->proprietaire, panneau: 'societe'), $espace, [$espace]);

    expect($pages)->toContain($espace.'/charges', $espace.'/telecharger-les-documents', $espace.'/cabinet-comptable');
});

test('chaque page du magasin se lit en clair et en sombre, pour son responsable et pour le propriétaire', function (string $role) {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $compte = $societe->{$role};

    $pages = lireEnClairEtEnSombre($this->seConnecter($compte), $magasin, [$magasin]);

    expect($pages)->toContain($magasin.'/factures');
})->with(['responsable' => 'responsable', 'propriétaire' => 'proprietaire']);

test('la page « Prix de vente » se lit en clair et en sombre avec l’arrondi coché, ses exemples et son essai, puis décoché', function () {
    $societe = $this->societeComplete();
    $societe->magasin->update([
        'prix_vente_automatique' => true,
        'bareme_prix_vente' => new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 6000, ArrondiDuPrixDeVente::EuroLePlusProche),
    ]);
    $onglet = $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/parametres-prix-de-vente')
        ->remplir('Prix d\'achat HT', '0.47')
        ->voir('Le prix exact est 12,564 €')
        ->voir('Exemples de la formule choisie');
    $contraste = new ContrasteDesPages($onglet);

    expect([$contraste->defautsDeLaPage('clair'), $contraste->defautsDeLaPage('sombre')])->toBe([[], []]);

    $onglet->cliquer('[id="data.arrondi"]')->voir('Case décochée : aucun arrondi');

    expect([$contraste->defautsDeLaPage('clair'), $contraste->defautsDeLaPage('sombre')])->toBe([[], []]);
});

test('chaque page de l’espace du cabinet se lit en clair et en sombre, pour son propriétaire', function () {
    $societe = $this->societeComplete();
    ChargesDeChaqueEtat::deposer($societe);
    $espace = '/cabinet/'.$societe->cabinet->uuid;

    $pages = lireEnClairEtEnSombre($this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet'), $espace, [$espace]);

    expect($pages)->toContain($espace.'/clients');
});

test('chaque page de l’expert-comptable déclaré se lit en clair et en sombre', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $marc = $this->expertComptable($magasin);
    $this->travelTo(self::instantDuTest());
    $onglet = $this->seConnecter($marc);
    $depart = $onglet->chemin();

    $pages = lireEnClairEtEnSombre($onglet, $depart, ['/app/']);

    expect($pages)->not->toBe([]);
    expect(User::query()->whereKey($marc->getKey())->exists())->toBeTrue();
});

test('le contrôle relève le menu déroulant nu d’avant, illisible en sombre, et un texte gris trop pâle, et rien sur la page corrigée', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')->aller('/societe/'.$societe->societe->uuid.'/charges');
    $contraste = new ContrasteDesPages($onglet);

    expect([$contraste->defautsDeLaPage('clair'), $contraste->defautsDeLaPage('sombre')])->toBe([[], []]);

    $onglet->page()->evaluate(<<<'JS'
        () => {
            const temoin = document.createElement('div');
            temoin.innerHTML = '<select class="mt-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"><option>À valider</option></select><p class="text-gray-400">Texte trop pâle</p>';
            document.querySelector('[data-page-des-charges]').prepend(temoin);
        }
        JS);

    expect(implode(PHP_EOL, $contraste->defautsDeLaPage('sombre')))->toContain('option de select', '« À valider »')
        ->and(implode(PHP_EOL, $contraste->defautsDeLaPage('clair')))->toContain('« Texte trop pâle »');
});
