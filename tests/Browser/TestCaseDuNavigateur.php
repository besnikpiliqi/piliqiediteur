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
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SocieteFactory;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\PiloteLeNavigateur;
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
    use PiloteLeNavigateur;

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
        $this->preparerLeNavigateur();
    }

    protected function tearDown(): void
    {
        $this->verifierLeJournal();

        parent::tearDown();
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
     * Ce compte, propriétaire ou responsable du magasin, connecté avec ce
     * mot de passe, reconnaît le navigateur de cet onglet comme un poste du
     * magasin (paramètres du magasin, « Postes reconnus »), puis se
     * déconnecte : un compte dont le mot de passe est encore son
     * identifiant peut ensuite s'y connecter.
     */
    protected function reconnaitreCeNavigateur(Navigation $onglet, Magasin $magasin, User $parQui, string $nom = 'Caisse 1', string $motDePasse = self::MOT_DE_PASSE_CHOISI): Navigation
    {
        $this->seConnecter($parQui, $motDePasse, onglet: $onglet)
            ->aller('/app/'.$magasin->uuid.'/postes-reconnus')
            ->cliquerSur('Reconnaître ce navigateur')
            ->remplir('Nom du poste', $nom)
            ->cliquerSur('Soumettre')
            ->voir('Ce navigateur est reconnu comme un poste du magasin.')
            ->voir($nom);

        return $onglet->seDeconnecter();
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
        // Des noms fixes, qui ne dépendent pas de la langue des noms tirés
        // au hasard (constat C45 des audits du 07/11/2026) : dans la liste
        // de l'équipe que le propriétaire trie par nom, le premier compte
        // est un membre (Olive Bernard, l'observatrice), et le balayage,
        // qui n'ouvre qu'une fiche par sorte d'adresse, ouvre la sienne,
        // où se trouvent les actions d'un membre (« Modifier son rôle »).
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT'], User::factory()->create([
            'name' => 'Sophie Petit',
            'societe_id' => Societe::factory()->state(['siren' => SocieteFactory::sirenFictif(), 'type_activite' => TypeDActivite::Commerce])->commeApresLInscription(),
        ]));
        $this->completerIdentiteLegale($magasin);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->choisirSonMotDePasse($proprietaire);
        $type = $magasin->societe->type_activite;
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), nom: 'Thomas Robert');
        $administrateurVendeur = $this->administrateur($magasin, null, [], [...PermissionRegistry::modeleDeLAdministrateur($type), ...PermissionRegistry::deVente()], 'Xavier Garnier');
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
     * journée ; ce jour, un devis envoyé puis un devis en brouillon (le plus
     * récent, donc le premier d'une liste qui montre les derniers
     * documents d'abord, dont la fiche et la page de modification sont les
     * premières que le balayage ouvre), une facture en
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
            app(EmettreDevisAction::class)->handle($vendeur, app(CreerDevisAction::class)->handle($vendeur, $entreprise, $lignes));
            app(CreerDevisAction::class)->handle($vendeur, $particulier, $lignes);
            app(CreerFactureAction::class)->handle($vendeur, $entreprise, $lignes);
            $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $entreprise, [['produit_id' => $bougie->id, 'quantite' => 3]]));
            app(EnregistrerPaiementAction::class)->handle($vendeur, $entreprise, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => '2026-03-10'], [['facture_id' => $facture->id, 'montant' => '20.00']]);
            $ticket = self::vendreAuComptant($vendeur, $particulier, [['produit_id' => $savon->id, 'quantite' => 2]], ModePaiement::CarteBancaire);
            app(EnregistrerAvoirAction::class)->handle($responsable, $particulier, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
            app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        });
    }
}
