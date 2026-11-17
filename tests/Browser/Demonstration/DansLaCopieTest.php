<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Contacts\Models\Client;
use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Resources\MagasinResource;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Browser\Support\Navigation;
use Tests\Browser\TestCaseDuNavigateurDeLaDemonstration;
use Tests\Fixtures\FichiersDeTest;

/*
 * Dans une copie du site de démonstration (lot DEMO-COPIE, N24), comme le
 * visiteur qui a reçu le lien : l'entrée par le lien, dont l'adresse se
 * réécrit sans le secret ; « Choisissez qui vous êtes » ; le bandeau
 * « Vous êtes : » et son menu, qui change de compte sans mot de passe ;
 * chaque compte agit en son nom, comme en vrai (la vendeuse vend à la
 * caisse, la responsable envoie une facture, le propriétaire ferme un
 * magasin avec le mot de passe de démonstration, l'expert-comptable
 * déclaré valide une charge) ; « E-mails qui seraient partis », leur
 * cadre, leur pièce jointe, leurs pages et le lien de réponse d'un devis ;
 * « Quitter ».
 */

/**
 * Le magasin « Les Halles » de la copie, avec un produit en stock que la
 * responsable a fait naître par un inventaire d'ouverture validé.
 */
function lesHallesAvecUnProduit(TestCaseDuNavigateurDeLaDemonstration $test, Copie $copie): Magasin
{
    return $test->dansLaCopie($copie, function (): Magasin {
        $halles = Magasin::query()->withoutGlobalScopes()->where('nom', 'Les Halles')->sole();
        $responsable = User::query()->where('name', 'Camille Laurent')->sole();
        app(TenantContext::class)->runAs($halles, function () use ($responsable): void {
            $inventaire = app(CreerInventaireOuvertureAction::class)->handle($responsable, [
                ['produit' => ['nom' => 'Confiture de figues', 'reference' => 'CONF-FIGUE', 'prix_vente_ttc' => '6.50', 'taux_tva' => '5.5'], 'quantite' => 12, 'cout_unitaire_ht' => '3.10'],
            ]);
            app(ValiderInventaireOuvertureAction::class)->handle($responsable, $inventaire);
        });
        app(TenantContext::class)->clear();

        return $halles;
    });
}

/**
 * Dans le menu du bandeau « Vous êtes : », le compte de ce nom : le menu
 * s'ouvre, le clic change de compte et mène à son accueil, où le bandeau
 * le nomme.
 */
function passerA(Navigation $onglet, string $nom): Navigation
{
    return $onglet->cliquer('[data-menu-vous-etes] > summary')
        ->cliquer('[data-compte-du-bandeau]:has(.piliqi-bandeau__nom:text-is('.Navigation::texte($nom).'))')
        ->attendreQue('document.querySelector("[data-menu-vous-etes] summary")?.innerText.includes('.Navigation::texte('Vous êtes : '.$nom).')', 'le bandeau ne nomme pas '.$nom)
        ->attendre()
        ->verifier();
}

test('le visiteur entre par le lien, choisit la vendeuse, vend à la caisse en son nom, puis passe au propriétaire, qui voit sa caisse ouverte', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $halles = lesHallesAvecUnProduit($this, $copie);
    $vendeuse = $this->compteDeLaCopie($copie, 'Inès Garcia');

    $onglet = $this->suivreLeLien($secret)
        ->voir('Choisissez qui vous êtes')
        ->voir('Commencer en tant que propriétaire');

    expect($onglet->chemin())->toBe('/choisir')
        ->and($onglet->page()->url())->not->toContain($secret)
        ->and($onglet->page()->evaluate('() => window.location.hash'))->toBe('');

    $onglet->cliquer('[data-compte-du-choix="'.$vendeuse->getKey().'"]')
        ->attendreQue('document.querySelector("[data-bandeau-de-la-demonstration]") !== null && location.pathname.startsWith("/app")', 'le choix de la vendeuse n\'a pas ouvert son panneau')
        ->voir('Vous êtes : Inès Garcia')
        ->voir('(démonstration)')
        ->aller('/app/'.$halles->uuid.'/caisse')
        ->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is("Confiture de figues")')
        ->cliquerSur('Ajouter le produit')
        ->voir('6,50 €')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = $this->dansLaCopie($copie, fn (): Facture => app(TenantContext::class)->runAs($halles, fn (): Facture => Facture::query()->latest('id')->firstOrFail()));
    expect($ticket->cree_par)->toBe($vendeuse->getKey());

    $proprietaire = $this->dansLaCopie($copie, fn (): User => User::query()->where('role_societe', RoleSociete::Proprietaire->value)->sole());
    passerA($onglet, $proprietaire->name);
    expect($onglet->chemin())->toStartWith('/societe/');

    $onglet->aller('/app/'.$halles->uuid)
        ->voir('1 caisse de l\'équipe est ouverte : Inès Garcia.');
});

