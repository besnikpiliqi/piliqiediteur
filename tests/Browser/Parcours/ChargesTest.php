<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\TypeDeChargeDeLaSociete;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Services\TypesDeCharge;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\Browser\Support\Navigation;
use Tests\Browser\TestCaseDuNavigateur;
use Tests\Fixtures\FichiersDeTest;

/*
 * Les charges de la société dans le navigateur (décisions de l'éditeur du
 * 14/11/2026, modèles C1 et C2, lot CHARGES) : la page « Charges » de
 * l'espace de la société, la page d'un client dans l'espace du cabinet,
 * « Charges de la société » de l'expert-comptable déclaré et « Charges du
 * magasin » du responsable coché, chacune avec le tableau « type de charge
 * × mois », la liste et ses gestes : déposer et saisir une pièce, valider,
 * renvoyer au client, corriger, voir la pièce, supprimer, gérer les types ;
 * puis l'« À faire » et la TVA à payer, réelle et estimée, du tableau de
 * bord. Les charges que le test prépare passent par la vraie action du
 * domaine, avec leur pièce.
 */

beforeEach(function () {
    Storage::fake(PiecesDesCharges::DISQUE);
    Storage::fake(FileUploadConfiguration::disk());
});

/**
 * Une pièce de charge sur le poste de la personne, prête à être choisie.
 */
function pieceDeChargeSurLePoste(string $nom, string $texte = 'Quittance de loyer de mars 2026'): string
{
    $chemin = storage_path('framework/testing/charges-'.getmypid().'/'.$nom);
    File::ensureDirectoryExists(dirname($chemin));
    File::put($chemin, FichiersDeTest::pdf($texte));

    return $chemin;
}

/**
 * Une charge déposée par ce compte, par la vraie action, avec sa pièce :
 * un loyer de mars 2026 de 1 200,00 € HT à 20 %, sauf ce que les champs
 * changent ; aucun contexte n'est actif ensuite.
 *
 * @param  array<string, mixed>  $champs
 */
function chargeDeposee(User $auteur, Societe $societe, array $champs = []): Charge
{
    app(TenantContext::class)->clear();
    $saisie = SaisieDeCharge::duFormulaire([
        'type' => 'piliqi:loyer',
        'fournisseur' => 'SCI des Halles',
        'numero_piece' => 'Q-2026-03',
        'date_piece' => '2026-03-01',
        'lignes' => [['taux' => '2000', 'ht' => '1200,00']],
        ...$champs,
    ]);
    $charge = app(DeposerUneChargeAction::class)->handle($auteur, $societe, $saisie, new PieceDeposee('quittance.pdf', FichiersDeTest::pdf('Quittance de loyer')));
    app(TenantContext::class)->clear();

    return Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey());
}

function chargeRelue(Charge $charge): Charge
{
    return Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey());
}

/**
 * Clique sur le bouton de ce libellé dans la ligne de la liste qui porte
 * ce texte (le n° de la pièce, le fournisseur).
 */
function cliquerDansLaLigneDeLaCharge(Navigation $onglet, string $texte, string $libelle): Navigation
{
    return $onglet->cliquer('tr:has-text('.Navigation::texte($texte).') >> '.Navigation::bouton($libelle));
}

/**
 * Le HT de la ligne de ce rang, tapé puis quitté (le calcul de la TVA et
 * du TTC part quand le champ perd la main), puis la TVA et le TTC que la
 * fenêtre a calculés.
 *
 * @return array{0: string, 1: string}
 */
function taperLeHt(Navigation $onglet, int $rang, string $ht): array
{
    $champ = fn (string $nom): string => Navigation::FENETRE.' [id*=".lignes."][id$=".'.$nom.'"] >> nth='.$rang;
    $onglet->remplirLaLigne('lignes', $rang, 'ht', $ht)
        ->geste(fn () => $onglet->element($champ('ht'))->press('Tab'), 'la sortie du champ HT');

    return [$onglet->element($champ('tva'))->inputValue(), $onglet->element($champ('ttc'))->inputValue()];
}

/**
 * La case du tableau des mois de ce type pour mars 2026 : son état, et si
 * sa couleur est celle de cet état.
 */
