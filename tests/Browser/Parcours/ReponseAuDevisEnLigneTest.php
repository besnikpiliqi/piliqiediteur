<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Services\DroitDeRetractation;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\Browser\Support\Navigation;
use Tests\Browser\TestCaseDuNavigateur;

/*
 * La réponse du client à son devis en ligne et la transformation d'un
 * devis accepté en facture (lot DV, décisions de l'éditeur du
 * 02/11/2026), cliquées dans un vrai navigateur : le client, sans compte,
 * accepte par son nom tapé et « Bon pour accord », ou refuse, et le lien
 * ne vaut plus ensuite pour répondre, une acceptation n'y montrant plus que
 * sa confirmation ; un lien faux ou caduc ne dit que « Ce lien n'est
 * plus valable. » ; le responsable et le vendeur autorisé transforment le
 * devis accepté, la facture brouillon dit d'où elle vient et mène au devis
 * ; le propriétaire, l'observateur et un vendeur sans la création de
 * facture voient le bouton désactivé sur la fiche, avec son explication,
 * et lisent cette explication dans la fenêtre du bouton grisé de la
 * liste ; le propriétaire, l'administrateur, le responsable et le vendeur retirent
 * le lien ; la ligne d'un devis montre le stock du produit, et
 * l'acceptation par la liste nomme le produit qui manque ; le PDF de la
 * facture transformée dit d'après quel devis elle est établie ; le client
 * ne trouve aucun PDF sur la page de son lien avant de répondre, puis
 * télécharge le devis signé depuis la confirmation de son acceptation
 * (demande de l'éditeur du 14/11/2026) ; le particulier lit son droit de
 * rétractation et demande que la prestation commence avant la fin du
 * délai, le professionnel ne voit ni l'un ni l'autre (T86) ; la page du
 * lien montre tout ce que le PDF du devis imprime, et un second envoi de
 * l'acceptation montre encore sa confirmation ; le
 * demandeur de l'envoi et le propriétaire lisent la réponse du client
 * dans la cloche, en texte, puis ouvrent la fiche du devis par « Voir le
 * devis ».
 */

/**
 * Un devis envoyé par e-mail par le responsable, à un particulier ou à un
 * professionnel, avec d'autres attributs s'il y a lieu (conditions de
 * paiement, notes), et le jeton du lien de l'e-mail parti.
 *
 * @param  array<string, string>  $attributs
 * @return array{0: Devis, 1: string}
 */
function devisEnvoyeParEMail(Magasin $magasin, User $responsable, bool $professionnel = false, array $attributs = []): array
{
    if (! Mail::getFacadeRoot() instanceof MailFake) {
        Mail::fake();
    }

    app(TenantContext::class)->set($magasin);
    $produit = Produit::factory()->create(['nom' => 'Câble tressé', 'prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
    $client = $professionnel ? Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']) : Client::factory()->create(['nom' => 'Paul Durand']);
    $devis = app(CreerDevisAction::class)->handle($responsable, $client, [['produit_id' => $produit->id, 'quantite' => 2]], ['date_validite' => '2026-12-31', ...$attributs]);
    $envoi = app(EnvoyerDocumentAction::class)->handle($responsable, $devis, 'paul@client.fr', null, (string) Str::uuid());
    $jeton = null;
    Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($envoi, &$jeton): bool {
        $jeton = $mail->envoi->is($envoi) && preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) === 1 ? $trouve[1] : $jeton;

        return $jeton !== null;
    });
    app(TenantContext::class)->clear();

    return [$devis->fresh(), (string) $jeton];
}

/**
 * Un devis accepté, prêt à être transformé.
 */
function devisAccepteDuMagasin(Magasin $magasin, User $auteur): Devis
{
    app(TenantContext::class)->set($magasin);
    $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
    $devis = app(EmettreDevisAction::class)->handle($auteur, app(CreerDevisAction::class)->handle($auteur, Client::factory()->create(['nom' => 'Paul Durand']), [['produit_id' => $produit->id, 'quantite' => 3]]));
    $devis = app(AccepterDevisAction::class)->handle($auteur, $devis);
    app(TenantContext::class)->clear();

    return $devis;
}

/**
 * Un vendeur du magasin à qui le propriétaire n'a coché que la
 * consultation des devis et des factures : il ne crée pas de facture.
 */