test('la responsable envoie une facture par e-mail, la lit dans « E-mails qui seraient partis » avec sa pièce jointe, puis le client accepte le devis par le lien de son e-mail', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $halles = lesHallesAvecUnProduit($this, $copie);
    [$facture, $devis] = $this->dansLaCopie($copie, function () use ($halles): array {
        $responsable = User::query()->where('name', 'Camille Laurent')->sole();

        return app(TenantContext::class)->runAs($halles, function () use ($responsable): array {
            $client = Client::factory()->create(['nom' => 'Jean Dupont', 'email' => 'jean.dupont@exemple.test']);
            $lignes = [['produit_id' => Produit::query()->where('reference', 'CONF-FIGUE')->sole()->getKey(), 'quantite' => 2]];
            $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, $lignes));
            $devis = app(CreerDevisAction::class)->handle($responsable, $client, $lignes, ['date_validite' => '2026-12-18']);
            app(EnvoyerDocumentAction::class)->handle($responsable, $devis, 'jean.dupont@exemple.test', null, (string) Str::uuid());

            return [$facture->refresh(), $devis->refresh()];
        });
    });

    $onglet = $this->suivreLeLien($secret)
        ->cliquer('[data-compte-du-choix]:has-text("Camille Laurent")')
        ->attendreQue('location.pathname.startsWith("/app")', 'le choix de la responsable n\'a pas ouvert son panneau')
        ->aller('/app/'.$halles->uuid.'/factures/'.$facture->getKey())
        ->voir((string) $facture->numero)
        ->cliquerSur('Envoyer par e-mail')
        ->remplir('Destinataire', 'jean.dupont@exemple.test')
        ->soumettre('Envoyer')
        ->aller('/app/'.$halles->uuid)
        ->cliquerSurLeLien('E-mails qui seraient partis : 3')
        ->voir('E-mails qui seraient partis')
        ->voir('jean.dupont@exemple.test');

    $courriel = $this->dansLaCopie($copie, fn (): CourrielDeLaDemonstration => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'like', '%'.$facture->numero.'%')->sole());
    $onglet->cliquer('[data-courriel="'.$courriel->getKey().'"]')
        ->voir((string) $courriel->objet)
        ->voir('Camille Laurent');

    expect($onglet->page()->evaluate('() => document.querySelector("[data-cadre-du-courriel]").getAttribute("sandbox")'))->toBe('allow-popups allow-popups-to-escape-sandbox')
        ->and($onglet->page()->evaluate('() => document.querySelector("[data-cadre-du-courriel]").getAttribute("srcdoc")'))->toContain((string) $facture->numero)->toContain('<base target="_blank">');

    $piece = $onglet->page()->evaluate('async () => { const lien = document.querySelector("[data-piece-jointe]"); const reponse = await fetch(lien.href); const contenu = await reponse.text(); return [lien.innerText, reponse.status, reponse.headers.get("content-disposition"), contenu.slice(0, 5)]; }');
    expect($piece)->toBeArray()
        ->and($piece[0])->toEndWith('.pdf')
        ->and($piece[1])->toBe(200)
        ->and($piece[2])->toStartWith('attachment')
        ->and($piece[3])->toBe('%PDF-');

    $courrielDuDevis = $this->dansLaCopie($copie, fn (): CourrielDeLaDemonstration => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'like', '%'.$devis->numero.'%')->sole());
    $onglet->cliquerSurLeLien('Tous les e-mails qui seraient partis')
        ->cliquer('[data-courriel="'.$courrielDuDevis->getKey().'"]')
        ->voir((string) $courrielDuDevis->objet);
    $lienDeReponse = (string) $onglet->element('[data-cadre-du-courriel] >> internal:control=enter-frame >> a[href*="/devis/"]')->getAttribute('href');
    expect($lienDeReponse)->toMatch('#/devis/[A-Za-z0-9]{64}$#');

    $onglet->aller((string) parse_url($lienDeReponse, PHP_URL_PATH))
        ->voir('Devis '.$devis->numero)
        ->remplir('Votre nom et votre prénom', 'Jean Dupont')
        ->cliquer('input[name="bon_pour_accord"]')
        ->cliquerSur('Accepter le devis')
        ->voir('Merci : votre acceptation est enregistrée');

    expect($this->dansLaCopie($copie, fn (): StatutDevis => app(TenantContext::class)->runAs($halles, fn (): StatutDevis => Devis::query()->findOrFail($devis->getKey())->statut)))->toBe(StatutDevis::Accepte);
});

