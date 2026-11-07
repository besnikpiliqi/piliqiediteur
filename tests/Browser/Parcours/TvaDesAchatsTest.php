<?php

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * La TVA des achats dans le navigateur (décision de l'éditeur du
 * 02/11/2026, T1 et T2) : une facture d'achat se saisit au PU HT à six
 * décimales, avec la TVA qu'écrit la facture du fournisseur ; dans la
 * tolérance, elle s'enregistre sans rien demander ; au-delà, la case de
 * confirmation est exigée, et la facture marquée « TVA à vérifier » se
 * filtre dans la liste et se lit sur sa fiche. Un avoir fournisseur
 * recopie de même sa note d'avoir. La marque se voit de chaque rôle qui
 * voit les achats, cabinet lié compris. La société de test a tous ses
 * rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

const CONFIRMER_LA_FACTURE = 'Je confirme ces montants : ce sont ceux de la facture du fournisseur. La facture sera marquée « TVA à vérifier ».';

const CONFIRMER_LA_NOTE_D_AVOIR = 'Je confirme ces montants : ce sont ceux de la note d\'avoir du fournisseur. L\'avoir sera marqué « TVA à vérifier ».';

/**
 * Le formulaire d'une nouvelle facture d'achat du Grossiste du Nord, avec
 * 1 000 bougies parfumées à 0,1234 € HT, ajoutées par « Ajouter un
 * produit » : 123,40 € HT proposés, que remplace le HT de la ligne écrit
 * sur la facture, s'il est donné.
 */
function factureDeMilleBougies(Navigation $onglet, Magasin $magasin, ?string $htDeLaLigne = null): Navigation
{
    $onglet->aller('/app/'.$magasin->uuid.'/facture-achats/create')
        ->choisirDansLaListe('Fournisseur', 'Grossiste du Nord')
        ->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->remplir('Quantité reçue', '1000')
        ->remplir('Prix d\'achat HT', '0.1234');

    if ($htDeLaLigne !== null) {
        $onglet->remplir('Total HT de la ligne sur la facture', $htDeLaLigne)
            ->voir('Calcul de PILIQI : 123,40 € (quantité × prix d\'achat, remise déduite).');
    }

    return $onglet->soumettre('Ajouter à la facture')
        ->voir('1000 x 0,1234 € HT = 123,40 € HT');
}

/**
 * Coche la case de ce libellé, dans la fenêtre ouverte s'il y en a une.
 */
function cocher(Navigation $onglet, string $libelle): Navigation
{
    return $onglet->geste(fn () => $onglet->element(($onglet->fenetreOuverte() ? Navigation::FENETRE.' >> ' : '').Navigation::champ($libelle))->check(), 'la case « '.$libelle.' »');
}

/**
 * Montre, dans la liste ouverte, les seuls documents marqués « TVA à
 * vérifier », par le filtre du tableau.
 */
function filtrerLaTvaAVerifier(Navigation $onglet): Navigation
{
    return $onglet->cliquer('.fi-ta-filters-dropdown .fi-dropdown-trigger')
        ->cliquer('[id="tableFilters.tva_a_verifier.isActive"]');
}

/**
 * Le nombre de lignes de la liste ouverte qui mènent à cette fiche.
 */
function lignesVers(Navigation $onglet, string $fin): int
{
    return $onglet->element('.fi-ta-row:has(a[href$='.Navigation::texte($fin).'])')->count();
}

/**
 * Le nombre de marques « TVA à vérifier » dans la ligne de la liste ouverte
 * qui mène à cette fiche.
 */
function marquesDansLaLigne(Navigation $onglet, string $fin): int
{
    return $onglet->element('.fi-ta-row:has(a[href$='.Navigation::texte($fin).']) >> text=TVA à vérifier')->count();
}

/**
 * Une facture d'achat validée du Grossiste du Nord : 4 diffuseurs à 12,50 €
 * HT (20 %), dont le stock n'est pas suivi.
 */
