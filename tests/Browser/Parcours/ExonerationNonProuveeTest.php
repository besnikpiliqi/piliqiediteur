<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\MotifDeRegularisation;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * La régularisation d'une exonération non prouvée dans le navigateur
 * (T29.3, voir RegulariserUneExonerationNonProuveeAction) : 150 jours
 * après sa date, un export sans preuve de sortie entre dans l'« À faire »
 * du magasin, à faire pour qui vend, avec « Régulariser », qui mène à sa
 * fiche, où « Régulariser l'exonération » émet d'un geste l'avoir de
 * régularisation et la facture rectificative à la TVA française, qui
 * s'ouvre ; le propriétaire, qui ne vend pas, la surveille, et sa fiche ne
 * lui propose pas le geste.
 */

/**
 * Un export à Acme Inc., expédié à New York, émis par ce compte le jour
 * du test dans le magasin de la société de test.
 */
function exportSansPreuve(SocieteDeTest $societe, User $auteur): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, function () use ($auteur): Facture {
        $acme = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $acme, [['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
    });
}

test('le responsable ouvre depuis son « À faire » la liste « Exonération non prouvée », puis régularise chaque export resté sans preuve de sortie 150 jours après sa date, et chaque facture rectificative s’ouvre', function () {
    $societe = $this->societeComplete();
    $export = exportSansPreuve($societe, $societe->responsable);
    $this->travelTo(self::aParis('2026-03-11 10:00:00'));
    $seconde = exportSansPreuve($societe, $societe->responsable);
    $this->travelTo(self::aParis('2026-08-08 10:00:00'));

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid)
        ->voir('2 ventes hors de France sans preuve de sortie 150 jours après leur date.')
        ->cliquer('[data-a-faire="exonerations_non_prouvees"] >> text="Régulariser"')
        ->attendreLaPage('/factures$');
    expect(urldecode((string) parse_url($onglet->page()->url(), PHP_URL_QUERY)))->toMatch('/tableFilters\[exonerations_non_prouvees\]\[isActive\]=(1|true)/')
        ->and([$onglet->element('.fi-ta-row')->count(), $onglet->element('.fi-ta-row:has-text("'.$seconde->numero.'")')->count()])->toBe([2, 1]);

    $regulariser = fn (Navigation $onglet): Navigation => $onglet
        ->cliquerSur('Régulariser l\'exonération')
        ->voir('son exonération tombe')
        ->soumettre('Régulariser')
        ->voir('Exonération régularisée : facture');
    $rectificativeDe = fn (Facture $vente): Facture => app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('facture_rectifiee_id', $vente->id)->sole());

    $regulariser($onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$export->getRouteKey()));
    $rectificative = $rectificativeDe($export);
    $onglet->attendreLaPage('/factures/'.$rectificative->getRouteKey().'$')->voir((string) $rectificative->numero);

    $regulariser($onglet->aller('/app/'.$societe->magasin->uuid)
        ->voir('1 vente hors de France sans preuve de sortie 150 jours après sa date : '.$seconde->numero)
        ->cliquer('[data-a-faire="exonerations_non_prouvees"] >> text="Régulariser"')
        ->attendreLaPage('/factures/'.$seconde->getRouteKey().'$'));
    $onglet->attendreLaPage('/factures/'.$rectificativeDe($seconde)->getRouteKey().'$');

    $avoirs = app(TenantContext::class)->runAs($societe->magasin, fn (): array => Avoir::query()->whereNotNull('motif_regularisation')->pluck('motif_regularisation')->all());
    expect($rectificative->nature_operation)->toBe(NatureOperation::VenteEnFrance)
        ->and($rectificative->total_ttc)->toBe($export->total_ttc)
        ->and($rectificative->total_tva)->not->toBe('0.00')
        ->and($avoirs)->toBe([MotifDeRegularisation::ExonerationNonProuvee, MotifDeRegularisation::ExonerationNonProuvee]);
    $onglet->aller('/app/'.$societe->magasin->uuid)->nePasVoir('sans preuve de sortie 150 jours');
});

test('le propriétaire surveille l’export sans preuve de sortie depuis son « À faire », avec « Voir », et sa fiche ne lui propose pas de le régulariser', function () {
    $societe = $this->societeComplete();
    $export = exportSansPreuve($societe, $societe->responsable);
    $this->travelTo(self::aParis('2026-08-07 10:00:00'));

    $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid)
        ->voir('1 vente hors de France sans preuve de sortie 150 jours après sa date : '.$export->numero)
        ->cliquer('[data-a-faire="exonerations_non_prouvees"] >> text="Voir"')
        ->attendreLaPage('/factures/'.$export->getRouteKey().'$')
        ->voir((string) $export->numero)
        ->nePasVoir('Régulariser l\'exonération');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): int => Avoir::query()->whereNotNull('motif_regularisation')->count()))->toBe(0);
});
