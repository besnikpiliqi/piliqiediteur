<?php

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Models\VisaDetaxe;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * L'« À faire » dans le navigateur (demande de l'éditeur du 26/10/2026,
 * décisions du 01/11/2026 et du 02/11/2026) : chaque ligne mène d'un clic
 * à la fiche ou à la liste filtrée, avec le bouton du geste pour qui le
 * fait et « Voir » pour qui le surveille ; dans l'espace de la société, la
 * nomination d'un responsable par un administrateur se garde, l'écart
 * relevé au répertoire Sirene se dit vérifié, et la colonne « À faire » de
 * « Vos magasins » mène au tableau de bord du magasin. La société de test
 * a une facture émise par le vendeur et en partie réglée (voir
 * TestCaseDuNavigateur::remplirLeMagasin()).
 */

/**
 * Un ticket du comptoir vendu par ce compte (le responsable, sauf autre
 * vendeur), dont le n° du bordereau de détaxe est noté, dans ce magasin :
 * la vente se fait par cette fonction de vente du test (voir
 * TestCase::vendreAuComptant()).
 *
 * @param  Closure(User, Client, list<array<string, mixed>>): Facture  $vendre
 */
function ticketDetaxe(SocieteDeTest $societe, Closure $vendre, string $bordereau, ?User $vendeur = null): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, function () use ($societe, $vendre, $bordereau, $vendeur): Facture {
        $ticket = $vendre($vendeur ?? $societe->responsable, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2]]);
        app(NoterBordereauDetaxeAction::class)->handle($vendeur ?? $societe->responsable, $ticket, $bordereau);

        return $ticket;
    });
}

/**
 * Une vente émise par ce compte à Acme Inc., expédiée à New York, dans le
 * magasin de la société de test, qui attend la preuve de son export.
 */
function venteALExport(SocieteDeTest $societe, User $auteur): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, function () use ($auteur): Facture {
        $acme = Client::query()->firstWhere('nom', 'Acme Inc.') ?? Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $acme, [['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
    });
}

/**
 * Le seul document de cette classe, de ce statut, dans le magasin de la
 * société de test.
 *
 * @template TModele of Model
 *
 * @param  class-string<TModele>  $classe
 * @return TModele
 */
function seulDuMagasin(SocieteDeTest $societe, string $classe, BackedEnum $statut): Model
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): Model => $classe::query()->where('statut', $statut)->sole());
}

test('le vendeur encaisse sa facture depuis « À faire » et ouvre la liste filtrée ; le propriétaire la surveille avec « Voir »', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('À faire')->voir('1 facture à encaisser')
        ->cliquer('[data-a-faire="a_encaisser"] >> text="Encaisser"')
        ->attendreLaPage('/factures/[^/]+$')
        ->voir('Hôtel des Pins');
    $numero = app(TenantContext::class)->runAs($societe->magasin, fn (): string => (string) Facture::query()->whereRelation('client', 'nom', 'Hôtel des Pins')->whereNotNull('numero')->sole()->numero);
    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->cliquer('[data-a-faire="a_encaisser"] >> internal:role=link[name="'.$numero.'"s]')
        ->attendreLaPage('/factures/[^/]+$')
        ->voir($numero);
    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->cliquerSurLeLien('Voir toutes les factures à encaisser')
        ->attendreLaPage('/factures$');
    expect(urldecode((string) parse_url($onglet->page()->url(), PHP_URL_QUERY)))->toMatch('/tableFilters\[a_encaisser\]\[isActive\]=(1|true)/')
        ->and($onglet->element('.fi-ta-row')->count())->toBe(1);
    $onglet->seDeconnecter();

    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid);
    $onglet->voir('À surveiller')
        ->cliquer('[data-a-faire="a_encaisser"] >> text="Voir"')
        ->attendreLaPage('/factures/[^/]+$')
        ->voir('Hôtel des Pins');
});

