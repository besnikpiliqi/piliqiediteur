<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Services\ConstructeurLigneAchat;
use Tests\TestCase;

/**
 * L'aperçu des montants pendant la saisie d'une facture d'achat (voir
 * ConstructeurLigneAchat::apercu()) : une ligne qui n'est pas encore
 * complète ou valable est ignorée et les autres se calculent sans elle ;
 * une TVA écrite pour un taux sans ligne laisse la proposition de PILIQI ;
 * des lignes valables dont le total dépasse ce que le calcul accepte ne
 * donnent aucun aperçu.
 */
class ApercuDUnAchatTest extends TestCase
{
    public function test_une_ligne_incomplete_ou_hors_bornes_est_ignoree_et_les_autres_se_calculent_sans_elle(): void
    {
        $apercu = app(ConstructeurLigneAchat::class)->apercu([
            'valable' => ['prix_unitaire_ht' => '10.00', 'quantite' => 2, 'taux_tva' => '20', 'remise_pourcentage' => null],
            'pas un tableau' => 'ligne illisible',
            'prix négatif' => ['prix_unitaire_ht' => '-5.00', 'quantite' => 1, 'taux_tva' => '20'],
            'taux au-delà de 100 %' => ['prix_unitaire_ht' => '5.00', 'quantite' => 1, 'taux_tva' => '120'],
            'remise négative' => ['prix_unitaire_ht' => '5.00', 'quantite' => 1, 'taux_tva' => '20', 'remise_pourcentage' => '-1'],
            'remise au-delà de 100 %' => ['prix_unitaire_ht' => '5.00', 'quantite' => 1, 'taux_tva' => '20', 'remise_pourcentage' => '101'],
            'sans prix' => ['prix_unitaire_ht' => '', 'quantite' => 1, 'taux_tva' => '20'],
        ]);

        $this->assertSame(['valable'], array_keys($apercu->lignes));
        $this->assertSame(['total_ht' => '20.00', 'total_tva' => '4.00', 'total_ttc' => '24.00'], $apercu->totaux);
    }

    /**
     * Une ligne illisible ou pas encore valable n'arrête pas la lecture des
     * suivantes ; une ligne sans taux se calcule à 0 %, et chaque ligne de
     * l'aperçu garde le nom et la référence de son produit.
     */
    public function test_les_lignes_qui_suivent_une_ligne_ignoree_se_calculent_et_gardent_leur_produit(): void
    {
        $apercu = app(ConstructeurLigneAchat::class)->apercu([
            'pas un tableau' => 'ligne illisible',
            'prix négatif' => ['prix_unitaire_ht' => '-5.00', 'quantite' => 1, 'taux_tva' => '20'],
            'sans taux' => ['prix_unitaire_ht' => '10.00', 'quantite' => 1, 'produit' => ['nom' => 'Carnet', 'reference' => 'CARNET']],
        ]);

        $this->assertSame(['sans taux'], array_keys($apercu->lignes));
        $this->assertSame(['Carnet', 'CARNET'], [$apercu->lignes['sans taux']['description'], $apercu->lignes['sans taux']['reference']]);
        $this->assertSame(['total_ht' => '10.00', 'total_tva' => '0.00', 'total_ttc' => '10.00'], $apercu->totaux);
    }

    public function test_une_tva_ecrite_pour_un_taux_sans_ligne_laisse_la_proposition_de_piliqi(): void
    {
        $apercu = app(ConstructeurLigneAchat::class)->apercu(
            [['prix_unitaire_ht' => '10.00', 'quantite' => 1, 'taux_tva' => '20']],
            [550 => 100],
        );

        $this->assertSame(['total_ht' => '10.00', 'total_tva' => '2.00', 'total_ttc' => '12.00'], $apercu->totaux);
    }

    public function test_des_lignes_valables_dont_le_total_deborde_ne_donnent_aucun_apercu(): void
    {
        $ligne = ['prix_unitaire_ht' => '300000000000.00', 'quantite' => 2, 'taux_tva' => '20'];
        $constructeur = app(ConstructeurLigneAchat::class);

        $this->assertSame('720000000000.00', $constructeur->apercu([$ligne])->totaux['total_ttc']);

        $apercu = $constructeur->apercu([$ligne, $ligne]);

        $this->assertSame([], $apercu->lignes);
        $this->assertSame(['total_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00'], $apercu->totaux);
    }
}