function caseDeMars(string $type): string
{
    return 'document.querySelector(\'tr[data-type-de-charge="'.$type.'"] [data-mois="2026-03"]\')';
}

/**
 * Le montant en centimes d'un chiffre clé affiché (« 1 234,56 € »,
 * « −12,00 € »).
 */
function centimesAffiches(Navigation $onglet, string $selecteur): int
{
    $texte = (string) $onglet->page()->evaluate('() => document.querySelector('.json_encode($selecteur).').textContent');
    $nombre = str_replace([',', '−'], ['.', '-'], (string) preg_replace('/[^0-9,\-−]/u', '', explode(':', $texte)[1] ?? $texte));

    return (int) round(((float) $nombre) * 100);
}

test('le propriétaire dépose une quittance de loyer avec ses chiffres, voit la case orange, filtre la liste par la case et télécharge la pièce', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->cliquerSurLeLien('Charges')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la page des charges ne s’ouvre pas')
        ->voir('Ce qui est déjà saisi')
        ->attendreQue('document.querySelector(\'tr[data-type-de-charge="piliqi:loyer"]\') !== null', 'le loyer, type habituel, n’a pas sa ligne sans charge')
        ->cliquerSur('Ajouter une charge')
        ->envoyerLeFichier(pieceDeChargeSurLePoste('quittance-mars.pdf'))
        ->choisirDansLaListe('Type de charge', 'Loyer')
        ->remplir('Fournisseur', 'SCI des Halles')
        ->remplir('N° de la pièce', 'Q-2026-03')
        ->remplir('Date de la pièce', '2026-03-01');

    expect(taperLeHt($onglet, 0, '1200,00'))->toBe(['240,00', '1440,00']);

    $onglet->soumettre('Enregistrer (à valider par le comptable)')
        ->voir('Charge enregistrée.')
        ->attendreQue(caseDeMars('piliqi:loyer').'?.dataset.case === "a_valider"', 'la case du loyer de mars n’est pas « à valider »')
        ->attendreQue(caseDeMars('piliqi:loyer').'.className.includes("bg-warning-100")', 'la case « à valider » n’est pas orange');

    $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();
    expect([$charge->etat, $charge->origine, $charge->mois_tva, $charge->total_ht, $charge->total_tva, $charge->total_ttc])
        ->toBe([EtatDeLaCharge::AValider, OrigineDeLaCharge::Client, '2026-03', 120000, 24000, 144000]);

    $onglet->cliquer('tr[data-type-de-charge="piliqi:loyer"] [data-mois="2026-03"]')
        ->voir('Liste filtrée sur une case, mars 2026.')
        ->voir('Q-2026-03')
        ->cliquerSur('Voir toute la période')
        ->attendreQue('! document.body.innerText.includes("Liste filtrée sur une case")', 'la liste reste filtrée sur la case');

    cliquerDansLaLigneDeLaCharge($onglet, 'Q-2026-03', 'Voir la pièce');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    expect([$fichiers[0]['nom'], $fichiers[0]['type'], base64_decode($fichiers[0]['contenu'])])
        ->toBe(['quittance-mars.pdf', 'application/pdf', FichiersDeTest::pdf('Quittance de loyer de mars 2026')]);
});

test('le collaborateur à qui le client est confié ouvre ses charges depuis la liste des clients, voit la case orange, valide, et la case devient verte', function () {
    $societe = $this->societeComplete();
    $charge = chargeDeposee($societe->proprietaire, $societe->societe);

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients')->attendre()->verifier()
        ->cliquerSurLeLien('Charges')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la page des charges du client ne s’ouvre pas')
        ->attendreQue(caseDeMars('piliqi:loyer').'?.dataset.case === "a_valider"', 'le collaborateur ne voit pas la case orange du loyer');

    cliquerDansLaLigneDeLaCharge($onglet, 'Q-2026-03', 'Valider')
        ->soumettre('Valider')
        ->voir('Charge validée.')
        ->attendreQue(caseDeMars('piliqi:loyer').'?.dataset.case === "validee"', 'la case du loyer n’est pas validée')
        ->attendreQue(caseDeMars('piliqi:loyer').'.className.includes("bg-success-100")', 'la case validée n’est pas verte')
        ->voir('Validée par le cabinet');

    expect([chargeRelue($charge)->etat, chargeRelue($charge)->validee_par])->toBe([EtatDeLaCharge::Validee, ValideePar::Cabinet]);
});

