<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\DepannageADomicile;
use App\Domain\Facturation\Services\DroitDeRetractation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Le devis de dépannage, de réparation ou d'entretien à domicile
 * (arrêté du 24 janvier 2017, décision de Claude T51.6), dans un vrai
 * navigateur : le propriétaire et le responsable cochent le réglage dans
 * Paramètres > « Factures et devis » et règlent le prix d'un devis payant ;
 * chaque compte qui fait un devis à un particulier y trouve le lieu de
 * l'intervention prérempli avec l'adresse du client, coche « Devis signé
 * chez le client », lit les deux sur la fiche, et le PDF du devis numéroté
 * imprime « Devis gratuit », les pièces remplacées et, sur sa dernière
 * page, le droit de rétractation et son formulaire, sous leurs titres en
 * capitales ; chaque compte qui modifie un brouillon fait avant que le
 * magasin coche le réglage y trouve le lieu prérempli, et la fiche le
 * montre une ligne par ligne saisie. Le test commence le 10
 * mars 2026 à 10 h à Paris (voir TestCaseDuNavigateur).
 */

/**
 * Tous les textes du PDF téléchargé depuis la fiche ouverte, page par
 * page.
 *
 * @return list<string>
 */
function pagesDuPdfDuDevisDeDepannage(Navigation $onglet, Closure $pagesDuPdf): array
{
    $onglet->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);

    return array_map(fn (array $page): string => preg_replace('/\s+/u', ' ', implode(' ', array_column($page['textes'], 'texte'))) ?? '', $pagesDuPdf(base64_decode($fichiers[0]['contenu'])));
}

dataset('comptes qui règlent les factures et les devis', [
    'le propriétaire' => [fn (SocieteDeTest $societe) => $societe->proprietaire],
    'le responsable' => [fn (SocieteDeTest $societe) => $societe->responsable],
]);

test('chaque compte qui règle les paramètres du magasin coche le dépannage à domicile et le prix du devis dans « Factures et devis », et le réglage s’enregistre', function (Closure $compte) {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($compte($societe))->aller('/app/'.$societe->magasin->uuid.'/parametres-factures')
        ->voir('Factures et devis : échéance et mentions')
        ->voir('Devis de dépannage à domicile')
        ->nePasVoir('Prix du devis');
    $onglet->cliquer('label:has-text('.Navigation::texte('Dépannage, réparation ou entretien à domicile').') >> button[role="switch"]')
        ->voir('Prix du devis')
        ->remplir('Prix du devis', '35.5')
        ->soumettre('Enregistrer')
        ->voir('Paramètres des factures et des devis enregistrés');

    expect(Magasin::query()->whereKey($societe->magasin->id)->sole()->only(['depannage_a_domicile', 'prix_du_devis_de_depannage']))
        ->toBe(['depannage_a_domicile' => true, 'prix_du_devis_de_depannage' => '35.50']);
})->with('comptes qui règlent les factures et les devis');

dataset('comptes qui font un devis de dépannage', [
    'le propriétaire' => [fn (SocieteDeTest $societe) => $societe->proprietaire],
    'le responsable' => [fn (SocieteDeTest $societe) => $societe->responsable],
    'le vendeur' => [fn (SocieteDeTest $societe) => $societe->vendeur],
    'l’administrateur autorisé à vendre' => [fn (SocieteDeTest $societe) => $societe->administrateurVendeur],
]);

