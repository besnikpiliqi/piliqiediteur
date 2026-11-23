<?php

namespace Tests\Browser\Support;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Le jeu des tests en navigateur des graphiques par mois, par trimestre ou
 * par année (choix de l'éditeur du 16/11/2026, lot GRAPH-AN) : une société
 * qui vend depuis 2023, avec une année vide au milieu, regardée le 10 mars
 * 2026 à 10 h à Paris (voir TestCaseDuNavigateur::instantDuTest()). Chaque
 * vente est faite par les vraies actions, au nom du responsable du magasin,
 * dans l'ordre du temps :
 *
 * - Boutique du Port : une lampe à 20,00 € HT (24,00 € TTC) le 10 mai 2023 ;
 *   rien en 2024 ; une lampe le 4 mars 2025, et dix câbles achetés 5,00 € HT
 *   le 5 mars 2025 (50,00 € HT d'achats) ; une facture de
 *   24,00 € TTC à l'Hôtel des Pins le 12 janvier 2026, jamais payée, en
 *   retard depuis le 11 février ; une lampe le 10 février, une le 3 mars,
 *   cinq câbles achetés le 4 mars (25,00 € HT), deux lampes le 5 mars 2026 ;
 * - Boutique de la Gare, ouverte le 1er février 2026 : une lampe le 5 mars
 *   2026 à 10 h 30.
 *
 * Le mois de mars 2026 (du 1er au 10) vaut donc 80,00 € HT de ventes contre
 * 20,00 € un an plus tôt (+300 %), 25,00 € d'achats contre 50,00 € (−50 %),
 * et 24,00 € restent dus, tous en retard. Par année : 2023 à 20,00 €, 2024 à
 * 0, 2025 à 20,00 €, 2026 à 120,00 € ; les six années d'avant 2023 n'ont
 * aucune donnée. Le trait s'utilise avec `uses()` dans le fichier du test.
 */
trait SemeLesVentesDeTroisAnnees
{
    /**
     * Sème le jeu et rend le magasin du Port, celui de la Gare et le
     * propriétaire, dont le mot de passe est choisi (sauf avec
     * $proprietaireChoisitSonMotDePasse à faux : l'expert-comptable que le
     * propriétaire déclare le redonne, tel qu'à l'inscription) ; l'horloge
     * revient à l'instant du test.
     *
     * @return array{magasin: Magasin, autre: Magasin, proprietaire: User}
     */
    protected function semerLesVentesDeTroisAnnees(bool $proprietaireChoisitSonMotDePasse = true): array
    {
        $this->travelTo(self::aParis('2023-05-09 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']));
        $proprietaire = $this->proprietaireDe($magasin);

        if ($proprietaireChoisitSonMotDePasse) {
            $this->choisirSonMotDePasse($proprietaire);
        }

        [$lampe, $cable, $particulier, $hotel] = app(TenantContext::class)->runAs($magasin, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]),
            Client::factory()->create(['nom' => 'Julie Petit']),
            Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']),
        ]);
        $responsable = self::responsableDeTest($magasin);
        $vendre = fn (CarbonImmutable $instant, int $lampes) => app(TenantContext::class)->runAs($magasin, function () use ($instant, $lampes, $responsable, $particulier, $lampe): void {
            $this->travelTo($instant);
            self::vendreAuComptant($responsable, $particulier, [['produit_id' => $lampe->id, 'quantite' => $lampes]]);
        });
        $acheter = fn (CarbonImmutable $instant, string $jourDeLaFacture, int $cables) => app(TenantContext::class)->runAs($magasin, function () use ($instant, $jourDeLaFacture, $cables, $responsable, $cable): void {
            $this->travelTo($instant);
            app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(['nom' => 'Grossiste Est']), [
                ['produit_id' => $cable->id, 'quantite' => $cables, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
            ], ['date_facture' => $jourDeLaFacture]));
        });

        $vendre(self::aParis('2023-05-10 10:00:00'), 1);
        $vendre(self::aParis('2025-03-04 10:00:00'), 1);
        $acheter(self::aParis('2025-03-05 10:00:00'), '2025-03-05', 10);
        app(TenantContext::class)->runAs($magasin, function () use ($responsable, $hotel, $lampe): void {
            $this->travelTo(self::aParis('2026-01-12 10:00:00'));
            app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $hotel, [['produit_id' => $lampe->id, 'quantite' => 1]]));
        });

        $this->travelTo(self::aParis('2026-02-01 09:00:00'));
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        [$lampeDeLaGare, $clientDeLaGare] = app(TenantContext::class)->runAs($autre, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Client::factory()->create(['nom' => 'Julie Petit']),
        ]);

        $vendre(self::aParis('2026-02-10 10:00:00'), 1);
        $vendre(self::aParis('2026-03-03 10:00:00'), 1);
        $acheter(self::aParis('2026-03-04 10:00:00'), '2026-03-04', 5);
        $vendre(self::aParis('2026-03-05 10:00:00'), 2);
        app(TenantContext::class)->runAs($autre, function () use ($autre, $lampeDeLaGare, $clientDeLaGare): void {
            $this->travelTo(self::aParis('2026-03-05 10:30:00'));
            self::vendreAuComptant(self::responsableDeTest($autre), $clientDeLaGare, [['produit_id' => $lampeDeLaGare->id, 'quantite' => 1]]);
        });

        app(TenantContext::class)->clear();
        $this->travelTo(self::instantDuTest());

        return ['magasin' => $magasin->fresh(), 'autre' => $autre->fresh(), 'proprietaire' => $proprietaire->refresh()];
    }
}