test('le propriétaire du cabinet renvoie une pièce au client avec un mot, le propriétaire la voit dans son « À faire » et en réécrit les chiffres', function () {
    $societe = $this->societeComplete();
    $charge = chargeDeposee($societe->proprietaire, $societe->societe);
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();

    $cabinet = $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid)->attendre()->verifier()
        ->cliquerSurLeLien('Charges')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la page des charges ne s’ouvre pas depuis la fiche du client');
    cliquerDansLaLigneDeLaCharge($cabinet, 'Q-2026-03', 'Renvoyer au client')
        ->remplir('Votre mot au client', 'Pièce illisible')
        ->soumettre('Renvoyer')
        ->voir('Charge renvoyée au client.')
        ->attendreQue(caseDeMars('piliqi:loyer').'?.dataset.case === "renvoyee"', 'la case du loyer n’est pas « renvoyée »');
    $cabinet->seDeconnecter();

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->voir('1 charge renvoyée par votre comptable')
        ->cliquer('a:has-text("Corriger")')
        ->attendreQue('location.pathname.endsWith("/charges")', 'le bouton de l’« À faire » ne mène pas à la page des charges')
        ->attendreQue('document.querySelector("[data-filtre-vue]").value === "renvoyees"', 'la page ne s’ouvre pas sur les charges renvoyées')
        ->voir('Mot du comptable : Pièce illisible');
    cliquerDansLaLigneDeLaCharge($onglet, 'Q-2026-03', 'Écrire les chiffres');
    expect(taperLeHt($onglet, 0, '1250,00'))->toBe(['250,00', '1500,00']);
    $onglet->soumettre('Enregistrer (à valider par le comptable)')
        ->voir('Chiffres enregistrés.');

    expect([chargeRelue($charge)->etat, chargeRelue($charge)->total_ht])->toBe([EtatDeLaCharge::AValider, 125000]);
});

test('le collaborateur ajoute lui-même une charge, validée aussitôt, à son mois de TVA, puis la corrige avec un motif', function () {
    $societe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid.'/charges')->attendre()->verifier()
        ->cliquerSur('Ajouter une charge')
        ->envoyerLeFichier(pieceDeChargeSurLePoste('edf-mars.pdf', 'Facture EDF de mars 2026'))
        ->choisirDansLaListe('Type de charge', 'Électricité')
        ->remplir('Fournisseur', 'EDF')
        ->remplir('N° de la pièce', 'EDF-03')
        ->remplir('Date de la pièce', '2026-02-27')
        ->remplir('Date de paiement', '2026-03-05')
        ->voir('Proposé : mars 2026.')
        ->remplir('Mois de TVA', '2026-03')
        ->choisirDansLaLigne('lignes', 0, 'taux', '5,5 %');

    expect(taperLeHt($onglet, 0, '100,00'))->toBe(['5,50', '105,50']);

    $onglet->soumettre('Enregistrer (validée)')
        ->voir('Charge enregistrée.')
        ->attendreQue(caseDeMars('piliqi:electricite').'?.dataset.case === "validee"', 'la charge du comptable n’est pas validée aussitôt');

    $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();
    expect([$charge->etat, $charge->origine, $charge->validee_par, $charge->date_paiement, $charge->mois_tva, $charge->total_tva_deductible])
        ->toBe([EtatDeLaCharge::Validee, OrigineDeLaCharge::Comptable, ValideePar::Cabinet, '2026-03-05', '2026-03', 550]);

    cliquerDansLaLigneDeLaCharge($onglet, 'EDF-03', 'Corriger');
    expect(taperLeHt($onglet, 0, '110,00'))->toBe(['6,05', '116,05']);
    $onglet->remplirLaLigne('lignes', 0, 'tva_deductible', '6,05')
        ->remplir('Motif de la correction', 'HT mal recopié')
        ->soumettre('Corriger')
        ->voir('Charge corrigée.');

    expect([chargeRelue($charge)->total_ht, chargeRelue($charge)->total_tva_deductible, chargeRelue($charge)->corrections])->toBe([11000, 605, 1]);
});