test('un vendeur voit toutes ses factures à encaisser et jamais celles d’un autre vendeur, ni par la liste, ni par la fiche, ni par l’adresse ; avec « voir celles de toute l’équipe », il voit aussi les leurs', function () {
    $societe = $this->societeComplete();
    [$saFacture, $sienne, $equipe] = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $autre = $this->membre($societe->magasin, RoleMagasin::Vendeur, identite: ['name' => 'Aline Vente']);
        $saFacture = app(EmettreFactureAction::class)->handle($autre, app(CreerFactureAction::class)->handle($autre, Client::query()->where('nom', 'Hôtel des Pins')->sole(), [
            ['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1],
        ]));
        $equipe = $this->membre($societe->magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut($societe->societe->type_activite),
            PermissionRegistry::FACTURE_VIEW_TOUTES,
        ], ['name' => 'Élise Équipe']);
        $sienne = Facture::query()->whereRelation('client', 'nom', 'Hôtel des Pins')->where('cree_par', $societe->vendeur->id)->whereNotNull('numero')->sole();

        return [$saFacture, $sienne, $equipe];
    });
    $magasin = '/app/'.$societe->magasin->uuid;
    $ficheDeLAutre = $magasin.'/factures/'.$saFacture->getRouteKey();

    $onglet = $this->seConnecter($societe->vendeur)->aller($magasin)
        ->voir('1 facture à encaisser')->voir((string) $sienne->numero)->nePasVoir((string) $saFacture->numero)
        ->aller($magasin.'/factures')
        ->voir((string) $sienne->numero)->nePasVoir((string) $saFacture->numero);
    $this->tolerer(404, '/factures/'.$saFacture->getRouteKey());
    $onglet->aller($ficheDeLAutre)->nePasVoir((string) $saFacture->numero)->nePasVoir('Aline Vente');

    $this->seConnecter($equipe)->aller($magasin)
        ->voir('2 factures à encaisser')->voir((string) $sienne->numero)->voir((string) $saFacture->numero)
        ->aller($magasin.'/factures')
        ->voir((string) $sienne->numero)->voir((string) $saFacture->numero)
        ->aller($ficheDeLAutre)
        ->voir((string) $saFacture->numero);
});

test('le responsable note le visa d’un ticket détaxé depuis « À faire », puis la liste « Visa à noter » montre exactement les tickets qui l’attendent', function () {
    $societe = $this->societeComplete();
    $vendre = fn (User $auteur, Client $client, array $lignes): Facture => self::vendreAuComptant($auteur, $client, $lignes);
    $premier = ticketDetaxe($societe, $vendre, '2026FR000000001');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('1 ticket détaxé attend la date du visa de son bordereau n° 2026FR000000001')
        ->cliquer('[data-a-faire="visas_detaxe"] >> text="Noter le visa"')
        ->attendreLaPage('/factures/[^/]+$')
        ->cliquerSur('Noter le visa du bordereau')
        ->remplir('Date du visa', '2026-03-10')
        ->soumettre('Soumettre')
        ->voir('Visa du bordereau noté.')
        ->voir('le 10/03/2026, noté par '.$societe->responsable->name);
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): int => VisaDetaxe::query()->count()))->toBe(1);

    ticketDetaxe($societe, $vendre, '2026FR000000002');
    ticketDetaxe($societe, $vendre, '2026FR000000003');
    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->voir('2 tickets détaxés attendent la date du visa de leur bordereau.')
        ->cliquer('[data-a-faire="visas_detaxe"] >> text="Noter le visa"')
        ->attendreLaPage('/factures$');
    expect(urldecode((string) parse_url($onglet->page()->url(), PHP_URL_QUERY)))->toMatch('/tableFilters\[visa_a_noter\]\[isActive\]=(1|true)/')
        ->and([$onglet->element('.fi-ta-row')->count(), $onglet->element('.fi-ta-row:has-text("'.$premier->numero.'")')->count()])->toBe([2, 0]);
});