test('chaque compte qui fait un devis à un particulier y trouve le lieu de l’intervention prérempli, coche « Devis signé chez le client », et la fiche puis le PDF numéroté les montrent', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    Magasin::query()->whereKey($societe->magasin->id)->update(['depannage_a_domicile' => true]);
    app(TenantContext::class)->runAs($societe->magasin, fn (): bool => Client::query()->where('nom', 'Jean Dupont')->sole()->forceFill(['adresse' => '7 rue du Port', 'complement_adresse' => null, 'code_postal' => '13002', 'ville' => 'Marseille'])->save());
    $onglet = $this->seConnecter($auteur)->aller($magasin.'/devis/create');

    $onglet->choisirLeClient('Hôtel des Pins')
        ->nePasVoir('Lieu de l\'intervention')
        ->choisirLeClient('Jean Dupont')
        ->voir('Lieu de l\'intervention')
        ->attendreQue('document.querySelector(\'textarea[id$="lieu_d_intervention"]\')?.value === '.json_encode("7 rue du Port\n13002 Marseille"), 'le lieu de l\'intervention n\'est pas prérempli avec l\'adresse du client');
    $onglet->geste(fn () => $onglet->element('label:has-text("Devis signé chez le client") >> input[type=checkbox]')->check(), 'la case « Devis signé chez le client »')
        ->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Bougie parfumée').')')
        ->cliquerSur('Ajouter le produit')
        ->remplir('Valide jusqu\'au', '2026-04-10')
        ->soumettre('Créer')
        ->attendreLaPage('/devis$');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => Devis::query()->latest('id')->firstOrFail());
    expect([$devis->lieu_d_intervention, $devis->signe_chez_le_client])->toBe(["7 rue du Port\n13002 Marseille", true]);

    $onglet->aller($magasin.'/devis/'.$devis->getRouteKey())
        ->voir('Lieu de l\'intervention')
        ->voir('7 rue du Port')
        ->voir('Oui : le devis imprime le droit de rétractation et son formulaire')
        ->cliquerSur('Numéroter sans e-mail')
        ->soumettre('Confirmer')
        ->voir('Envoyé');

    $pages = pagesDuPdfDuDevisDeDepannage($onglet, fn (string $pdf): array => $this->pagesDuPdf($pdf));
    expect($pages[0])->toContain('Lieu de l\'intervention : 7 rue du Port')
        ->toContain('Devis gratuit')
        ->toContain(DepannageADomicile::PIECES_REMPLACEES)
        ->and(end($pages))->toContain(mb_strtoupper(DroitDeRetractation::TITRE))
        ->toContain(mb_strtoupper(DroitDeRetractation::TITRE_DU_FORMULAIRE))
        ->toContain('dans un délai de quatorze jours');
})->with('comptes qui font un devis de dépannage');

test('chaque compte qui modifie un brouillon fait avant le réglage y trouve le lieu de l’intervention prérempli, l’enregistre, et la fiche le montre ligne par ligne', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, function () use ($auteur): Devis {
        $client = Client::query()->where('nom', 'Jean Dupont')->sole();
        $client->forceFill(['adresse' => '7 rue du Port', 'complement_adresse' => 'Escalier C', 'code_postal' => '13002', 'ville' => 'Marseille'])->save();

        return app(CreerDevisAction::class)->handle($auteur, $client, [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]], ['date_validite' => '2026-04-10']);
    });
    app(TenantContext::class)->clear();
    Magasin::query()->whereKey($societe->magasin->id)->update(['depannage_a_domicile' => true]);

    $onglet = $this->seConnecter($auteur)->aller($magasin.'/devis/'.$brouillon->getRouteKey().'/edit')
        ->voir('Lieu de l\'intervention')
        ->attendreQue('document.querySelector(\'textarea[id$="lieu_d_intervention"]\')?.value === '.json_encode("7 rue du Port\nEscalier C\n13002 Marseille"), 'le lieu de l\'intervention n\'est pas prérempli avec l\'adresse du client');
    $onglet->soumettre('Sauvegarder')
        ->attendreLaPage('/devis$')
        ->aller($magasin.'/devis/'.$brouillon->getRouteKey())
        ->voir('Lieu de l\'intervention');
    expect($onglet->element('.fi-in-entry-wrp:has-text("Lieu de l\'intervention") br')->count())->toBe(2);

    $relu = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => $brouillon->fresh());
    expect($relu->lieu_d_intervention)->toBe("7 rue du Port\nEscalier C\n13002 Marseille");
})->with('comptes qui font un devis de dépannage');