test('l’expert-comptable déclaré voit « 1 charge à valider » sur « Tableau de bord de la société », ouvre « Charges de la société » et valide', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $marc = $this->expertComptable($magasin);
    $this->travelTo(self::instantDuTest());
    $charge = chargeDeposee($this->proprietaireDe($magasin), $magasin->societe()->firstOrFail());

    $onglet = $this->seConnecter($marc)
        ->cliquerSurLeLien('Tableau de bord de la société')
        ->voir('1 charge à valider')
        ->cliquerSurLeLien('Valider')
        ->attendreQue('location.pathname.endsWith("/charges-de-la-societe")', 'le lien ne mène pas à « Charges de la société »')
        ->attendreQue('document.querySelector("[data-filtre-vue]").value === "a_valider"', 'la page ne s’ouvre pas sur les charges à valider');
    cliquerDansLaLigneDeLaCharge($onglet, 'Q-2026-03', 'Valider')
        ->soumettre('Valider')
        ->voir('Charge validée.')
        ->attendreQue(caseDeMars('piliqi:loyer').'?.dataset.case === "validee"', 'la case du loyer n’est pas validée');

    expect([chargeRelue($charge)->etat, chargeRelue($charge)->validee_par])->toBe([EtatDeLaCharge::Validee, ValideePar::ExpertComptable]);
});

test('le responsable à qui le propriétaire coche « Charges : ajouter » ajoute un plein de carburant de son magasin, pour un véhicule utilitaire', function () {
    $societe = $this->societeComplete();
    $lea = $this->membre($societe->magasin, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER], ['name' => 'Léa Roux']);
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($lea)
        ->cliquerSurLeLien('Charges du magasin')
        ->attendreQue('location.pathname.endsWith("/charges-du-magasin")', 'la page des charges du magasin ne s’ouvre pas')
        ->cliquerSur('Ajouter une charge')
        ->envoyerLeFichier(pieceDeChargeSurLePoste('ticket-carburant.pdf', 'Ticket de carburant'))
        ->choisirDansLaListe('Type de charge', 'Carburant')
        ->remplir('Fournisseur', 'Station du Port')
        ->remplir('Date de la pièce', '2026-03-09');
    $onglet->geste(fn () => $onglet->element(Navigation::FENETRE.' >> label:has-text("Véhicule utilitaire (100 %)") >> input[type=checkbox]')->check(), 'la case du véhicule utilitaire');

    expect(taperLeHt($onglet, 0, '50,00'))->toBe(['10,00', '60,00']);

    // La TVA recopiée de la pièce, à un centime de celle que PILIQI
    // calcule : le TTC suit, sans question.
    $ttc = Navigation::FENETRE.' [id*=".lignes."][id$=".ttc"]';
    $onglet->remplirLaLigne('lignes', 0, 'tva', '10,01')
        ->geste(fn () => $onglet->element(Navigation::FENETRE.' [id*=".lignes."][id$=".tva"]')->press('Tab'), 'la sortie du champ TVA')
        ->attendreQue('document.querySelector('.json_encode($ttc).').value === "60,01"', 'le TTC ne suit pas la TVA recopiée');

    $onglet->soumettre('Enregistrer (à valider par le comptable)')
        ->voir('Charge enregistrée.')
        ->attendreQue(caseDeMars('piliqi:carburant').'?.dataset.case === "a_valider"', 'la case du carburant n’est pas « à valider »');

    $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();
    expect([$charge->magasin_id, $charge->deposee_par, $charge->vehicule_utilitaire, $charge->total_tva, $charge->total_ttc, $charge->total_tva_deductible, $charge->tva_a_verifier])
        ->toBe([$societe->magasin->getKey(), $lea->getKey(), true, 1001, 6001, 1001, false]);
});