test('le responsable ouvre, depuis « À faire », la liste des devis à transformer puis celle des devis qui expirent bientôt, chacune avec ses seuls devis', function () {
    $societe = $this->societeComplete();
    [$acceptes, $quiExpirent] = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $client = Client::query()->where('nom', 'Hôtel des Pins')->sole();
        $lignes = [['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1]];
        $envoyer = fn (string $validite): Devis => app(EmettreDevisAction::class)->handle($societe->responsable, app(CreerDevisAction::class)->handle($societe->responsable, $client, $lignes, ['date_validite' => $validite]));

        return [
            [app(AccepterDevisAction::class)->handle($societe->responsable, $envoyer('2026-04-09')), app(AccepterDevisAction::class)->handle($societe->responsable, $envoyer('2026-04-09'))],
            [$envoyer('2026-03-12'), $envoyer('2026-03-17')],
        ];
    });
    $numeros = fn (Devis ...$devis): array => array_map(fn (Devis $un): string => (string) $un->numero, $devis);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('2 devis acceptés ne sont pas encore transformés en facture.')
        ->cliquer('[data-a-faire="devis_a_transformer"] >> text="Transformer en facture"')
        ->attendreLaPage('/devis$');
    expect(urldecode((string) parse_url($onglet->page()->url(), PHP_URL_QUERY)))->toMatch('/tableFilters\[a_transformer\]\[isActive\]=(1|true)/')
        ->and($onglet->element('.fi-ta-row')->allTextContents())->toHaveCount(2)
        ->and(implode(' ', $onglet->element('.fi-ta-row')->allTextContents()))->toContain($numeros(...$acceptes)[0], $numeros(...$acceptes)[1]);

    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->voir('2 devis envoyés expirent dans les 7 jours sans réponse du client.')
        ->cliquer('[data-a-faire="devis_qui_expirent"] >> text="Voir"')
        ->attendreLaPage('/devis$');
    expect(urldecode((string) parse_url($onglet->page()->url(), PHP_URL_QUERY)))->toMatch('/tableFilters\[expirent_bientot\]\[isActive\]=(1|true)/')
        ->and($onglet->element('.fi-ta-row')->allTextContents())->toHaveCount(2)
        ->and(implode(' ', $onglet->element('.fi-ta-row')->allTextContents()))->toContain($numeros(...$quiExpirent)[0], $numeros(...$quiExpirent)[1]);
});

test('le vendeur émet son brouillon de facture depuis « Émettre » de l’« À faire » : le bouton mène à sa fiche, qui porte le geste', function () {
    $societe = $this->societeComplete();
    $brouillon = seulDuMagasin($societe, Facture::class, StatutFacture::Brouillon);
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('1 brouillon de facture pas encore émis')
        ->cliquer('[data-a-faire="brouillons_de_facture"] >> text="Émettre"')
        ->attendreLaPage('/factures/'.$brouillon->getRouteKey().'$')
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFacture => $brouillon->fresh()->statut))->toBe(StatutFacture::Emise);
    $onglet->aller('/app/'.$societe->magasin->uuid)->nePasVoir('brouillon de facture pas encore émis');
});

test('le responsable numérote sans e-mail le devis en brouillon depuis « Envoyer » de l’« À faire », sur sa fiche, qui mène aussi à sa modification', function () {
    $societe = $this->societeComplete();
    $devis = seulDuMagasin($societe, Devis::class, StatutDevis::Brouillon);
    $fiche = '/app/'.$societe->magasin->uuid.'/devis/'.$devis->getRouteKey();
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('1 devis en brouillon pas encore envoyé')
        ->cliquer('[data-a-faire="brouillons_de_devis"] >> text="Envoyer"')
        ->attendreLaPage('/devis/'.$devis->getRouteKey().'$')
        ->cliquerSurLeLien('Modifier')
        ->attendreLaPage('/devis/'.$devis->getRouteKey().'/edit$');
    $onglet->aller($fiche)
        ->cliquerSur('Numéroter sans e-mail')
        ->soumettre('Confirmer')
        ->voir('Envoyé');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutDevis => $devis->fresh()->statut))->toBe(StatutDevis::Envoye);
    $onglet->aller('/app/'.$societe->magasin->uuid)->nePasVoir('devis en brouillon pas encore envoyé');
});

