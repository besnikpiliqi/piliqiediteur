<?php

namespace Tests\Browser;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SocieteFactory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Tests\Browser\Support\Balayeur;
use Tests\Browser\Support\CouvertureDuBalayage;
use Tests\Browser\Support\DemonstrationDeTest;
use Tests\Browser\Support\Inventaire;
use Tests\Browser\Support\JournalDesReponses;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\NoyauDUneRequeteNeuve;
use Tests\Browser\Support\SocieteDeTest;
use Tests\TestCase;

/**
 * Le cas de test des tests en navigateur (tests/Browser, Pest 4 et son
 * module browser, voir tests/Pest.php) : un vrai Chromium, piloté par
 * Playwright, clique dans l'application, que le module sert depuis le
 * processus du test, avec ses fabriques, ses aides et sa base.
 *
 * Chaque test part d'un instant figé, écrit avec son fuseau (voir
 * instantDuTest()), que le serveur partage ; la session vit en base, comme en
 * production ; chaque requête part de l'état d'un processus neuf, et chaque
 * témoin dure le temps du navigateur, pour que le navigateur, à l'heure
 * réelle, ne le jette pas comme échu (voir NoyauDUneRequeteNeuve) ;
 * chaque réponse du serveur s'inscrit au journal du test (voir
 * JournalDesReponses), qui fait échouer tout refus ou toute erreur que le
 * test n'attendait pas, au plus tard à la fin du test.
 */
abstract class TestCaseDuNavigateur extends TestCase
{
    protected JournalDesReponses $journal;