test('« E-mails qui seraient partis » se lit par pages de cinquante', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $this->dansLaCopie($copie, function (): void {
        $societe = Societe::query()->where('siren', '000000109')->sole();
        app(TenantContext::class)->runAsSociete($societe, function (): void {
            foreach (range(1, 51) as $rang) {
                CourrielDeLaDemonstration::query()->create([
                    'expediteur' => 'Les Halles <contact@les-halles.exemple.test>',
                    'destinataires' => 'client'.$rang.'@exemple.test',
                    'copies' => '',
                    'objet' => 'Relance n° '.$rang,
                    'html' => '<p>Relance n° '.$rang.'</p>',
                    'texte' => 'Relance n° '.$rang,
                    'pieces_jointes' => [],
                    'envoye_le' => now()->subMinutes(60 - $rang),
                ]);
            }
        });
        app(TenantContext::class)->clear();
    });

    $this->suivreLeLien($secret)
        ->cliquer('[data-commencer-en-tant-que-proprietaire]')
        ->attendreQue('location.pathname.startsWith("/societe")', 'le propriétaire n\'arrive pas dans l\'espace de la société')
        ->cliquerSurLeLien('E-mails qui seraient partis : 52')
        ->voir('Relance n° 51')
        ->nePasVoir('Page précédente')
        ->cliquerSurLeLien('Page suivante')
        ->voir('Relance n° 1')
        ->nePasVoir('Relance n° 2')
        ->nePasVoir('Relance n° 51')
        ->cliquerSurLeLien('Page précédente')
        ->voir('Relance n° 51');
});

