<?php

use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Models\Magasin;
use Tests\Browser\Support\Navigation;

/*
 * Le barème des prix de vente s'écrit au centime (relecture du lot CALC de
 * la série 8) : un bénéfice saisi au millième est refusé sous son champ,
 * jamais arrondi en silence, rien ne s'enregistre et l'essai ne propose
 * aucun prix ; écrit au centime, il s'essaie et s'enregistre. Le barème
 * d'exemple a pour premier palier les achats de moins de 2 € : 1 € d'achat
 * plus 0,13 € de bénéfice, à 20 %, font 1,36 € TTC. Chaque rôle qui règle
 * le barème le vit de même ; la société de test a tous ses rôles (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Dans cet onglet connecté, le barème de ce magasin s'active avec un
 * bénéfice de 0,125 € au premier palier, refusé, puis de 0,13 €, essayé et
 * enregistré. Un palier ne se renvoie pas au serveur à chaque frappe :
 * l'essai se refait quand son prix d'achat change.
 */
function reglerLeBeneficeAuCentime(Navigation $onglet, Magasin $magasin): void
{
    $onglet->aller('/app/'.$magasin->uuid.'/parametres-prix-de-vente')
        ->cliquer('[id="data.actif"]')
        ->remplirLaLigne('paliers', 0, 'benefice', '0.125')
        ->remplir('Prix d\'achat HT', '1')
        ->soumettre('Enregistrer')
        ->voir(SaisieDecimale::REFUS_MONTANT_TROP_PRECIS)
        ->nePasVoir('bénéfice réel');
    expect($magasin->fresh()->prix_vente_automatique)->toBeFalse();

    $onglet->remplirLaLigne('paliers', 0, 'benefice', '0.13')
        ->remplir('Prix d\'achat HT', '1.00')
        ->voir('1,36 € TTC')
        ->soumettre('Enregistrer')
        ->voir('Barème de prix de vente enregistré');
    $magasin->refresh();
    expect($magasin->prix_vente_automatique)->toBeTrue()
        ->and($magasin->bareme_prix_vente->paliers[0])->toBe(['jusqu_a' => 200, 'benefice' => 13]);
}

test('le propriétaire voit refusé un bénéfice du barème au millième, jamais arrondi, puis l’enregistre au centime', function () {
    $societe = $this->societeComplete();

    reglerLeBeneficeAuCentime($this->seConnecter($societe->proprietaire), $societe->magasin);
});

test('le responsable voit refusé un bénéfice du barème au millième, jamais arrondi, puis l’enregistre au centime', function () {
    $societe = $this->societeComplete();

    reglerLeBeneficeAuCentime($this->seConnecter($societe->responsable), $societe->magasin);
});

test('l’administrateur voit refusé un bénéfice du barème au millième, jamais arrondi, puis l’enregistre au centime', function () {
    $societe = $this->societeComplete();

    reglerLeBeneficeAuCentime($this->seConnecter($societe->administrateur), $societe->magasin);
});