test('le responsable valide la facture d’achat en brouillon puis supprime l’inventaire d’ouverture en brouillon depuis l’« À faire », sur la fiche où mène chaque bouton', function () {
    $societe = $this->societeComplete();
    $achat = seulDuMagasin($societe, FactureAchat::class, StatutFactureAchat::Brouillon);
    $inventaire = seulDuMagasin($societe, InventaireOuverture::class, StatutInventaireOuverture::Brouillon);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid);

    $onglet->voir('1 facture d\'achat en brouillon')
        ->cliquer('[data-a-faire="achats_en_brouillon"] >> text="Valider"')
        ->attendreLaPage('/facture-achats/'.$achat->getRouteKey().'$')
        ->cliquerSur('Valider la réception')
        ->soumettre('Confirmer')
        ->voir('Validée');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFactureAchat => $achat->fresh()->statut))->toBe(StatutFactureAchat::Validee);

    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->voir('1 inventaire d\'ouverture en brouillon ne se valide plus')
        ->cliquer('[data-a-faire="inventaires_en_brouillon"] >> text="Supprimer"')
        ->attendreLaPage('/inventaires-ouverture/'.$inventaire->getRouteKey().'$')
        ->cliquerSur('Supprimer')
        ->cliquerSur('Supprimer')
        ->attendreLaPage('/inventaires-ouverture$');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): int => InventaireOuverture::query()->count()))->toBe(0);
    $onglet->aller('/app/'.$societe->magasin->uuid)->nePasVoir('inventaire d\'ouverture en brouillon');
});

test('dans le magasin fermé, le responsable puis le vendeur notent depuis leur « À faire » la preuve de leur vente à l’export (exemple de l’éditeur, Q-AF12)', function () {
    $societe = $this->societeComplete();
    $deLaResponsable = venteALExport($societe, $societe->responsable);
    $duVendeur = venteALExport($societe, $societe->vendeur);
    app(FermerMagasinAction::class)->handle($societe->proprietaire, $societe->magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_CHOISI);
    $noterLeMrn = fn (Navigation $onglet, string $mrn): Navigation => $onglet
        ->cliquerSur('Ajouter une preuve')
        ->choisir('Type de preuve', TypePreuve::Mrn->getLabel())
        ->remplir('Référence', $mrn)
        ->remplir('Établie par', 'Douane')
        ->soumettre('Soumettre')
        ->voir('Preuve enregistrée.');

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid)
        ->voir('2 ventes hors de France attendent leur preuve d\'export ou de livraison.')
        ->cliquer('[data-a-faire="preuves"] >> internal:role=link[name="'.$deLaResponsable->numero.'"s]')
        ->attendreLaPage('/factures/'.$deLaResponsable->getRouteKey().'$');
    $noterLeMrn($onglet, '26FR000000000001E1');
    $onglet->aller('/app/'.$societe->magasin->uuid)->voir('1 vente hors de France attend sa preuve : '.$duVendeur->numero);
    $onglet->seDeconnecter();

    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid)
        ->voir('1 vente hors de France attend sa preuve : '.$duVendeur->numero)
        ->cliquer('[data-a-faire="preuves"] >> text="Ajouter la preuve"')
        ->attendreLaPage('/factures/'.$duVendeur->getRouteKey().'$');
    $noterLeMrn($onglet, '26FR000000000002E1');
    $onglet->aller('/app/'.$societe->magasin->uuid)->nePasVoir('vente hors de France attend');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => PreuveOperation::query()->orderBy('id')->pluck('reference')->all()))->toBe(['26FR000000000001E1', '26FR000000000002E1']);
});