function factureDeQuatreDiffuseurs(Magasin $magasin, User $responsable): FactureAchat
{
    return app(TenantContext::class)->runAs($magasin, function () use ($responsable): FactureAchat {
        $diffuseur = Produit::factory()->create(['nom' => 'Diffuseur', 'reference' => 'DIFFUSEUR', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::query()->where('nom', 'Grossiste du Nord')->sole(), [
            ['produit_id' => $diffuseur->id, 'quantite' => 4, 'prix_unitaire_ht' => '12.50'],
        ]));
    });
}

/*
 * Le HT de la ligne écrit sur la facture (123,41 €) et sa TVA (24,69 €)
 * s'écartent d'un centime de la proposition (123,40 € et 24,68 €) : dans
 * la tolérance, rien n'est demandé.
 */
test('le responsable saisit une facture d’achat au PU à six décimales, avec le HT et la TVA du fournisseur dans la tolérance', function () {
    $societe = $this->societeComplete();
    $onglet = factureDeMilleBougies($this->seConnecter($societe->responsable), $societe->magasin, '123.41')
        ->voir('HT de la facture : 123,41 € + 24,68 € de TVA')
        ->voir('Base HT 123,41 € · calcul depuis les lignes : 24,68 €. Laissez vide pour le garder.')
        ->remplir('TVA à 20 % sur la facture', '24.69')
        ->remplir('Total TTC de la facture (contrôle)', '148.10')
        ->soumettre('Créer')
        ->attendreLaPage('/facture-achats$');

    $factureAchat = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => FactureAchat::query()->latest('id')->firstOrFail()->load('lignes'));
    expect([$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc, $factureAchat->tva_a_verifier])->toBe(['123.41', '24.69', '148.10', false])
        ->and([$factureAchat->lignes->sole()->prix_unitaire_ht, $factureAchat->lignes->sole()->montant_ht_saisi])->toBe(['0.1234', '123.41'])
        ->and(marquesDansLaLigne($onglet, '/facture-achats/'.$factureAchat->getRouteKey()))->toBe(0);
});

test('le responsable confirme une TVA du fournisseur au-delà de la tolérance, puis retrouve la facture marquée par le filtre et sur sa fiche', function () {
    $societe = $this->societeComplete();
    $onglet = factureDeMilleBougies($this->seConnecter($societe->responsable), $societe->magasin)
        ->voir('1000 x 0,1234 € HT = 123,40 € HT + 24,68 € de TVA')
        ->remplir('TVA à 20 % sur la facture', '25.00')
        ->soumettre('Créer')
        ->voir('À 20 % : la TVA saisie (25,00 €) s\'écarte de 0,32 € de la base × taux (24,68 €).')
        ->voir('Confirmez les montants de la facture, ou corrigez votre saisie.');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): bool => FactureAchat::query()->where('total_tva', '25.00')->exists()))->toBeFalse();

    cocher($onglet, CONFIRMER_LA_FACTURE)->soumettre('Créer')->attendreLaPage('/facture-achats$');

    $marquee = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => FactureAchat::query()->latest('id')->firstOrFail());
    expect([$marquee->total_tva, $marquee->tva_a_verifier])->toBe(['25.00', true]);

    $fiche = '/facture-achats/'.$marquee->getRouteKey();
    expect(marquesDansLaLigne($onglet, $fiche))->toBe(1)
        ->and($onglet->element('.fi-ta-row')->count())->toBeGreaterThan(1);
    filtrerLaTvaAVerifier($onglet);
    expect(lignesVers($onglet, $fiche))->toBe(1)
        ->and($onglet->element('.fi-ta-row')->count())->toBe(1);

    $onglet->aller('/app/'.$societe->magasin->uuid.$fiche)
        ->voir('TVA à vérifier')
        ->voir('Calcul depuis les lignes : 24,68 €')
        ->voir('Confirmé par Rose Martin le');
});

