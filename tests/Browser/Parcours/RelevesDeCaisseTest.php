<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * La colonne « Encaissé » des relevés de caisse dans le navigateur
 * (demandes de l'éditeur du 15/11/2026, choix A) : une ligne par mode, en
 * abrégé (CB, ESP, CHQ, VIR, PRLV), dans l'ordre fixe des relevés, puis
 * « Total » en gras quand il y a plusieurs modes, chaque montant sur une
 * seule ligne avec son « € ». Le vendeur de la Boutique du Port vend ce
 * jour une bougie de 18,00 €, réglée par 8,00 € en espèces et 10,00 € par
 * carte bancaire, et ferme sa caisse par sa fenêtre « Fermer ma caisse » ;
 * son relevé précédent compte le ticket de 18,00 € de la veille, payé en
 * espèces, le ticket de 12,00 € payé par carte et le virement de 20,00 €
 * reçus avant (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */

/**
 * Le vendeur vend la bougie à la caisse, en deux modes.
 */
function vendreUneBougieEnDeuxModes(SocieteDeTest $societe): void
{
    app(TenantContext::class)->runAs($societe->magasin, fn () => app(EnregistrerVenteCaisseAction::class)->handle(
        $societe->vendeur,
        Client::query()->where('est_comptoir', true)->sole(),
        [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]],
        new ReglementComptant([new PartDeReglement(ModePaiement::CarteBancaire, 1000), new PartDeReglement(ModePaiement::Especes, 800)]),
    ));
}

/**
 * Les cellules « Encaissé » de la page ouverte, ou de ce qu'en désigne ce
 * sélecteur, chacune en ses lignes : le texte et le nom entier
 * (infobulle) de chaque mode, son montant, le nombre de lignes où s'écrit
 * ce montant, et la graisse de la ligne.
 *
 * @return list<list<array{mode: string, titre: string|null, montant: string, lignes: int, gras: bool}>>
 */
function cellulesEncaisse(Navigation $onglet, string $dans = 'body'): array
{
    return $onglet->page()->evaluate(<<<'JS'
        (dans) => Array.from(document.querySelector(dans).querySelectorAll('.piliqi-encaisse')).filter((cellule) => cellule.offsetParent !== null).map((cellule) => {
            const enfants = Array.from(cellule.children);
            const lignes = [];

            for (let rang = 0; rang < enfants.length; rang += 2) {
                const mode = enfants[rang];
                const montant = enfants[rang + 1];

                lignes.push({
                    mode: mode.textContent.trim(),
                    titre: mode.getAttribute('title'),
                    montant: montant.textContent.trim(),
                    lignes: montant.getClientRects().length,
                    gras: Number(getComputedStyle(montant).fontWeight) >= 600,
                });
            }

            return lignes;
        })
        JS, $dans);
}

/**
 * Les deux relevés du vendeur : celui de la bougie (espèces puis carte
 * bancaire, dans l'ordre fixe, quel que soit l'ordre des parts) et le
 * précédent (espèces, carte bancaire, virement), chacun avec son total en
 * gras, chaque montant sur une seule ligne.
 */
const RELEVE_DE_LA_BOUGIE = [
    ['mode' => 'ESP', 'titre' => 'Espèces', 'montant' => '8,00 €', 'lignes' => 1, 'gras' => false],
    ['mode' => 'CB', 'titre' => 'Carte bancaire', 'montant' => '10,00 €', 'lignes' => 1, 'gras' => false],
    ['mode' => 'Total', 'titre' => null, 'montant' => '18,00 €', 'lignes' => 1, 'gras' => true],
];

const RELEVE_PRECEDENT = [
    ['mode' => 'ESP', 'titre' => 'Espèces', 'montant' => '18,00 €', 'lignes' => 1, 'gras' => false],
    ['mode' => 'CB', 'titre' => 'Carte bancaire', 'montant' => '12,00 €', 'lignes' => 1, 'gras' => false],
    ['mode' => 'VIR', 'titre' => 'Virement', 'montant' => '20,00 €', 'lignes' => 1, 'gras' => false],
    ['mode' => 'Total', 'titre' => null, 'montant' => '50,00 €', 'lignes' => 1, 'gras' => true],
];

test('la fenêtre « Fermer ma caisse » du vendeur, « Les caisses » du responsable et « Les caisses » de la société écrivent l’encaissé une ligne par mode abrégé, puis le total en gras, à 1 280 et à 1 024 px', function () {
    $societe = $this->societeComplete();
    vendreUneBougieEnDeuxModes($societe);

    $vendeur = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid)->attendre()->verifier()
        ->cliquerSurLeLien('Ma caisse')
        ->cliquerSur('Fermer ma caisse');
    expect(cellulesEncaisse($vendeur->voir('Encaissé :'), Navigation::FENETRE))->toBe([RELEVE_DE_LA_BOUGIE]);
    $vendeur->soumettre('Confirmer')
        ->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null', 'la caisse ne se ferme pas');

    foreach ([
        'le responsable du magasin' => [$this->seConnecter($societe->responsable), '/app/'.$societe->magasin->uuid.'/cloture-caisse-personnelles'],
        'le propriétaire, dans l’espace de la société' => [$this->seConnecter($societe->proprietaire, panneau: 'societe'), '/societe/'.$societe->societe->uuid.'/caisses'],
    ] as $qui => [$onglet, $adresse]) {
        foreach ([1280, 1024] as $largeur) {
            $onglet->page()->setViewportSize($largeur, 900);
            $onglet->aller($adresse)->attendre()->verifier()->voir('Encaissé');

            expect(cellulesEncaisse($onglet))->toContain(RELEVE_DE_LA_BOUGIE, RELEVE_PRECEDENT);
        }

        $onglet->nePasVoir('Carte bancaire :')->nePasVoir('Par mode de paiement');
    }
});