test('l’avertissement de doublon s’affiche dès que le type et le mois sont choisis, et le doublon probable demande une confirmation', function () {
    $societe = $this->societeComplete();
    chargeDeposee($societe->proprietaire, $societe->societe, ['numero_piece' => null]);

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->aller('/societe/'.$societe->societe->uuid.'/charges')->attendre()->verifier()
        ->cliquerSur('Ajouter une charge')
        ->choisirDansLaListe('Type de charge', 'Loyer')
        ->remplir('Date de la pièce', '2026-03-02')
        ->voir('Une charge de ce type est déjà saisie pour mars 2026 :')
        ->voir('! À valider — SCI des Halles')
        ->remplir('Fournisseur', 'SCI des Halles');
    taperLeHt($onglet, 0, '1200,00');

    $onglet->soumettre('Enregistrer (à valider par le comptable)')
        ->attendreQue('document.querySelector(\''.Navigation::FENETRE.' input[type=checkbox][id$="confirmer"]\') !== null', 'le doublon probable ne demande pas de confirmation');
    expect(Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->count())->toBe(1);

    $onglet->geste(fn () => $onglet->element(Navigation::FENETRE.' input[type=checkbox][id$="confirmer"]')->check(), 'la confirmation du doublon')
        ->soumettre('Enregistrer (à valider par le comptable)')
        ->voir('Charge enregistrée.');
    expect(Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->count())->toBe(2);
});

test('le tableau de bord de la société montre la TVA à payer réelle, avec les charges validées, et l’estimation avec les charges à valider', function () {
    $societe = $this->societeComplete();
    $tableau = '/societe/'.$societe->societe->uuid;
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelector(\'[data-tva="reelle"]\') !== null', 'la TVA à payer n’est pas affichée')
        ->attendreQue('document.querySelector(\'[data-tva="estimation"]\') === null', 'une estimation paraît sans charge à valider');
    $sansLesCharges = centimesAffiches($onglet, '[data-tva="reelle"]');

    chargeDeposee($societe->collaborateur, $societe->societe, ['type' => 'piliqi:electricite', 'numero_piece' => 'EDF-03', 'fournisseur' => 'EDF', 'lignes' => [['taux' => '2000', 'ht' => '100,00']]]);
    chargeDeposee($societe->proprietaire, $societe->societe);

    $onglet->aller($tableau)->attendre()->verifier()
        ->attendreQue('document.querySelector(\'[data-tva="estimation"]\') !== null', 'l’estimation avec les charges à valider n’est pas affichée')
        ->voir('estimation avec les charges à valider');

    expect([centimesAffiches($onglet, '[data-tva="reelle"]'), centimesAffiches($onglet, '[data-tva="estimation"]')])
        ->toBe([$sansLesCharges - 2000, $sansLesCharges - 2000 - 24000]);
});

test('sans comptable, le propriétaire valide lui-même, supprime une charge, choisit les types habituels, ajoute un type et filtre la liste', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $proprietaire = $this->proprietaireDe($magasin);
    $this->choisirSonMotDePasse($proprietaire);
    $this->travelTo(self::instantDuTest());
    $societeDuTest = $magasin->societe()->firstOrFail();
    $aValider = chargeDeposee($proprietaire, $societeDuTest, ['numero_piece' => 'Q-1']);
    $aSupprimer = chargeDeposee($proprietaire, $societeDuTest, ['type' => 'piliqi:telephone_internet', 'fournisseur' => 'Opérateur', 'numero_piece' => 'T-2']);

    $onglet = $this->seConnecter($proprietaire->refresh(), panneau: 'societe')
        ->aller('/societe/'.$societeDuTest->uuid.'/charges')->attendre()->verifier();
    cliquerDansLaLigneDeLaCharge($onglet, 'Q-1', 'Valider')
        ->soumettre('Valider')
        ->voir('Charge validée.')
        ->voir('Validée par le propriétaire');
    cliquerDansLaLigneDeLaCharge($onglet, 'T-2', 'Supprimer')
        ->soumettre('Supprimer')
        ->voir('Charge supprimée.');
    expect([chargeRelue($aValider)->validee_par, Charge::query()->withoutGlobalScopes()->whereKey($aSupprimer->getKey())->exists()])->toBe([ValideePar::Proprietaire, false]);

    $onglet->cliquerSur('Types de charge')
        ->geste(fn () => $onglet->element(Navigation::FENETRE.' >> label:has-text("Assurances") >> input[type=checkbox]')->check(), 'la case des assurances')
        ->soumettre('Enregistrer')
        ->voir('Types de charge enregistrés.')
        ->attendreQue('document.querySelector(\'tr[data-type-de-charge="piliqi:assurances"]\') !== null', 'les assurances, cochées habituelles, n’ont pas leur ligne');
    $onglet->cliquerSur('Ajouter un type')
        ->remplir('Nom du type', 'Ménage des locaux')
        ->soumettre('Ajouter')
        ->voir('Type ajouté.');
    expect(TypeDeChargeDeLaSociete::query()->withoutGlobalScopes()->where('societe_id', $societeDuTest->getKey())->pluck('nom')->all())->toBe(['Ménage des locaux']);
    expect(in_array(TypeDeCharge::Assurances, app(TypesDeCharge::class)->habituels($societeDuTest->refresh()), true))->toBeTrue();

    $onglet->geste(fn () => $onglet->element('[data-filtre-vue]')->selectOption('a_valider'), 'le choix de la vue « à valider »')
        ->voir('Aucune charge sur cette période')
        ->geste(fn () => $onglet->element('[data-filtre-vue]')->selectOption('toutes'), 'le retour à toutes les charges')
        ->voir('Q-1')
        ->geste(fn () => $onglet->element('[data-filtre-periode]')->selectOption('civile:2025'), 'le choix de l’année 2025')
        ->voir('Aucune charge sur cette période');
});