function vendeurSansLaCreationDeFacture(TestCaseDuNavigateur $test, Magasin $magasin): User
{
    $vendeur = (fn (): User => $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::DEVIS_VIEW, PermissionRegistry::FACTURE_VIEW], ['name' => 'Valérie Blanc']))->call($test);
    app(TenantContext::class)->clear();

    return $vendeur;
}

test('le client particulier lit son droit de rétractation, accepte son devis en ligne en tapant son nom, en cochant « Bon pour accord » et la demande de début anticipé, puis le lien ne montre que la confirmation', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    [$devis, $jeton] = devisEnvoyeParEMail($magasin, self::responsableDeTest($magasin));

    $onglet = $this->naviguer('/devis/'.$jeton)
        ->voir("Devis {$devis->numero}")
        ->voir('Ma Boutique')
        ->voir('120,00 €')
        ->cliquer('details.retractation > summary')
        ->voir('dans un délai de quatorze jours')
        ->voir(DroitDeRetractation::TITRE_DU_FORMULAIRE)
        ->remplir('Votre nom et votre prénom', 'Paul Durand')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquer('input[name="debut_avant_la_fin_du_delai"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Paul Durand')
        ->voir("Votre acceptation du devis {$devis->numero} a été enregistrée le 12/11/2026")
        ->voir(DroitDeRetractation::reponseALaDemande(true))
        ->voir('Télécharger le devis signé en PDF');

    app(TenantContext::class)->set($magasin);
    $reponse = ReponseDuDevis::query()->sole();
    expect($devis->fresh()->statut)->toBe(StatutDevis::Accepte)
        ->and([$reponse->signataire, $reponse->debut_avant_la_fin_du_delai, $reponse->reference])->toBe(['Paul Durand', true, 'ACC-MABOUT-2026-000001']);
    app(TenantContext::class)->clear();

    $onglet->aller('/devis/'.$jeton)
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Paul Durand')
        ->nePasVoir('Accepter le devis')
        ->nePasVoir('Refuser le devis');
});

test('le client lit sur la page de son lien tout ce que le PDF du devis imprime, puis l’accepte, et un second envoi de son acceptation montre encore la confirmation', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    [$devis, $jeton] = devisEnvoyeParEMail($magasin, self::responsableDeTest($magasin), attributs: ['conditions_paiement' => 'Acompte de 30 % à la commande', 'notes' => 'Intervention prévue le matin entre 8 h et 10 h']);

    // Un second onglet ouvert sur le même lien avant l'acceptation garde
    // son formulaire : son envoi est celui d'un double clic ou d'un
    // formulaire renvoyé par le navigateur.
    $second = $this->naviguer('/devis/'.$jeton)->voir('Accepter le devis');

    $this->naviguer('/devis/'.$jeton)
        ->voir("N° {$devis->numero}")
        ->voir('Établi pour')
        ->voir('Paul Durand')
        ->voir('Détail des articles et prestations')
        ->voir('20 %')
        ->voir('Récapitulatif TVA')
        ->voir('Montants calculés depuis les prix TTC')
        ->voir('Intervention prévue le matin entre 8 h et 10 h')
        ->voir('Acompte de 30 % à la commande')
        ->voir('RCS Lyon')
        ->nePasVoir('PU HT exact')
        ->remplir('Votre nom et votre prénom', 'Paul Durand')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Paul Durand');

    $second->remplir('Votre nom et votre prénom', 'Paul Durand')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Paul Durand')
        ->nePasVoir(LienDuDevis::PLUS_VALABLE);

    app(TenantContext::class)->set($magasin);
    expect(ReponseDuDevis::query()->count())->toBe(1);
    app(TenantContext::class)->clear();
});

test('le client professionnel accepte son devis en ligne sans information sur la rétractation ni demande de début anticipé', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    [$devis, $jeton] = devisEnvoyeParEMail($magasin, self::responsableDeTest($magasin), professionnel: true);

    $onglet = $this->naviguer('/devis/'.$jeton)
        ->voir("Devis {$devis->numero}")
        ->nePasVoir(DroitDeRetractation::TITRE);
    expect($onglet->element('input[name="debut_avant_la_fin_du_delai"]')->count())->toBe(0);

    $onglet->remplir('Votre nom et votre prénom', 'Claire Martin')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Claire Martin')
        ->nePasVoir(DroitDeRetractation::TITRE);

    app(TenantContext::class)->set($magasin);
    expect([ReponseDuDevis::query()->sole()->signataire, ReponseDuDevis::query()->sole()->debut_avant_la_fin_du_delai])->toBe(['Claire Martin', null]);
});