test('le responsable crée un avoir fournisseur depuis la fiche d’une facture d’achat avec le HT et la TVA de la note d’avoir, et confirme leur écart', function () {
    $societe = $this->societeComplete();
    $factureAchat = factureDeQuatreDiffuseurs($societe->magasin, $societe->responsable);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/facture-achats/'.$factureAchat->getRouteKey());

    $onglet->cliquerSur('Créer un avoir fournisseur')
        ->remplirLaLigne('lignes', 0, 'quantite', '2')
        ->remplirLaLigne('lignes', 0, 'montant_ht', '25.10')
        ->remplirLaLigne('taux', 0, 'montant_tva', '5.02')
        ->remplir('N° de l\'avoir du fournisseur', 'AV-NORD-2')
        ->remplir('Motif du retour', 'Diffuseurs fêlés')
        ->soumettre('Émettre l\'avoir fournisseur')
        ->voir('« Diffuseur » : le HT saisi (25,10 €) s\'écarte de 0,10 € de la quantité × le PU HT, remise déduite (25,00 €).')
        ->voir('Confirmez les montants de la note d\'avoir, ou corrigez votre saisie.');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): bool => AvoirFournisseur::query()->where('numero_fournisseur', 'AV-NORD-2')->exists()))->toBeFalse();

    cocher($onglet, CONFIRMER_LA_NOTE_D_AVOIR)->soumettre('Émettre l\'avoir fournisseur')->voir('émis.');

    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): AvoirFournisseur => AvoirFournisseur::query()->where('numero_fournisseur', 'AV-NORD-2')->sole());
    expect([$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc, $avoir->tva_a_verifier])->toBe(['25.10', '5.02', '30.12', true]);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs');
    filtrerLaTvaAVerifier($onglet)->voir($avoir->numero);
    expect($onglet->element('.fi-ta-row')->count())->toBe(1);
});

test('la marque « TVA à vérifier » et son filtre se voient de chaque rôle qui voit les achats, cabinet lié compris ; seul qui crée les factures d’achat en saisit la TVA', function () {
    $societe = $this->societeComplete();
    $marquee = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => app(CreerFactureAchatAction::class)->handle($societe->responsable, Fournisseur::query()->where('nom', 'Grossiste du Nord')->sole(), [
        ['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2, 'prix_unitaire_ht' => '5.00'],
    ], [], MontantsDuFournisseur::depuisLaSaisie(['taux' => [['taux_tva' => '20', 'montant_tva' => '2.50']], 'confirmation' => true])));
    $liste = '/app/'.$societe->magasin->uuid.'/facture-achats';
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', true, true],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', true, true],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', true, true],
        'le responsable' => [$societe->responsable, 'app', true, true],
        'le vendeur' => [$societe->vendeur, 'app', false, false],
        'l’observateur' => [$societe->observateur, 'app', true, false],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', true, false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $voit, $cree]) {
        $onglet = $this->seConnecter($compte, panneau: $panneau);

        if (! $voit) {
            $onglet->aller('/app/'.$societe->magasin->uuid);
            expect([$qui, $onglet->element('.fi-sidebar a[href$="/facture-achats"]')->count()])->toBe([$qui, 0]);
            $onglet->seDeconnecter();

            continue;
        }

        $onglet->aller($liste);
        expect([$qui, marquesDansLaLigne($onglet, '/facture-achats/'.$marquee->getRouteKey()), $onglet->element('.fi-header a[href$="/facture-achats/create"]')->count()])->toBe([$qui, 1, $cree ? 1 : 0]);
        filtrerLaTvaAVerifier($onglet);
        expect([$qui, lignesVers($onglet, '/facture-achats/'.$marquee->getRouteKey()), $onglet->element('.fi-ta-row')->count()])->toBe([$qui, 1, 1]);
        $onglet->aller($liste.'/'.$marquee->getRouteKey())->voir('Calcul depuis les lignes : 2,00 €');
        $onglet->seDeconnecter();
    }
});