test('un administrateur à qui le propriétaire coche « Charges : ajouter » ajoute une charge de toute la société auprès d’un fournisseur établi hors de France, puis filtre par magasin', function () {
    $societe = $this->societeComplete();
    $nina = $this->administrateur($societe->magasin, [$societe->magasin->id], [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::CHARGES_AJOUTER], 'Nina Faure');
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($nina, panneau: 'societe')
        ->cliquerSurLeLien('Charges')
        ->attendreQue('location.pathname.endsWith("/charges")', 'la page des charges ne s’ouvre pas pour l’administrateur coché')
        ->cliquerSur('Ajouter une charge')
        ->envoyerLeFichier(pieceDeChargeSurLePoste('logiciel-mars.pdf', 'Facture de logiciel'))
        ->choisirDansLaListe('Type de charge', 'Abonnements et logiciels')
        ->remplir('Fournisseur', 'Logiciels Ltd (Irlande)')
        ->remplir('N° de la pièce', 'IE-0310')
        ->remplir('Date de la pièce', '2026-03-03')
        ->choisir('Magasin concerné', 'Toute la société');
    $onglet->geste(fn () => $onglet->element(Navigation::FENETRE.' >> label:has-text("Fournisseur établi hors de France") >> input[type=checkbox]')->check(), 'la case du fournisseur établi hors de France');

    // Autoliquidée, la ligne a pour TTC son HT : sa TVA ne se paie pas au
    // fournisseur.
    expect(taperLeHt($onglet, 0, '100,00'))->toBe(['20,00', '100,00']);

    $onglet->soumettre('Enregistrer (à valider par le comptable)')
        ->voir('Charge enregistrée.')
        ->voir('IE-0310');

    $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();
    expect([$charge->magasin_id, $charge->fournisseur_hors_france, $charge->total_tva, $charge->total_ttc])->toBe([null, true, 2000, 10000]);

    $onglet->geste(fn () => $onglet->element('[data-filtre-magasin]')->selectOption((string) $societe->magasin->uuid), 'le choix du magasin de sa portée')
        ->voir('Aucune charge sur cette période')
        ->nePasVoir('Boutique de la Gare');
});

test('le propriétaire du cabinet voit sur son tableau de bord la charge à valider de son client et ouvre ses charges par ce lien', function () {
    $societe = $this->societeComplete();
    chargeDeposee($societe->proprietaire, $societe->societe);

    $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet')
        ->attendreQue('document.querySelector("[data-charges-a-valider=\"1\"]") !== null', 'la colonne des charges du client ne compte pas sa charge à valider')
        ->cliquerSurLeLien('1 charge à valider')
        ->attendreQue('location.pathname.endsWith("/charges")', 'le lien ne mène pas aux charges du client')
        ->attendreQue('document.querySelector("[data-filtre-vue]").value === "a_valider"', 'la page ne s’ouvre pas sur les charges à valider')
        ->voir('Q-2026-03');
});