test('le client refuse son devis en ligne avec un motif, un second envoi de son refus le redit, puis le lien ne vaut plus', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    [$devis, $jeton] = devisEnvoyeParEMail($magasin, self::responsableDeTest($magasin));

    // Un second onglet ouvert sur le même lien avant le refus garde son
    // formulaire : son envoi est celui d'un double clic ou d'un formulaire
    // renvoyé par le navigateur.
    $second = $this->naviguer('/devis/'.$jeton)->voir('Refuser le devis');

    $onglet = $this->naviguer('/devis/'.$jeton)
        ->remplir('Motif (facultatif)', 'Trop cher pour nous.')
        ->cliquerSur('Refuser le devis')
        ->voir('Votre refus est enregistré');

    $second->remplir('Motif (facultatif)', 'Trop cher pour nous.')
        ->cliquerSur('Refuser le devis')
        ->voir('Votre refus est enregistré')
        ->nePasVoir(LienDuDevis::PLUS_VALABLE);

    app(TenantContext::class)->set($magasin);
    $reponse = ReponseDuDevis::query()->sole();
    expect([$reponse->reponse, $reponse->motif, $devis->fresh()->statut])->toBe([ReponseAuDevis::Refus, 'Trop cher pour nous.', StatutDevis::Refuse]);
    app(TenantContext::class)->clear();

    $this->tolerer(410, '/devis/');
    $onglet->aller('/devis/'.$jeton)->voir(LienDuDevis::PLUS_VALABLE);
});

test('un lien faux, ou rendu caduc par un nouvel envoi, ne dit que « Ce lien n’est plus valable. »', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    $responsable = self::responsableDeTest($magasin);
    [$devis, $premier] = devisEnvoyeParEMail($magasin, $responsable);
    app(TenantContext::class)->set($magasin);
    app(EnvoyerDocumentAction::class)->handle($responsable, $devis, 'paul@client.fr', null, (string) Str::uuid());
    app(TenantContext::class)->clear();

    $this->tolerer(410, '/devis/');
    $onglet = $this->naviguer('/devis/'.Str::random(64))->voir(LienDuDevis::PLUS_VALABLE)->nePasVoir('Ma Boutique');
    $this->tolerer(410, '/devis/');
    $onglet->aller('/devis/'.$premier)->voir(LienDuDevis::PLUS_VALABLE)->nePasVoir('Ma Boutique')->nePasVoir('Accepter le devis');
});

test('le responsable retire le lien de réponse d’un devis envoyé, et le client ne peut plus répondre', function () {
    $equipe = $this->societeComplete();
    [$devis, $jeton] = devisEnvoyeParEMail($equipe->magasin, $equipe->responsable);
    $onglet = $this->seConnecter($equipe->responsable);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
        ->voir('Le client peut répondre en ligne par le lien envoyé à paul@client.fr')
        ->cliquerSur('Retirer le lien de réponse')
        ->soumettre('Confirmer')
        ->voir('Le lien de réponse est retiré : le client ne peut plus répondre en ligne.')
        ->nePasVoir('Le client peut répondre en ligne par le lien envoyé à paul@client.fr');

    $this->tolerer(410, '/devis/');
    $onglet->aller('/devis/'.$jeton)->voir(LienDuDevis::PLUS_VALABLE);
});

