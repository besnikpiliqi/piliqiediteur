<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * L'aperçu de l'e-reporting dans le navigateur (T107.1, voir
 * EReportingDesParticuliers et EncaissementsDesPrestations) : le règlement
 * partiel d'un ticket qui mêle un livre à 5,5 % et une réparation à 20 %
 * se répartit au prorata de leur TTC, et la fiche de la journée montre la
 * part de la réparation, sans rien laisser « à répartir » ni « à
 * confirmer ».
 */

test('le responsable lit sur la fiche de l’e-reporting la part de la réparation dans un règlement partiel, répartie entre les taux', function () {
    $societe = $this->societeComplete();
    $cloture = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): ClotureCaisse {
        $client = Client::factory()->create(['nom' => 'Julie Bernard']);
        $mixte = app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle($societe->responsable, $client, [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 10.55, 'taux_tva' => 5.5, 'suivi_stock' => false])->id, 'quantite' => 1],
            ['service_id' => Service::factory()->create(['nom' => 'Réparation'])->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1],
        ]));
        app(EnregistrerPaiementAction::class)->handle($societe->responsable, $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => 30, 'recu_le' => '2026-03-10'], [['facture_id' => $mixte->id, 'montant' => 30]]);
        $this->travelTo(self::aParis('2026-03-10 19:00:00'));

        return app(CloturerCaisseAction::class)->handle($societe->responsable, $societe->magasin, '2026-03-10');
    });

    $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/e-reporting/'.$cloture->id)
        ->voir('E-reporting du 10/03/2026')
        ->voir('Encaissements des prestations de services')
        ->voir('25,51 €')
        ->nePasVoir('à répartir')
        ->nePasVoir('à confirmer');
});