test('dans le magasin fermé, le responsable note le bordereau de détaxe d’un ticket sur sa fiche, puis la date de son visa depuis son « À faire »', function () {
    $societe = $this->societeComplete();
    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => self::vendreAuComptant($societe->responsable, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2]]));
    app(FermerMagasinAction::class)->handle($societe->proprietaire, $societe->magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_CHOISI);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->getRouteKey());

    $onglet->cliquerSur('Noter le bordereau de détaxe (BVE)')
        ->remplir('N° du bordereau (BVE)', '2026FR000000777')
        ->soumettre('Soumettre')
        ->voir('Bordereau de détaxe n° 2026FR000000777 noté.');
    $onglet->aller('/app/'.$societe->magasin->uuid)
        ->voir('1 ticket détaxé attend la date du visa de son bordereau n° 2026FR000000777')
        ->cliquer('[data-a-faire="visas_detaxe"] >> text="Noter le visa"')
        ->attendreLaPage('/factures/'.$ticket->getRouteKey().'$')
        ->cliquerSur('Noter le visa du bordereau')
        ->remplir('Date du visa', '2026-03-10')
        ->soumettre('Soumettre')
        ->voir('Visa du bordereau noté.');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [BordereauDetaxe::query()->count(), VisaDetaxe::query()->count()]))->toBe([1, 1]);
});

test('le vendeur, le propriétaire et l’administrateur notent chacun depuis leur « À faire » le visa du bordereau d’un ticket détaxé', function () {
    $societe = $this->societeComplete();
    $vendre = fn (User $auteur, Client $client, array $lignes): Facture => self::vendreAuComptant($auteur, $client, $lignes);
    $noterLeVisa = function (User $compte, Facture $ticket, string $bordereau): void {
        $this->seConnecter($compte)->aller('/app/'.$ticket->magasin->uuid)
            ->voir('1 ticket détaxé attend la date du visa de son bordereau n° '.$bordereau)
            ->cliquer('[data-a-faire="visas_detaxe"] >> text="Noter le visa"')
            ->attendreLaPage('/factures/'.$ticket->getRouteKey().'$')
            ->cliquerSur('Noter le visa du bordereau')
            ->remplir('Date du visa', '2026-03-10')
            ->soumettre('Soumettre')
            ->voir('Visa du bordereau noté.')
            ->voir('le 10/03/2026, noté par '.$compte->name)
            ->seDeconnecter();
    };

    $noterLeVisa($societe->vendeur, ticketDetaxe($societe, $vendre, '2026FR000000011', $societe->vendeur), '2026FR000000011');
    $noterLeVisa($societe->proprietaire, ticketDetaxe($societe, $vendre, '2026FR000000012'), '2026FR000000012');
    $noterLeVisa($societe->administrateur, ticketDetaxe($societe, $vendre, '2026FR000000013'), '2026FR000000013');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => VisaDetaxe::query()->orderBy('id')->pluck('note_par_nom')->all()))
        ->toBe([$societe->vendeur->name, $societe->proprietaire->name, $societe->administrateur->name]);
});

test('l’observateur qui voit les avoirs de toute l’équipe suit son « À surveiller » jusqu’au client qui garde un crédit d’avoir, et l’administrateur le sien jusqu’à la facture à encaisser', function () {
    $societe = $this->societeComplete();
    $observateur = $this->membre($societe->magasin, RoleMagasin::Observateur, [
        ...RoleMagasin::Observateur->permissionsParDefaut($societe->societe->type_activite),
        PermissionRegistry::FACTURE_VIEW_TOUTES,
        PermissionRegistry::AVOIR_VIEW,
    ], ['name' => 'Odile Avoirs']);
    $jeanDupont = app(TenantContext::class)->runAs($societe->magasin, fn (): Client => Client::query()->where('nom', 'Jean Dupont')->sole());

    $this->seConnecter($observateur)->aller('/app/'.$societe->magasin->uuid)
        ->voir('À surveiller')
        ->voir('1 client a un crédit d\'avoir non utilisé')
        ->cliquer('[data-a-faire="credits_des_clients"] >> internal:role=link[name="Jean Dupont"s]')
        ->attendreLaPage('/clients/'.$jeanDupont->getRouteKey().'$')
        ->voir('Afficher Client')
        ->seDeconnecter();

    $this->seConnecter($societe->administrateur)->aller('/app/'.$societe->magasin->uuid)
        ->voir('À surveiller')
        ->voir('1 facture à encaisser')
        ->cliquer('[data-a-faire="a_encaisser"] >> text="Voir"')
        ->attendreLaPage('/factures/[^/]+$')
        ->voir('Hôtel des Pins');
});