test('le responsable transforme un devis accepté depuis sa fiche et arrive sur la facture brouillon, qui dit d’où elle vient et mène au devis', function () {
    $equipe = $this->societeComplete();
    $devis = devisAccepteDuMagasin($equipe->magasin, $equipe->responsable);
    $mention = "Établie d'après le devis n° {$devis->numero} accepté le ".$equipe->magasin->calendrier()->afficher($devis->accepte_le, 'd/m/Y');
    $onglet = $this->seConnecter($equipe->responsable);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
        ->cliquerSur('Transformer en facture')
        ->voir('Une facture brouillon est créée avec exactement les lignes, les prix, les remises et les taux de ce devis accepté')
        ->soumettre('Transformer en facture')
        ->attendreLaPage('^/app/'.$equipe->magasin->uuid.'/factures/[^/]+$')
        ->voir($mention)
        ->cliquerSurLeLien($mention)
        ->attendreLaPage('^/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey().'$')
        ->voir('Transformé en facture');

    app(TenantContext::class)->set($equipe->magasin);
    expect($devis->fresh()->statut)->toBe(StatutDevis::Converti)
        ->and(Facture::query()->where('devis_id', $devis->id)->sole()->cree_par)->toBe($equipe->responsable->id);
});

test('le vendeur transforme un devis accepté depuis la liste des devis', function () {
    $equipe = $this->societeComplete();
    $devis = devisAccepteDuMagasin($equipe->magasin, $equipe->responsable);
    $onglet = $this->seConnecter($equipe->vendeur);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis')
        ->cliquerSur('Transformer en facture')
        ->soumettre('Transformer en facture')
        ->attendreLaPage('^/app/'.$equipe->magasin->uuid.'/factures/[^/]+$')
        ->voir("Établie d'après le devis n° {$devis->numero}");

    app(TenantContext::class)->set($equipe->magasin);
    expect(Facture::query()->where('devis_id', $devis->id)->sole()->cree_par)->toBe($equipe->vendeur->id);
});

test('le propriétaire, l’observateur et un vendeur sans la création de facture voient « Transformer en facture » désactivé sur la fiche, avec son explication', function () {
    $equipe = $this->societeComplete();
    $devis = devisAccepteDuMagasin($equipe->magasin, $equipe->responsable);

    foreach ([$equipe->proprietaire, $equipe->observateur, vendeurSansLaCreationDeFacture($this, $equipe->magasin)] as $compte) {
        $onglet = $this->seConnecter($compte);
        $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
            ->voir(CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
            ->attendreQue('[...document.querySelectorAll("button")].some((bouton) => bouton.textContent.includes("Transformer en facture") && bouton.disabled)', 'le bouton « Transformer en facture » n\'est pas désactivé');
        $onglet->seDeconnecter();
    }

    app(TenantContext::class)->set($equipe->magasin);
    expect(Facture::query()->where('devis_id', $devis->id)->exists())->toBeFalse()
        ->and($devis->fresh()->statut)->toBe(StatutDevis::Accepte);
});

test('le responsable lit le stock disponible sous la quantité d’une ligne de devis, et l’avertissement quand elle le dépasse', function () {
    $equipe = $this->societeComplete();
    app(TenantContext::class)->set($equipe->magasin);
    Produit::factory()->create(['nom' => 'Lampe de chevet', 'reference' => 'LAMPE-CHEVET', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($equipe->responsable);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/create')
        ->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Lampe de chevet').')')
        ->cliquerSur('Ajouter le produit')
        ->voir('Stock disponible : 2.')
        ->remplirLaLigne('lignes', 0, 'quantite', '4')
        ->voir('La quantité dépasse le stock : le devis ne réserve rien, la facture le contrôlera.');
});

test('dans la liste des devis, le propriétaire, l’observateur et un vendeur sans la création de facture lisent pourquoi ils ne transforment pas un devis accepté', function () {
    $equipe = $this->societeComplete();
    $devis = devisAccepteDuMagasin($equipe->magasin, $equipe->responsable);

    foreach ([$equipe->proprietaire, $equipe->observateur, vendeurSansLaCreationDeFacture($this, $equipe->magasin)] as $compte) {
        $onglet = $this->seConnecter($compte);
        $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis')
            ->cliquer('.fi-ta-row:has-text('.Navigation::texte($devis->numero).') >> '.Navigation::bouton('Transformer en facture'))
            ->voir(CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT);
        expect($onglet->element(Navigation::FENETRE.' button[type=submit]')->count())->toBe(0);
        $onglet->fermerLaFenetre()
            ->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null', 'la fenêtre ne s\'est pas fermée');
        $onglet->seDeconnecter();
    }

    app(TenantContext::class)->set($equipe->magasin);
    expect(Facture::query()->where('devis_id', $devis->id)->exists())->toBeFalse()
        ->and($devis->fresh()->statut)->toBe(StatutDevis::Accepte);
});

test('le propriétaire, l’administrateur et le vendeur retirent le lien de réponse d’un devis envoyé, et le client ne peut plus répondre', function () {
    $equipe = $this->societeComplete();

    foreach ([$equipe->proprietaire, $equipe->administrateur, $equipe->vendeur] as $compte) {
        [$devis, $jeton] = devisEnvoyeParEMail($equipe->magasin, $equipe->responsable);
        $onglet = $this->seConnecter($compte);

        $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
            ->voir('Le client peut répondre en ligne par le lien envoyé à paul@client.fr')
            ->cliquerSur('Retirer le lien de réponse')
            ->soumettre('Confirmer')
            ->voir('Le lien de réponse est retiré : le client ne peut plus répondre en ligne.')
            ->nePasVoir('Le client peut répondre en ligne par le lien envoyé à paul@client.fr');

        $this->tolerer(410, '/devis/');
        $onglet->aller('/devis/'.$jeton)->voir(LienDuDevis::PLUS_VALABLE);
        app(TenantContext::class)->set($equipe->magasin);
        expect(LienDuDevis::query()->where('devis_id', $devis->id)->sole()->retire_par)->toBe($compte->id);
        app(TenantContext::class)->clear();
    }
});

test('le responsable accepte depuis la liste un devis dont un produit manque, et l’avis qui reste nomme ce produit', function () {
    $equipe = $this->societeComplete();
    app(TenantContext::class)->set($equipe->magasin);
    $lampe = Produit::factory()->create(['nom' => 'Lampe de chevet', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
    $devis = app(EmettreDevisAction::class)->handle($equipe->responsable, app(CreerDevisAction::class)->handle($equipe->responsable, Client::factory()->create(['nom' => 'Paul Durand']), [['produit_id' => $lampe->id, 'quantite' => 4]]));
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($equipe->responsable);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis')
        ->cliquer('.fi-ta-row:has-text('.Navigation::texte($devis->numero).') >> '.Navigation::bouton('Accepter'))
        ->soumettre('Confirmer')
        ->voir("Le devis {$devis->numero} est accepté, mais 1 produit n'a plus assez de stock.")
        ->voir('« Lampe de chevet » : 4 demandés, 2 en stock.');

    app(TenantContext::class)->set($equipe->magasin);
    expect($devis->fresh()->statut)->toBe(StatutDevis::Accepte);
});

test('le responsable lit sur le PDF de la facture transformée d’après quel devis elle est établie', function () {
    $equipe = $this->societeComplete();
    $devis = devisAccepteDuMagasin($equipe->magasin, $equipe->responsable);
    $facture = app(TenantContext::class)->runAs($equipe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($equipe->responsable, app(CreerFactureDepuisDevisAction::class)->handle($equipe->responsable, $devis)));
    $mention = "Établie d'après le devis n° {$devis->numero} accepté le ".$equipe->magasin->calendrier()->afficher($devis->accepte_le, 'd/m/Y');
    $onglet = $this->seConnecter($equipe->responsable);

    $onglet->aller('/app/'.$equipe->magasin->uuid.'/factures/'.$facture->getRouteKey())
        ->voir($mention)
        ->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();

    expect($fichiers)->toHaveCount(1);
    $textes = collect($this->pagesDuPdf(base64_decode($fichiers[0]['contenu'])))->flatMap(fn (array $page): array => array_column($page['textes'], 'texte'))->implode(' ');
    expect(preg_replace('/\s+/u', ' ', $textes))->toContain($mention);
});

test('le client ne trouve aucun PDF sur la page de son lien avant de répondre, puis télécharge le devis signé depuis la confirmation de son acceptation', function () {
    $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
    [$devis, $jeton] = devisEnvoyeParEMail($magasin, self::responsableDeTest($magasin));

    $onglet = $this->naviguer('/devis/'.$jeton)->voir("Devis {$devis->numero}")->nePasVoir('Télécharger');
    expect($onglet->element('a')->count())->toBe(0);

    $onglet->remplir('Votre nom et votre prénom', 'Paul Durand')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Devis accepté le 12/11/2026 à 10:00 par Paul Durand');
    $onglet->geste(fn () => $onglet->page()->evaluate('() => document.querySelector("a.pdf").click()'), 'le lien « Télécharger le devis signé en PDF »');
    $fichiers = $onglet->telechargements();

    expect($fichiers)->toHaveCount(1)
        ->and($fichiers[0]['type'])->toBe('application/pdf')
        ->and($fichiers[0]['debut'])->toStartWith('%PDF');
    $textes = preg_replace('/\s+/u', ' ', collect($this->pagesDuPdf(base64_decode($fichiers[0]['contenu'])))->flatMap(fn (array $page): array => array_column($page['textes'], 'texte'))->implode(' '));
    expect($textes)->toContain($devis->numero)
        ->toContain('Accepté en ligne')
        ->toContain('Le 12/11/2026 à 10:00 (heure de Paris)')
        ->toContain('Paul Durand')
        ->toContain('ACC-MABOUT-2026-000001');
});

test('le responsable qui a envoyé le devis et le propriétaire lisent dans la cloche le motif du client en texte, puis « Voir le devis » les mène à sa fiche', function () {
    $equipe = $this->societeComplete();
    [$devis, $jeton] = devisEnvoyeParEMail($equipe->magasin, $equipe->responsable);
    $piege = '<a href="https://evil.example/connexion" style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999;background:#fff">Votre session a expiré : reconnectez-vous ici</a>';
    app(RepondreAuDevisEnLigneAction::class)->refuser($jeton, $piege);

    foreach ([$equipe->responsable, $equipe->proprietaire] as $compte) {
        $onglet = $this->seConnecter($compte);
        $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis')
            ->cliquer('.fi-topbar-database-notifications-btn')
            ->voir("Devis {$devis->numero} refusé en ligne")
            ->voir($piege);
        expect($onglet->page()->evaluate('() => document.querySelectorAll(\'a[href^="https://evil"]\').length'))->toBe(0);

        $onglet->cliquerSurLeLien('Voir le devis')
            ->attendreLaPage('^/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey().'$')
            ->voir('Refusé en ligne le')
            ->voir('Le client, par sa réponse en ligne');
        $onglet->seDeconnecter();
    }
});

/*
 * Un produit n'a qu'une ligne d'un devis (décision de l'éditeur du
 * 26/10/2026, série 9) : la lampe, sur une seule ligne de 4, en demande
 * plus que ses 3 en stock.
 */
test('le responsable lit sur la fiche d’un devis envoyé le stock de chaque ligne de produit, et l’avertissement quand sa ligne en demande plus', function () {
    $equipe = $this->societeComplete();
    app(TenantContext::class)->set($equipe->magasin);
    $lampe = Produit::factory()->create(['nom' => 'Lampe de chevet', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 3]);
    $ampoule = Produit::factory()->create(['nom' => 'Ampoule LED', 'prix_vente_ttc' => 6, 'taux_tva' => 20, 'stock_quantite' => 10]);
    $devis = app(EmettreDevisAction::class)->handle($equipe->responsable, app(CreerDevisAction::class)->handle($equipe->responsable, Client::factory()->create(['nom' => 'Paul Durand']), [
        ['produit_id' => $lampe->id, 'quantite' => 4],
        ['produit_id' => $ampoule->id, 'quantite' => 2],
    ]));
    app(TenantContext::class)->clear();

    $this->seConnecter($equipe->responsable)
        ->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
        ->voir('Stock disponible : 10.')
        ->voir('Stock disponible : 3. La quantité dépasse le stock : le devis ne réserve rien, la facture le contrôlera.');
});

/*
 * Un devis refusé ou expiré ne peut plus être accepté, et son client ne
 * reçoit le PDF d'un devis qu'une fois accepté (demande de l'éditeur du
 * 14/11/2026) : « Envoyer par e-mail » disparaît de sa fiche, et reste sur
 * un devis accepté, qui repart avec son PDF.
 */
test('le propriétaire, le responsable et le vendeur ne trouvent plus « Envoyer par e-mail » sur un devis refusé ou expiré, et le trouvent sur un devis accepté', function () {
    $equipe = $this->societeComplete();
    app(TenantContext::class)->set($equipe->magasin);
    $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
    $emettre = fn (array $attributs = []): Devis => app(EmettreDevisAction::class)->handle($equipe->responsable, app(CreerDevisAction::class)->handle($equipe->responsable, Client::factory()->create(['nom' => 'Paul Durand', 'email' => 'paul@client.fr']), [['produit_id' => $produit->id, 'quantite' => 1]], $attributs));
    $refuse = app(RefuserDevisAction::class)->handle($equipe->responsable, $emettre());
    $expire = $emettre(['date_validite' => '2026-03-10']);
    $accepte = app(AccepterDevisAction::class)->handle($equipe->responsable, $emettre());
    app(TenantContext::class)->clear();
    $this->travelTo(self::aParis('2026-03-11 10:00:00'));

    foreach ([$equipe->proprietaire, $equipe->responsable, $equipe->vendeur] as $compte) {
        $onglet = $this->seConnecter($compte);

        foreach ([$refuse, $expire] as $devis) {
            $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$devis->getRouteKey())
                ->voir((string) $devis->numero)
                ->nePasVoir('Envoyer par e-mail');
        }

        $onglet->aller('/app/'.$equipe->magasin->uuid.'/devis/'.$accepte->getRouteKey())
            ->voir('Envoyer par e-mail');
        $onglet->seDeconnecter();
    }
});