    /**
     * L'instant auquel chaque test en navigateur commence : un mardi, à
     * 10 h à Paris, en heure d'hiver, en cours d'exercice.
     */
    protected static function instantDuTest(): CarbonImmutable
    {
        return self::aParis('2026-03-10 10:00:00');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::instantDuTest());
        config(['session.driver' => 'database']);
        $this->app->extend(Kernel::class, fn (Kernel $noyau): Kernel => $noyau instanceof NoyauDUneRequeteNeuve ? $noyau : new NoyauDUneRequeteNeuve($noyau, fn (): array => $this->prepareCookiesForRequest()));
        $this->journal = new JournalDesReponses;
        Event::listen(RequestHandled::class, $this->journal->noter(...));
    }

    protected function tearDown(): void
    {
        if ($this->status()->isSuccess()) {
            $this->journal->verifier();
            $this->assertSame([], $this->journal->attendusNonArrives(), 'Un refus attendu par le test n\'est pas arrivé.');
        }

        parent::tearDown();
    }

    /**
     * Un onglet d'un navigateur neuf, sans témoin ni session, ouvert sur
     * cette adresse de l'application.
     */
    protected function naviguer(string $chemin): Navigation
    {
        return Navigation::ouvrir($chemin, $this->journal);
    }

    /**
     * Le prochain refus de ce statut sur une requête dont le chemin contient
     * ce morceau est attendu par le test.
     */
    protected function tolerer(int $statut, string $chemin = ''): void
    {
        $this->journal->tolerer($statut, $chemin);
    }

    /**
     * Ce compte se connecte sur la page de connexion de ce panneau (le
     * magasin, « app », sauf autre panneau), dans cet onglet ou dans un
     * navigateur neuf, avec son identifiant (ou cette saisie, l'adresse de
     * connexion d'un propriétaire par exemple) et ce mot de passe ; rend
     * l'onglet, sur la page où la connexion l'a mené.
     */
    protected function seConnecter(User $compte, string $motDePasse = self::MOT_DE_PASSE_CHOISI, ?string $saisie = null, ?Navigation $onglet = null, string $panneau = 'app'): Navigation
    {
        $connexion = '/'.$panneau.'/login';
        $onglet = $onglet === null ? $this->naviguer($connexion) : $onglet->aller($connexion);

        $onglet->remplir('Identifiant ou adresse e-mail', $saisie ?? (string) $compte->identifiantDeConnexion())
            ->remplir('Mot de passe', $motDePasse)
            ->cliquerSur('Connexion')
            ->attendreQue('location.pathname !== '.Navigation::texte($connexion), 'la connexion n\'a mené nulle part')
            ->attendre()
            ->verifier();

        return $onglet;
    }

    /**
     * Ce compte, propriétaire ou responsable du magasin, reconnaît le
     * navigateur de cet onglet comme un poste du magasin (paramètres du
     * magasin, « Postes reconnus »), puis se déconnecte : un compte dont le
     * mot de passe est encore son identifiant peut ensuite s'y connecter.
     */
    protected function reconnaitreCeNavigateur(Navigation $onglet, Magasin $magasin, User $parQui, string $nom = 'Caisse 1'): Navigation
    {
        $this->seConnecter($parQui, onglet: $onglet)
            ->aller('/app/'.$magasin->uuid.'/postes-reconnus')
            ->cliquerSur('Reconnaître ce navigateur')
            ->remplir('Nom du poste', $nom)
            ->cliquerSur('Soumettre')
            ->voir('Ce navigateur est reconnu comme un poste du magasin.')
            ->voir($nom);

        return $onglet->seDeconnecter();
    }

    /**
     * Balaye, dans cet onglet connecté, chaque page atteignable depuis ce
     * chemin dans ces préfixes, et chaque action visible de chacune (voir
     * Balayeur) ; puis vérifie que tout ce qui a été ouvert est déclaré
     * dans le code (voir ActionsDeLInterface), et que ce balayage a ouvert
     * chaque action que l'inventaire des actions dit couverte par ce test,
     * dont le fichier est donné (__FILE__). Rend les pages balayées.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    protected function balayer(Navigation $onglet, string $depart, array $prefixes, string $fichierDuTest): array
    {
        $couverture = new CouvertureDuBalayage;
        $couverture->ecouter();
        $pages = (new Balayeur($onglet, $couverture))->balayer($depart, $prefixes);
        $bilan = $couverture->bilan();

        if ($bilan['inconnus'] !== []) {
            $this->fail('Le balayage a ouvert des actions que la lecture du code ne trouve pas (voir ActionsDeLInterface) :'.PHP_EOL.implode(PHP_EOL, $bilan['inconnus']));
        }

        $manquantes = [];

        foreach (Inventaire::promisesPar(Inventaire::reference($fichierDuTest, $this->getPrintableTestCaseMethodName())) as $fichier => $actions) {
            foreach (array_diff($actions, $bilan['couverts'][$fichier] ?? []) as $action) {
                $manquantes[] = $fichier.' : '.$action;
            }
        }

        if ($manquantes !== []) {
            $this->fail('L\'inventaire des actions dit ce balayage couvrir des actions qu\'il n\'a pas ouvertes :'.PHP_EOL.implode(PHP_EOL, $manquantes));
        }

        return $pages;
    }

    /**
     * Une société validée de « Commerce ou atelier » avec tous ses rôles,
     * comme le vrai parcours les crée (voir SocieteDeTest) : son magasin
     * « Boutique du Port » et un autre magasin ; un administrateur de tous
     * les magasins, sans la vente, avec toutes les autorisations de
     * société ; un administrateur autorisé à vendre dans les deux magasins ;
     * dans la Boutique du Port, un responsable, un vendeur et un observateur
     * (les autorisations du modèle de leur rôle) ; son identité légale
     * complète, pour facturer une entreprise ; son cabinet vérifié, lié
     * avec les autorisations par défaut, et le collaborateur à qui il la
     * confie ; le propriétaire a choisi son mot de passe. La Boutique du
     * Port a de quoi remplir chacune de ses pages (voir remplirLeMagasin()).
     * La société s'ouvre la veille de l'instant du test, à 8 h, avant toute opération
     * de son registre ; l'horloge revient ensuite à l'instant du test. Aucun magasin
     * n'est le magasin actif ensuite.
     */
    protected function societeComplete(): SocieteDeTest
    {
        $this->travelTo(self::aParis('2026-03-09 08:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT', 'siren' => SocieteFactory::sirenFictif()]);
        $this->completerIdentiteLegale($magasin);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->choisirSonMotDePasse($proprietaire);
        $type = $magasin->societe->type_activite;
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $administrateurVendeur = $this->administrateur($magasin, null, [], [...PermissionRegistry::modeleDeLAdministrateur($type), ...PermissionRegistry::deVente()]);
        $responsable = $this->membre($magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Victor Durand']);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, identite: ['name' => 'Olive Bernard']);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin, nom: 'Paul Lefèvre');
        $cabinet = $collaborateur->societe()->firstOrFail();
        $this->remplirLeMagasin($magasin, $responsable, $vendeur);
        app(TenantContext::class)->clear();

        return new SocieteDeTest(
            $magasin->societe()->firstOrFail(),
            $magasin->fresh(),
            $autreMagasin->fresh(),
            $proprietaire->refresh(),
            $administrateur,
            $administrateurVendeur,
            $responsable,
            $vendeur,
            $observateur,
            $cabinet,
            $this->proprietaireDuCabinet($cabinet),
            $collaborateur,
        );
    }

    /**
     * De quoi remplir chaque page du magasin, par les actions du domaine,
     * de la veille de l'instant du test, à 9 h, jusqu'à cet instant :
     * un fournisseur, deux clients (un particulier, une entreprise), un
     * service, deux produits, un inventaire d'ouverture en brouillon, une
     * facture d'achat validée avec un avoir fournisseur et une en
     * brouillon ; la veille, un ticket du vendeur et la clôture de la
     * journée ; ce jour, un devis en brouillon et un envoyé, une facture en
     * brouillon et une émise, réglée en partie, un ticket et son avoir,
     * fait par le responsable, puis la caisse du vendeur fermée.
     */
    protected function remplirLeMagasin(Magasin $magasin, User $responsable, User $vendeur): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $responsable, $vendeur): void {
            $this->travelTo(self::aParis('2026-03-09 09:00:00'));
            $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste du Nord']);
            $particulier = Client::factory()->create(['nom' => 'Jean Dupont']);
            $entreprise = Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']);
            Service::factory()->auPrixDe('5.00')->create(['nom' => 'Emballage cadeau']);
            $bougie = Produit::factory()->create(['nom' => 'Bougie parfumée', 'reference' => 'BOUGIE', 'prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => true]);
            $savon = Produit::factory()->create(['nom' => 'Savon au lait', 'reference' => 'SAVON', 'prix_vente_ttc' => 6, 'taux_tva' => 20, 'suivi_stock' => true]);
            app(CreerInventaireOuvertureAction::class)->handle($responsable, [
                ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
            ]);
            app(CreerFactureAchatAction::class)->handle($responsable, $fournisseur, [['produit_id' => $savon->id, 'quantite' => 10, 'prix_unitaire_ht' => 2]], ['date_facture' => '2026-01-15']);
            $achat = app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, $fournisseur, [
                ['produit_id' => $bougie->id, 'quantite' => 20, 'prix_unitaire_ht' => 5],
                ['produit_id' => $savon->id, 'quantite' => 30, 'prix_unitaire_ht' => 2],
            ], ['date_facture' => '2026-01-15']));
            app(EnregistrerAvoirFournisseurAction::class)->handle($responsable, $achat, [['ligne_achat_id' => $achat->lignes->first()->id, 'quantite' => 1]], 'Bougie cassée', 'AV-NORD-1');
            $this->travelTo(self::aParis('2026-03-09 15:00:00'));
            self::vendreAuComptant($vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $bougie->id, 'quantite' => 1]]);
            $this->travelTo(self::instantDuTest());
            app(CloturerCaisseAction::class)->handle($responsable, $magasin, '2026-03-09');
            $lignes = [['produit_id' => $savon->id, 'quantite' => 2]];
            app(CreerDevisAction::class)->handle($vendeur, $particulier, $lignes);
            app(EmettreDevisAction::class)->handle($vendeur, app(CreerDevisAction::class)->handle($vendeur, $entreprise, $lignes));
            app(CreerFactureAction::class)->handle($vendeur, $entreprise, $lignes);
            $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $entreprise, [['produit_id' => $bougie->id, 'quantite' => 3]]));
            app(EnregistrerPaiementAction::class)->handle($vendeur, $entreprise, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => '2026-03-10'], [['facture_id' => $facture->id, 'montant' => '20.00']]);
            $ticket = self::vendreAuComptant($vendeur, $particulier, [['produit_id' => $savon->id, 'quantite' => 2]], ModePaiement::CarteBancaire);
            app(EnregistrerAvoirAction::class)->handle($responsable, $particulier, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
            app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        });
    }

    /**
     * Le magasin de démonstration d'une société inscrite qui attend le code
     * de son courrier, ouvert par son propriétaire (voir
     * OuvrirLaDemonstrationAction), et les comptes d'entraînement qu'il y
     * crée, avec les autorisations du modèle de leur rôle : un responsable
     * et un vendeur, chacun son mot de passe choisi (voir
     * DemonstrationDeTest). Aucun magasin n'est le magasin actif ensuite.
     */
    protected function demonstration(): DemonstrationDeTest
    {
        $this->travelTo(self::aParis('2026-03-09 08:00:00'));
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire;
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        [$responsable, $vendeur] = app(TenantContext::class)->runAs($demonstration, fn (): array => array_map(
            fn (array $compte): User => $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle(
                $proprietaire,
                $demonstration,
                ['name' => $compte[0]],
                $compte[1],
                $compte[1]->permissionsParDefaut($societe->type_activite),
            )),
            [['Rémi d\'essai', RoleMagasin::Responsable], ['Valentin d\'essai', RoleMagasin::Vendeur]],
        ));
        $this->travelTo(self::instantDuTest());
        app(TenantContext::class)->clear();

        return new DemonstrationDeTest($societe->fresh(), $demonstration->fresh(), $proprietaire->refresh(), $responsable, $vendeur);
    }
}