/**
 * Pour ce rôle qui voit une page des charges, préparé par les aides du
 * test : le compte, son panneau, l'adresse de sa page, le libellé du
 * bouton qui enregistre, l'état de la charge enregistrée et la société des
 * charges.
 *
 * @return array{0: User, 1: string, 2: string, 3: string, 4: EtatDeLaCharge, 5: Societe}
 */
function pageDesChargesDe(TestCaseDuNavigateur $test, string $role): array
{
    return (function () use ($role): array {
        if ($role === 'expert_comptable') {
            $this->travelTo(self::aParis('2026-03-09 08:00:00'));
            $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
            $marc = $this->expertComptable($magasin);
            $this->travelTo(self::instantDuTest());

            return [$marc, 'app', '/app/'.$magasin->uuid.'/charges-de-la-societe', 'Enregistrer (validée)', EtatDeLaCharge::Validee, $magasin->societe()->firstOrFail()];
        }

        $societe = $this->societeComplete();
        $client = '/cabinet/'.$societe->cabinet->uuid.'/clients/'.LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole()->uuid.'/charges';
        $espace = '/societe/'.$societe->societe->uuid.'/charges';
        $aValider = 'Enregistrer (à valider par le comptable)';
        $preparee = match ($role) {
            'proprietaire' => [$societe->proprietaire, 'societe', $espace, $aValider, EtatDeLaCharge::AValider],
            'administrateur' => [$this->administrateur($societe->magasin, null, [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::CHARGES_AJOUTER], 'Nina Faure'), 'societe', $espace, $aValider, EtatDeLaCharge::AValider],
            'responsable' => [$this->membre($societe->magasin, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER], ['name' => 'Léa Roux']), 'app', '/app/'.$societe->magasin->uuid.'/charges-du-magasin', $aValider, EtatDeLaCharge::AValider],
            'proprietaire_du_cabinet' => [$societe->proprietaireDuCabinet, 'cabinet', $client, 'Enregistrer (validée)', EtatDeLaCharge::Validee],
            'collaborateur' => [$societe->collaborateur, 'cabinet', $client, 'Enregistrer (validée)', EtatDeLaCharge::Validee],
        };
        app(TenantContext::class)->clear();

        return [...$preparee, $societe->societe];
    })->call($test);
}

test('chaque rôle qui voit une page des charges y ajoute une charge avec sa pièce', function (string $role) {
    [$compte, $panneau, $page, $bouton, $etat, $societeDuCompte] = pageDesChargesDe($this, $role);

    $onglet = $this->seConnecter($compte, panneau: $panneau)
        ->aller($page)->attendre()->verifier()
        ->cliquerSur('Ajouter une charge')
        ->envoyerLeFichier(pieceDeChargeSurLePoste('assurance.pdf', 'Avis d’échéance d’assurance'))
        ->choisirDansLaListe('Type de charge', 'Assurances')
        ->remplir('Fournisseur', 'Assurances du Centre')
        ->remplir('N° de la pièce', 'A-2026')
        ->remplir('Date de la pièce', '2026-03-04');
    taperLeHt($onglet, 0, '300,00');
    $onglet->soumettre($bouton)
        ->voir('Charge enregistrée.')
        ->attendreQue(caseDeMars('piliqi:assurances').'?.dataset.case === '.json_encode($etat->value), 'la case de la charge ajoutée n’a pas l’état attendu');

    $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $societeDuCompte->getKey())->sole();
    expect([$charge->etat, $charge->deposee_par, $charge->total_ttc])->toBe([$etat, $compte->getKey(), 36000]);
})->with([
    'le propriétaire' => ['proprietaire'],
    'l’administrateur coché' => ['administrateur'],
    'le responsable coché' => ['responsable'],
    'le propriétaire du cabinet' => ['proprietaire_du_cabinet'],
    'le collaborateur confié' => ['collaborateur'],
    'l’expert-comptable déclaré' => ['expert_comptable'],
]);