test('le propriétaire garde, depuis l’« À faire » de la société, la nomination d’un responsable par un administrateur', function () {
    $societe = $this->societeComplete();
    $paul = $societe->vendeur;
    app(ModifierMembreAction::class)->handle($societe->administrateur, $paul, RoleMagasin::Responsable);
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/societe/'.$societe->societe->uuid);

    $onglet->voir($paul->name.' a été nommé responsable de '.$societe->magasin->nom.' par '.$societe->administrateur->name)
        ->cliquer('[data-a-faire="nomination"] >> text="Garder ou annuler"')
        ->attendreLaPage('/comptes/')
        ->cliquerSur('Garder')
        ->soumettre('Confirmer')
        ->voir('La nomination est gardée.');

    expect(Affectation::query()->where('user_id', $paul->id)->sole()->nomme_par)->toBeNull();
    $onglet->aller('/societe/'.$societe->societe->uuid)->nePasVoir('a été nommé responsable de');
});

test('le propriétaire dit vérifié, depuis l’« À faire » de la société, l’écart relevé au répertoire Sirene', function () {
    Mail::fake();
    $societe = $this->societeComplete();
    app(RepertoireSirene::class)->deplacer((string) $societe->magasin->siret, '69001', 'LYON', '69381');
    app(ReverificationSirene::class)->reprendre($societe->societe, true);
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/societe/'.$societe->societe->uuid);

    $onglet->voir('Écart relevé au répertoire Sirene')
        ->cliquer('[data-a-faire="ecart_sirene"] >> text="C\'est vérifié"')
        ->soumettre('Confirmer')
        ->voir('Écart vérifié.')
        ->nePasVoir('Écart relevé au répertoire Sirene');

    expect(app(TenantContext::class)->runAsSociete($societe->societe, fn (): ?int => EcartSirene::query()->sole()->verifie_par))->toBe($societe->proprietaire->id);
});

test('l’administrateur qui gère les magasins dit vérifié, depuis l’« À faire » de la société, l’écart relevé au répertoire Sirene', function () {
    Mail::fake();
    $societe = $this->societeComplete();
    app(RepertoireSirene::class)->deplacer((string) $societe->magasin->siret, '69001', 'LYON', '69381');
    app(ReverificationSirene::class)->reprendre($societe->societe, true);
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($societe->administrateur)->aller('/societe/'.$societe->societe->uuid);

    $onglet->voir('Écart relevé au répertoire Sirene')
        ->cliquer('[data-a-faire="ecart_sirene"] >> text="C\'est vérifié"')
        ->soumettre('Confirmer')
        ->voir('Écart vérifié.')
        ->nePasVoir('Écart relevé au répertoire Sirene');

    expect(app(TenantContext::class)->runAsSociete($societe->societe, fn (): ?int => EcartSirene::query()->sole()->verifie_par))->toBe($societe->administrateur->id);
});

test('l’administrateur ouvre, depuis la colonne « À faire » de « Vos magasins », le tableau de bord du magasin', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->administrateur)->aller('/societe/'.$societe->societe->uuid);

    $onglet->cliquer('[data-a-faire-du-magasin="'.$societe->magasin->code.'"] a')
        ->attendreLaPage('/app/'.$societe->magasin->uuid)
        ->voir('À faire');
});
