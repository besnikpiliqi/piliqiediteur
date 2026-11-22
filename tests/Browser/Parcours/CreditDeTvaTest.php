<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Actions\ConfirmerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\DeclarerUnCreditDeTvaAction;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\SeuilDuTrimestriel;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Le crédit de TVA de départ et son report dans le navigateur (lot TVA27,
 * maquette « Message TVA avant PILIQI » approuvée par l'éditeur le
 * 18/11/2026) : le message du tableau de bord de la société, le bloc du
 * crédit de la page des charges et ses gestes, chez le propriétaire, chez
 * le collaborateur du cabinet lié et chez l'expert-comptable déclaré, le
 * lien sous le graphique de la TVA, la carte de la TVA reportée, et la
 * ligne du seuil du réel normal trimestriel, dite vérifiée. La société
 * s'ouvre le 9 mars 2026 au réel normal mensuel ; le test va au 15 avril
 * 2026, mars se déclarant depuis le 1er avril.
 */

test('le propriétaire suit le message du tableau de bord, indique son crédit de TVA de mars et le lit à vérifier par son comptable', function () {
    $societe = $this->societeComplete();
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->voir('Indiquez le crédit de TVA de votre déclaration de mars 2026, ou d\'une plus récente')
        ->cliquerSurLeLien('Indiquer mon crédit de TVA')
        ->attendreQue('location.pathname.endsWith("/charges")', 'le message ne mène pas à la page des charges')
        ->voir('Votre crédit de TVA')
        ->voir('Faites vérifier ce montant par votre expert-comptable.')
        ->cliquer('[data-credit-de-tva] >> '.Navigation::bouton('Indiquer mon crédit de TVA'))
        ->choisir('Mois de cette déclaration', 'Mars 2026')
        ->remplir('Solde de cette déclaration (négatif pour un crédit, 0 € si aucun)', '-1 250,00')
        ->soumettre('Enregistrer')
        ->voir('Crédit de TVA enregistré.')
        ->voir('À vérifier par votre comptable')
        ->voir('point de départ du report');

    $credit = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $societe->societe->getKey())->sole();
    expect([$credit->periode, $credit->solde, $credit->saisi_comme])->toBe(['2026-03', -125000, ValideePar::Proprietaire]);

    $onglet->aller('/societe/'.$societe->societe->uuid)->attendre()->verifier()
        ->voir('Crédit de TVA saisi : -1 250,00 € (déclaration de mars 2026), à vérifier par votre comptable')
        ->attendreQue('document.querySelector(\'[data-chiffre="tva"]\') !== null', 'la carte de la TVA n’est pas affichée')
        ->voir('crédit de départ à vérifier par votre comptable');
});

test('le collaborateur du cabinet lit le crédit de départ à vérifier sur son tableau de bord, le confirme depuis la page des charges du client, puis le corrige avec un motif', function () {
    $societe = $this->societeComplete();
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));
    app(TenantContext::class)->clear();
    $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($societe->proprietaire, $societe->societe, '2026-03', -125000);
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->cliquerSurLeLien('Crédit de TVA de départ à vérifier')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la ligne du tableau de bord ne mène pas aux charges du client')
        ->voir('Le crédit de TVA de la société')
        ->voir('À vérifier')
        ->cliquer('tr[data-declaration="'.$credit->uuid.'"] >> '.Navigation::bouton('Confirmer'))
        ->soumettre('Confirmer')
        ->voir('Crédit de TVA vérifié.')
        ->voir('Vérifié par le comptable')
        ->cliquer('tr[data-declaration="'.$credit->uuid.'"] >> '.Navigation::bouton('Corriger'))
        ->remplir('Solde corrigé (négatif pour un crédit, 0 € si aucun)', '-1 200,00')
        ->remplir('Motif de la correction', 'Ligne 27 de la déclaration de mars : 1 200 €')
        ->soumettre('Corriger')
        ->voir('Crédit de TVA corrigé.')
        ->voir('motif : Ligne 27 de la déclaration de mars : 1 200 €');

    $relu = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->findOrFail($credit->getKey());
    expect([$relu->solde, $relu->verifie_comme, $relu->motif])->toBe([-120000, ValideePar::Cabinet, 'Ligne 27 de la déclaration de mars : 1 200 €']);

    $onglet->aller('/cabinet/'.$societe->cabinet->uuid)->attendre()->verifier()
        ->nePasVoir('Crédit de TVA de départ à vérifier');
});