test('le propriétaire ferme un magasin avec le mot de passe de démonstration, l’expert-comptable déclaré valide une charge, puis « Quitter » retire le témoin', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;
    [$societe, $republique, $charge] = $this->dansLaCopie($copie, function () use ($motDePasse): array {
        $societe = Societe::query()->where('siren', '000000109')->sole();
        $proprietaire = User::query()->where('societe_id', $societe->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->sole();
        // Le propriétaire déclare comme depuis son écran, connecté : le lien
        // part à Marc Petit, et l'e-mail s'écrit dans la société du compte
        // connecté (voir TransportDeLaDemonstration).
        auth()->setUser($proprietaire);
        app(DeclarerSonExpertComptableAction::class)->handle($proprietaire, $societe, [
            'siren' => FauxRepertoireSirene::SIREN_CABINET,
            'name' => 'Marc Petit',
            'email' => 'marc.petit@petit-associes.exemple.test',
            'email_confirmation' => 'marc.petit@petit-associes.exemple.test',
            'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($societe->type_activite)),
            'remplacer' => false,
        ], $motDePasse);
        auth()->forgetUser();
        app(TenantContext::class)->clear();
        $message = app(TenantContext::class)->runAsSociete($societe, fn (): string => (string) CourrielDeLaDemonstration::query()->where('destinataires', 'like', '%marc.petit@%')->sole()->html);
        preg_match('#/expert-comptable/([A-Za-z0-9]+)"#', $message, $trouve);
        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($trouve[1] ?? '', $motDePasse, $motDePasse);
        app(TenantContext::class)->clear();
        $saisie = SaisieDeCharge::duFormulaire(['type' => 'piliqi:loyer', 'fournisseur' => 'SCI des Halles', 'numero_piece' => 'Q-2026-11', 'date_piece' => '2026-11-01', 'lignes' => [['taux' => '2000', 'ht' => '1200,00']]]);
        $charge = app(DeposerUneChargeAction::class)->handle($proprietaire, $societe, $saisie, new PieceDeposee('quittance.pdf', FichiersDeTest::pdf('Quittance de loyer')));
        app(TenantContext::class)->clear();

        return [$societe, Magasin::query()->withoutGlobalScopes()->where('nom', 'La République')->sole(), $charge];
    });

    $onglet = $this->suivreLeLien($secret)
        ->cliquer('[data-commencer-en-tant-que-proprietaire]')
        ->attendreQue('location.pathname.startsWith("/societe")', 'le propriétaire n\'arrive pas dans l\'espace de la société')
        ->aller((string) parse_url(MagasinResource::getUrl('edit', ['record' => $republique], panel: 'societe', tenant: $societe), PHP_URL_PATH))
        ->cliquerSur('Fermer le magasin')
        ->cliquer(Navigation::FENETRE.' input[value="temporaire"]')
        ->remplir('Votre mot de passe', $motDePasse)
        ->soumettre('Soumettre')
        ->voir('Le magasin La République est fermé.');

    expect($this->dansLaCopie($copie, fn (): bool => Magasin::query()->withoutGlobalScopes()->findOrFail($republique->getKey())->estFerme()))->toBeTrue();

    passerA($onglet, 'Marc Petit')
        ->cliquerSurLeLien('Tableau de bord de la société')
        ->voir('1 charge à valider')
        ->cliquerSurLeLien('Valider')
        ->attendreQue('location.pathname.endsWith("/charges-de-la-societe")', 'le lien ne mène pas à « Charges de la société »')
        ->cliquer('tr:has-text("Q-2026-11") >> '.Navigation::bouton('Valider'))
        ->soumettre('Valider')
        ->voir('Charge validée.');

    $relue = $this->dansLaCopie($copie, fn (): Charge => Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey()));
    expect([$relue->etat, $relue->validee_par])->toBe([EtatDeLaCharge::Validee, ValideePar::ExpertComptable]);

    $onglet->cliquer('.piliqi-bandeau__quitter')
        ->voir('Vous avez quitté la démonstration');

    expect(Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->exists())->toBeFalse();
});

test('dans la copie d’un cabinet, le lien d’une invitation ne crée aucune société et « Revenir à la démonstration » ramène au choix des comptes', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Cabinet);
    $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;
    [$lien, $societes] = $this->dansLaCopie($copie, function () use ($motDePasse): array {
        $cabinet = Societe::query()->where('type_activite', TypeDActivite::Cabinet->value)->sole();
        app(InviterUneEntrepriseAction::class)->handle($cabinet->proprietaire()->firstOrFail(), $cabinet, '000000141', 'dirigeant@entreprise-invitee.exemple.test', $motDePasse);
        app(TenantContext::class)->clear();
        $html = (string) CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('destinataires', 'like', '%dirigeant@entreprise-invitee.exemple.test%')->sole()->html;
        preg_match('#/invitation-cabinet/[A-Za-z0-9]{64}#', $html, $lien);

        return [$lien[0] ?? '', Societe::query()->count()];
    });
    expect($lien)->not->toBe('');

    $onglet = $this->suivreLeLien($secret)
        ->voir('Choisissez qui vous êtes')
        ->aller($lien)
        ->voir("L'entreprise invitée recevrait ce lien")
        ->nePasVoir('Commerce ou atelier')
        ->cliquerSurLeLien('Revenir à la démonstration')
        ->voir('Choisissez qui vous êtes');

    expect($onglet->chemin())->toBe('/choisir')
        ->and($this->dansLaCopie($copie, fn (): int => Societe::query()->count()))->toBe($societes);
});