test('avant la fin de son premier mois, le propriétaire lit la TVA déjà calculée, en attente de sa déclaration, et le bouton grisé avec sa date', function () {
    $societe = $this->societeComplete();

    $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->voir('En attente de la déclaration de votre crédit de TVA')
        ->voir('PILIQI calcule déjà votre TVA depuis le 09/03/2026.')
        ->attendreQue('document.querySelector(\'[data-geste-du-message="attente"]\')?.disabled === true', 'le bouton du message n’est pas grisé')
        ->cliquerSurLeLien('Charges')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la page des charges ne s’ouvre pas')
        ->voir('Disponible le 01/04/2026.')
        ->attendreQue('document.querySelector(\'[data-credit-de-tva] button\')?.disabled === true', 'le bouton « Indiquer mon crédit de TVA » n’est pas grisé');
});

test('sous le graphique de la TVA, le propriétaire suit « Indiquer ou voir le crédit de TVA » et modifie son crédit vérifié, qui repasse « à vérifier » par son comptable', function () {
    $societe = $this->societeComplete();
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));
    app(TenantContext::class)->clear();
    $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($societe->proprietaire, $societe->societe, '2026-03', -125000);
    app(TenantContext::class)->clear();
    app(ConfirmerUnCreditDeTvaAction::class)->handle($societe->collaborateur, $societe->societe, (string) $credit->uuid);
    app(TenantContext::class)->clear();

    $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->voir('Crédit de TVA vérifié par votre comptable le 15/04/2026')
        ->cliquerSurLeLien('Indiquer ou voir le crédit de TVA')
        ->attendreQue('location.pathname.endsWith("/charges")', 'le lien sous le graphique de la TVA ne mène pas à la page des charges')
        ->voir('Vérifié par le comptable')
        ->cliquer('tr[data-declaration="'.$credit->uuid.'"] >> '.Navigation::bouton('Modifier'))
        ->remplir('Solde de cette déclaration (négatif pour un crédit, 0 € si aucun)', '-1 100,00')
        ->soumettre('Enregistrer')
        ->voir('Crédit de TVA modifié.')
        ->voir('À vérifier par votre comptable');

    $relu = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->findOrFail($credit->getKey());
    expect([$relu->solde, $relu->verifie_le, $relu->saisi_comme])->toBe([-110000, null, ValideePar::Proprietaire]);
});

test('l’expert-comptable déclaré lit « Crédit de TVA de départ à vérifier » sur « Tableau de bord de la société » et le confirme depuis « Charges de la société »', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $marc = $this->expertComptable($magasin);
    $societe = $magasin->societe()->firstOrFail();
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));
    app(TenantContext::class)->clear();
    $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaireDe($magasin), $societe, '2026-03', -125000);
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($marc)
        ->cliquerSurLeLien('Tableau de bord de la société')
        ->voir('Crédit de TVA de départ à vérifier : -1 250,00 €')
        ->cliquer('[data-credit-de-tva-a-verifier] >> internal:role=link[name="Vérifier"s]')
        ->attendreQue('location.pathname.endsWith("/charges-de-la-societe")', 'le lien ne mène pas à « Charges de la société »')
        ->voir('Le crédit de TVA de la société')
        ->cliquer('tr[data-declaration="'.$credit->uuid.'"] >> '.Navigation::bouton('Confirmer'))
        ->soumettre('Confirmer')
        ->voir('Crédit de TVA vérifié.')
        ->voir('Vérifié par le comptable');

    $relu = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->findOrFail($credit->getKey());
    expect([$relu->verifie_comme, $relu->verifie_par])->toBe([ValideePar::ExpertComptable, $marc->getKey()]);

    $onglet->cliquerSurLeLien('Tableau de bord de la société')
        ->nePasVoir('Crédit de TVA de départ à vérifier :')
        ->voir('Crédit de TVA vérifié le 15/04/2026');
});

test('le propriétaire d’une société au trimestriel dont le chiffre d’affaires de l’année dépasse 1 100 000 € lit la ligne de son « À faire » et dit « C’est vérifié »', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAsSociete($societe->societe, fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($societe->proprietaire, $societe->societe->refresh(), ['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]));
    app(TenantContext::class)->runAs($societe->magasin->fresh(), function () use ($societe): void {
        $armoire = Produit::factory()->create(['nom' => 'Armoire de chêne', 'reference' => 'ARMOIRE', 'prix_vente_ttc' => '1320.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle($societe->responsable, Client::factory()->create(['nom' => 'Hôtel du Lac']), [['produit_id' => $armoire->id, 'quantite' => 1000]]));
    });
    app(TenantContext::class)->clear();

    $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->voir(SeuilDuTrimestriel::PHRASE)
        ->cliquer('[data-a-faire="seuil_du_trimestriel"] >> '.Navigation::bouton('C\'est vérifié'))
        ->soumettre('Confirmer')
        ->voir('Régime de TVA vérifié pour cette année.')
        ->nePasVoir(SeuilDuTrimestriel::PHRASE);

    expect(EntreeAudit::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $societe->societe->getKey())->where('action', ActionAuditee::SeuilDuTrimestrielVerifie->value)->count())->toBe(1);
});
