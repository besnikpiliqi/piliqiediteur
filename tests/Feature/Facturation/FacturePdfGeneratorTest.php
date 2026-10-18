<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacturePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const LIVRAISON_A_BERLIN = ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, 'livraison_adresse' => 'Musterstraße 1', 'livraison_code_postal' => '10115', 'livraison_ville' => 'Berlin', 'livraison_pays' => 'DE', 'livraison_date' => '2026-10-12', 'transport_par' => 'magasin'];

    private const NOTE_DE_CALCUL = 'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence.';

    public function test_genere_un_pdf_non_vide_pour_une_facture_emise(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50.40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $pdf = app(FacturePdfGenerator::class)->generer($facture);
        $contenu = $pdf->output();

        $this->assertStringStartsWith('%PDF', $contenu);
        $this->assertGreaterThan(500, strlen($contenu));
    }

    /**
     * Le client archivé depuis doit toujours sa facture : elle se
     * réimprime (voir Facture::client()).
     */
    public function test_genere_le_pdf_de_la_facture_d_un_client_archive(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(['nom' => 'Jeanne Archivée']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $client->delete();
        $facture = $facture->fresh();

        $generateur = app(FacturePdfGenerator::class);

        $this->assertStringStartsWith('%PDF', $generateur->generer($facture)->output());
        $this->assertSame("ticket-{$facture->numero}.pdf", $generateur->nomFichier($facture));
        $this->assertStringContainsString('Jeanne Archivée', $this->rendu($facture));
    }

    /**
     * Une mise à jour groupée ne passe pas par le modèle, qui retire le
     * SIRET d'un particulier : la copie de son client que prend le ticket
     * le garde, mais seul un document à un professionnel imprime les
     * identifiants de son client.
     */
    public function test_le_siret_du_client_ne_s_imprime_que_pour_un_professionnel(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create(['siret' => '12121212000012']);
        $particulier = Client::factory()->create(['nom' => 'Jean Dupont', 'adresse' => '3 place du Marché', 'code_postal' => '75011', 'ville' => 'Paris']);
        Client::query()->whereKey($particulier->id)->update(['siret' => '98765432100013']);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $emettre = fn (Client $client): Facture => app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client->fresh(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        [$facture, $ticket] = [$emettre($professionnel), $emettre($particulier)];

        $this->assertStringContainsString('SIRET : 12121212000012<br>', $this->rendu($facture));
        $this->assertSame('98765432100013', $ticket->client_siret);
        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Client</div><strong>Jean Dupont</strong><br>3 place du Marché<br>75011 Paris<br></div>',
            $this->compacte($this->rendu($ticket)),
        );
    }

    /**
     * Le client d'une facture à un professionnel établi hors de France
     * s'imprime avec son adresse complète, son pays et son n° de TVA.
     */
    public function test_la_facture_imprime_l_adresse_le_pays_et_le_numero_de_tva_d_un_client_etranger(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->etranger('BE', 'BE0123456789')->create([
            'nom' => 'Brasserie de Namur',
            'adresse' => 'Rue de Fer 12',
            'complement_adresse' => 'Boîte 3',
            'code_postal' => '5000',
            'ville' => 'Namur',
        ]);
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Facturé à</div><strong>Brasserie de Namur</strong><br>Rue de Fer 12<br>Boîte 3<br>5000 Namur<br>BE<br>N° TVA : BE0123456789<br></div>',
            $this->compacte($this->rendu($facture)),
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function choixDeLoptionPourLesDebits(): array
    {
        return [
            'option choisie' => [true],
            'option non choisie' => [false],
        ];
    }

    #[DataProvider('choixDeLoptionPourLesDebits')]
    public function test_la_mention_de_loption_pour_les_debits_suit_le_choix_du_magasin(bool $optionDebits): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['option_debits_tva' => $optionDebits])->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $html = $this->rendu($facture);

        $this->assertSame($optionDebits, str_contains($html, e("Option pour le paiement de la taxe d'après les débits")));
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function naturesDesLignes(): array
    {
        return [
            'un écran seul' => [['bien'], 'Livraison de biens'],
            'une main-d\'œuvre seule' => [['service'], 'Prestation de services'],
            'un écran et sa pose' => [['bien', 'service'], 'Livraison de biens et prestation de services'],
        ];
    }

    /**
     * @param  list<string>  $natures
     */
    #[DataProvider('naturesDesLignes')]
    public function test_la_facture_indique_la_categorie_de_l_operation(array $natures, string $categorie): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, array_map(
            fn (string $nature): array => [
                ...($nature === 'bien' ? ['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id] : ['service_id' => Service::factory()->create()->id]),
                'prix_unitaire_ttc' => 60,
                'taux_tva' => 20,
                'quantite' => 1,
            ],
            $natures,
        )));

        $html = $this->rendu($facture);

        $this->assertStringContainsString(e("Catégorie de l'opération : {$categorie}").'</div>', $html);
    }

    /**
     * Les mentions du vendeur (voir IdentiteSociete) : en tête, le magasin
     * qui vend, son adresse complète, son SIRET et ses coordonnées ; dans
     * le pied de chaque page (point 4), la raison sociale, la forme
     * juridique et le capital social de la société (H2), son siège, son
     * SIREN, son immatriculation (K7) et son n° de TVA.
     */
    public function test_la_facture_imprime_l_identite_legale_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'capital_social' => '10000.00',
            'siren' => '121212120',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République',
            'siege_code_postal' => '13001',
            'siege_ville' => 'Marseille',
        ])->create([
            'nom' => 'Boutique du Port',
            'siret' => '12121212000012',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => 'Bâtiment B',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'telephone' => '04 91 00 00 00',
            'email' => 'contact@boutique-du-port.fr',
        ]));
        $facture = $this->factureEmise();

        $html = $this->compacte($this->rendu($facture));

        $this->assertStringContainsString(
            '<div class="magasin-nom">Boutique du Port</div>12 quai des Pêcheurs<br>Bâtiment B<br>13002 Marseille<br>FR<br>'
            .'SIRET : 12121212000012<br>Tél. 04 91 00 00 00 · contact@boutique-du-port.fr</td>',
            $html,
        );
        $this->assertStringContainsString(
            '<div id="pied-de-page"><div class="identite">Martin et Fils SARL au capital de 10 000,00 € · '
            .'Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · '
            .'TVA intracommunautaire : FR56121212120</div>',
            $html,
        );
    }

    /**
     * Une société qui n'a encore que son SIREN, et ne vend donc qu'à des
     * particuliers, avec un magasin dont l'INSEE n'a pas encore donné
     * l'adresse : ni ligne vide ni libellé sans valeur, seuls le SIRET et
     * le SIREN, qui ne manquent jamais, s'impriment.
     */
    public function test_l_identite_non_renseignee_ne_s_imprime_pas(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'siren' => '121212120',
            'raison_sociale' => null,
            'forme_juridique' => null,
            'capital_social' => null,
            'immatriculation' => null,
            'numero_tva' => null,
            'siege_adresse' => null,
            'siege_code_postal' => null,
            'siege_ville' => null,
        ])->create([
            'nom' => 'Boutique du Port',
            'siret' => '12121212000012',
            'adresse' => null,
            'complement_adresse' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'FR',
            'telephone' => null,
            'email' => null,
        ]));
        $facture = $this->ticketEmis();

        $html = $this->rendu($facture);

        $enTete = Str::betweenFirst($html, '<table class="en-tete">', '</table>');
        $this->assertStringContainsString('<div class="magasin-nom">Boutique du Port</div>SIRET : 12121212000012</td>', $this->compacte($enTete));
        $this->assertStringNotContainsString('Tél.', $enTete);
        $this->assertStringContainsString('<div class="identite">SIREN : 121212120</div>', $this->compacte($html));
    }

    /**
     * Un capital social non renseigné ne s'imprime pas : pour une forme
     * qui n'est pas une société commerciale, dont il n'est pas exigé, la
     * raison sociale et la forme juridique ouvrent seules l'identité de la
     * société, dans le pied de page.
     */
    public function test_un_capital_social_non_renseigne_ne_s_imprime_pas(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Autre,
            'forme_juridique_autre' => 'Société civile',
            'societe_commerciale' => false,
            'capital_social' => null,
        ])->create());
        $facture = $this->factureEmise();

        $html = $this->rendu($facture);

        $this->assertStringContainsString('<div class="identite">Martin et Fils Société civile · ', $this->compacte($html));
        $this->assertStringNotContainsString('au capital', $html);
    }

    /**
     * Entre professionnels, la facture imprime les pénalités de retard,
     * l'indemnité forfaitaire de 40 € et l'absence d'escompte (B9), après
     * son échéance ; un ticket de caisse n'en imprime aucune.
     */
    public function test_seule_une_facture_a_un_professionnel_imprime_les_mentions_entre_professionnels(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $mentions = [
            'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.',
            'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
            'Pas d\'escompte pour paiement anticipé.',
        ];

        $facture = $this->rendu($this->factureEmise());
        $ticket = $this->rendu($this->ticketEmis());

        $this->assertStringContainsString('Échéance le ', $facture);
        $this->assertSame([3, 0], [
            collect($mentions)->filter(fn (string $mention): bool => str_contains($facture, '<div class="pied">'.e($mention).'</div>'))->count(),
            collect($mentions)->filter(fn (string $mention): bool => str_contains($ticket, e($mention)))->count(),
        ]);
    }

    /**
     * Les mentions sont celles que le magasin a réglées (Paramètres >
     * Factures).
     */
    public function test_la_facture_imprime_les_mentions_reglees_par_le_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['mention_escompte' => 'Escompte de 1 % pour paiement sous 8 jours.']));

        $html = $this->rendu($this->factureEmise());

        $this->assertStringContainsString('<div class="pied">Escompte de 1 % pour paiement sous 8 jours.</div>', $html);
        $this->assertStringNotContainsString(e('Pas d\'escompte pour paiement anticipé.'), $html);
    }

    public function test_le_nom_de_fichier_utilise_le_numero_ou_brouillon(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertStringContainsString('brouillon-', $generateur->nomFichier($facture));

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
        $this->assertSame("facture-{$facture->numero}.pdf", $generateur->nomFichier($facture));
    }

    /**
     * Un client particulier relève de l'e-reporting agrégé, pas d'une
     * facture transmise individuellement (voir
     * Facture::relevantDeLeInvoicing) : le document imprimé porte le
     * libellé usuel "ticket" plutôt que "facture", même s'il s'agit du
     * même enregistrement en base.
     */
    public function test_le_document_est_nomme_ticket_pour_un_client_particulier(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(); // particulier par défaut
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertSame("ticket-{$facture->numero}.pdf", $generateur->nomFichier($facture));

        // Le binaire PDF compilé n'est pas cherchable en texte brut
        // (flux compressés) : on vérifie le libellé sur la page HTML que
        // reçoit dompdf (voir FacturePdfGenerator::html()) plutôt que sur
        // sa sortie.
        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
        $this->assertStringContainsString('Ticket de caisse', $html);
        $this->assertStringNotContainsString('>Facturé à<', $html);
    }

    /**
     * Un montant déjà réglé à l'émission (encaissement et/ou crédit
     * d'avoir) doit apparaître sur le document remis au client -- sans ça,
     * rien ne prouve sur le PDF qu'une vente entièrement couverte par un
     * avoir a bien été soldée (voir AppliquerAvoirsDisponiblesAction). Le
     * bloc « Règlements » nomme l'avoir et dit ce qui reste dû (Q5).
     */
    public function test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $premiereFacture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        // Nouvel achat du même montant : couvert automatiquement par le crédit.
        $nouvelleFacture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $nouvelleFacture = app(EmettreFactureAction::class)->handle(self::auteur(), $nouvelleFacture)->fresh();

        $reglements = $this->reglementsImprimes($nouvelleFacture);

        $this->assertSame(["Avoir {$avoir->fresh()->numero} : 60,00 €", 'Restant dû : 0,00 €'], $reglements);
    }

    private function payer(Client $client, Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => $montant]],
        );
    }

    /**
     * Les lignes du bloc « Règlements » du PDF, « libellé : montant », dans
     * l'ordre ; aucune quand le PDF n'a pas ce bloc.
     *
     * @return list<string>
     */
    private function reglementsImprimes(Facture $facture): array
    {
        $html = $this->compacte(app(FacturePdfGenerator::class)->html($facture->fresh()));

        if (! str_contains($html, '<table class="reglements">')) {
            return [];
        }

        preg_match_all('#<td class="libelle">(.*?)</td><td class="montant">(.*?)</td>#', Str::betweenFirst($html, '<table class="reglements">', '</table>'), $lignes, PREG_SET_ORDER);

        return array_map(fn (array $ligne): string => html_entity_decode("{$ligne[1]} : {$ligne[2]}", ENT_QUOTES), $lignes);
    }

    /**
     * Un ticket émis sans rien recevoir, puis réglé par un paiement erroné,
     * un avoir imputé (20,00 €), la contre-passation de ce paiement et le
     * bon paiement, qui impute de nouveau l'avoir (30,00 €) : le PDF
     * reproduit le ticket tel qu'émis, sans aucun de ces règlements, que
     * la fiche montre (Q5).
     */
    public function test_un_avoir_impute_apres_l_emission_ne_s_imprime_pas_sur_le_pdf(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]]));
        $errone = $this->payer($client, $ticket, '80.00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Montant erroné');

        $this->payer($client, $ticket, '50.00');

        $this->assertSame([], $this->reglementsImprimes($ticket));
        $this->assertSame([50.0, 50.0, 0.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir(), $ticket->fresh()->montantRestant()]);
    }

    /**
     * Le PDF nomme l'avoir dont le crédit a réglé le ticket à son émission
     * (50,00 €, restant dû 50,00 €), et lui seul (Q5). Le second avoir est
     * émis quand le ticket est réglé, et n'a donc rien à y imputer (B6) ;
     * la contre-passation de ce règlement rouvre le ticket et y impute
     * aussitôt son crédit (complément 1), puis un paiement erroné est
     * contre-passé à son tour : aucun de ces règlements, venus après
     * l'émission, ne s'imprime.
     */
    public function test_un_avoir_impute_par_une_contre_passation_apres_l_emission_n_est_pas_nomme(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $client = Client::factory()->create();
        $article = fn (float $prixTtc): Produit => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
        $premierAchat = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $article(50)->id, 'quantite' => 1]], ModePaiement::Especes);
        $secondAchat = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $article(30)->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $premierAchat->lignes->sole()->id, 'quantite' => 1]]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $article(100)->id, 'quantite' => 1]]));
        $reglementRejete = $this->payer($client, $ticket, '50.00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $secondAchat->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $reglementRejete, 'Chèque rejeté');
        $errone = $this->payer($client, $ticket, '10.00');
        $this->assertSame([10.0, 80.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir()]);

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Saisi sur le mauvais ticket');

        $this->assertSame(['Avoir AV-LYON1-2026-000001 : 50,00 €', 'Restant dû : 50,00 €'], $this->reglementsImprimes($ticket));
        $this->assertSame([0.0, 80.0, 20.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir(), $ticket->fresh()->montantRestant()]);
    }

    public function test_aucune_mention_de_reglement_sur_une_facture_non_payee(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $html = $this->rendu($facture);

        $this->assertStringNotContainsString('Payé', $html);
        $this->assertStringNotContainsString('Crédité par avoir', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    /**
     * Toute vente soumise à la TVA, ticket ou facture, se calcule taux par
     * taux depuis ses prix TTC, et le HT de chaque taux se répartit sur ses
     * lignes : une note sous les lignes explique le calcul (point 15).
     *
     * @return array<string, array{bool}>
     */
    public static function ventesAvecTva(): array
    {
        return [
            'ticket à un particulier' => [false],
            'facture à un professionnel' => [true],
        ];
    }

    #[DataProvider('ventesAvecTva')]
    public function test_une_vente_explique_le_calcul_de_ses_lignes(bool $professionnel): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1000],
        ]));

        $html = $this->rendu($facture);

        $this->assertStringContainsString('<td class="montant">833,33 €</td>', $html);
        $this->assertStringContainsString(self::NOTE_DE_CALCUL, $html);
    }

    /**
     * En franchise en base, le HT est le prix TTC, sans TVA, pour un
     * particulier comme pour un professionnel : aucune note de calcul.
     *
     * @return array<string, array{bool}>
     */
    public static function ventesEnFranchise(): array
    {
        return [
            'ticket à un particulier' => [false],
            'facture à un professionnel' => [true],
        ];
    }

    #[DataProvider('ventesEnFranchise')]
    public function test_une_vente_en_franchise_n_a_pas_de_note_de_calcul(bool $professionnel): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => RegimeTva::FranchiseEnBase])->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1000],
        ]));

        $this->assertStringNotContainsString('<div class="note-calcul">', $this->rendu($facture));
    }

    /**
     * Le PDF est une vraie page A4 en portrait (595,28 x 841,89 points),
     * même pour une seule ligne, et jamais réduite : son pied et « Page 1
     * sur 1 » sont au bas de la page, à moins de 4 cm du bord (point 4).
     */
    public function test_le_pdf_est_une_page_a4_meme_avec_une_seule_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['siren' => '121212120'])->create());
        $facture = $this->factureEmise();

        $pages = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output());

        $this->assertSame(['0.000 0.000 595.280 841.890'], array_column($pages, 'format'));
        $this->assertLessThan(40 * 72 / 25.4, $this->texte($pages[0], fn (string $texte): bool => str_contains($texte, 'SIREN : 121212120'))['y']);
        $this->assertLessThan(15 * 72 / 25.4, $this->texte($pages[0], fn (string $texte): bool => $texte === 'Page 1 sur 1')['y']);
        $this->assertStringContainsString('@page { size: A4 portrait;', $this->rendu($facture));
    }

    /**
     * La référence du produit s'imprime dans sa colonne, entière sur une
     * seule ligne, même avec des espaces ; sa longue désignation passe à la
     * ligne (point 3).
     */
    public function test_la_reference_du_produit_est_imprimee_dans_sa_colonne_sans_retour_a_la_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $designation = 'Câble USB-C vers USB-C tressé nylon, charge rapide 100 W, longueur deux mètres, coloris noir';
        $produit = Produit::factory()->create(['reference' => 'CABLE USB-C 100W 2M NOIR', 'nom' => $designation, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $textes = array_column($this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output())[0]['textes'], 'texte');

        $this->assertStringContainsString('<td class="reference">CABLE USB-C 100W 2M NOIR</td><td>'.e($designation).'</td>', $this->tableauDesLignes($facture));
        $this->assertContains('CABLE USB-C 100W 2M NOIR', $textes);
        $this->assertNotContains($designation, $textes);
        $this->assertNotEmpty(array_filter($textes, fn (string $texte): bool => str_starts_with($texte, 'Câble USB-C')));
    }

    /**
     * Une référence de 60 caractères, la plus longue que la fiche d'un
     * produit accepte, sur une facture remisée aux grands montants : le
     * tableau reste dans la largeur de la page, chaque colonne obligatoire
     * (quantité, PU HT, remise, taux, total HT) et chaque texte entre les
     * marges. La longue référence s'imprime entière sur une seule ligne,
     * dans un corps réduit pour tenir dans sa colonne ; une référence
     * courte du même tableau garde le corps des lignes. 9 000 x 9 999,97 €
     * TTC remisés de 12,5 % : 78 749 763,75 € TTC, soit 65 624 803,13 € HT
     * à 20 %, PU HT 8 333,308333 €.
     */
    public function test_une_reference_de_60_caracteres_et_de_grands_montants_laissent_toutes_les_colonnes_dans_la_page(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'ABCDEFGHIJ']));
        $reference = 'CABLE-USB-C-VERS-USB-C-TRESSE-NYLON-100W-2M-NOIR-MODELE-2026';
        $longue = Produit::factory()->create(['reference' => $reference, 'nom' => 'Câble USB-C vers USB-C tressé nylon', 'prix_vente_ttc' => '9999.97', 'taux_tva' => 20, 'suivi_stock' => false]);
        $courte = Produit::factory()->create(['reference' => 'TAS-01', 'nom' => 'Tasse', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [
            ['produit_id' => $longue->id, 'quantite' => 9000, 'remise_pourcentage' => 12.5],
            ['produit_id' => $courte->id, 'quantite' => 1],
        ]);

        $page = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output())[0];

        $this->assertChaqueTexteTientEntreLesMarges($page);
        foreach (['QTÉ', 'PU HT', 'REMISE', 'TAUX', 'TOTAL HT', $reference, "9\u{202F}000", '8 333,308333 €', '12,5 %', '65 624 803,13 €'] as $texte) {
            $this->assertContains($texte, array_column($page['textes'], 'texte'));
        }
        $this->assertMatchesRegularExpression('#<td class="reference" style="font-size: [1-7]\.\dpt">'.$reference.'</td>#', $this->tableauDesLignes($facture));
        $this->assertStringContainsString('<td class="reference">TAS-01</td>', $this->tableauDesLignes($facture));
    }

    /**
     * Une facture imprime, pour chaque ligne, la référence, la désignation,
     * la quantité, le PU HT, le taux et le total HT, et ni PU TTC, ni TVA,
     * ni total TTC : ils sont dans le tableau de la TVA et les totaux
     * (point 3). 2 écrans à 120,00 € TTC à 20 % : PU HT 100,00 €, total HT
     * 200,00 €.
     */
    public function test_une_facture_n_imprime_ni_pu_ttc_ni_tva_ni_total_ttc_par_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'ECR-15', 'nom' => 'Écran 15 pouces', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);

        $tableau = $this->tableauDesLignes($facture);

        $this->assertSame(['Référence', 'Désignation', 'Qté', 'PU HT', 'Taux', 'Total HT'], $this->entetes($tableau));
        $this->assertStringContainsString('<tr><td class="reference">ECR-15</td><td>Écran 15 pouces</td><td class="montant">2</td><td class="montant">100,00 €</td><td class="montant">20 %</td><td class="montant">200,00 €</td></tr>', $tableau);
    }

    /**
     * Un ticket, dont le client paie le prix affiché TTC, imprime pour
     * chaque ligne le PU TTC et le total TTC (P3) : 2 articles à 25,00 €
     * TTC font 50,00 €.
     */
    public function test_un_ticket_imprime_le_pu_ttc_et_le_total_ttc_de_chaque_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'TAS-01', 'nom' => 'Tasse', 'prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = $this->emettre(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);

        $tableau = $this->tableauDesLignes($ticket);

        $this->assertSame(['Référence', 'Désignation', 'Qté', 'PU TTC', 'Taux', 'Total TTC'], $this->entetes($tableau));
        $this->assertStringContainsString('<tr><td class="reference">TAS-01</td><td>Tasse</td><td class="montant">2</td><td class="montant">25,00 €</td><td class="montant">20 %</td><td class="montant">50,00 €</td></tr>', $tableau);
    }

    /**
     * 1 000 articles à 1,00 € TTC à 20 % : le PU HT s'enregistre et
     * s'imprime au millionième, 0,833333 € (Q1-b), et 1 000 x 0,833333 =
     * 833,333 € retombe sur les 833,33 € HT de la ligne à moins de deux
     * centimes ; au centime, 1 000 x 0,83 € en ferait 830,00 €.
     */
    public function test_quantite_fois_pu_ht_imprime_redonne_le_total_ht_a_deux_centimes_pres(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'VIS-01', 'nom' => 'Vis', 'prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1000]]);

        $tableau = $this->tableauDesLignes($facture);

        $this->assertSame(['0.833333', '833.33'], [$facture->lignes->sole()->prix_unitaire_ht, $facture->lignes->sole()->montant_ht]);
        $this->assertStringContainsString("<td class=\"montant\">1\u{202F}000</td><td class=\"montant\">0,833333 €</td><td class=\"montant\">20 %</td><td class=\"montant\">833,33 €</td>", $tableau);
        preg_match_all('#<td class="montant">(.*?)</td>#', $tableau, $cases);
        [$quantite, $prixUnitaireHt, , $totalHt] = $cases[1];
        $enMillioniemes = fn (string $montant): int => (int) round((float) str_replace(',', '.', (string) preg_replace('/[^\d,]/u', '', $montant)) * 1_000_000);
        $this->assertLessThan(
            20_000,
            abs((int) preg_replace('/\D/u', '', $quantite) * $enMillioniemes($prixUnitaireHt) - $enMillioniemes($totalHt)),
            'Écart en millionièmes d\'euro entre la quantité x le PU HT imprimés et le total HT imprimé de la ligne.',
        );
    }

    /**
     * Une vente sans TVA française se paie hors taxe : un ticket d'export
     * à un particulier comme la facture d'une livraison intracommunautaire
     * n'impriment que le PU HT et le total HT, au taux de 0 %, et leur note
     * de calcul ne cite ni « Prix catalogue TTC » ni « TVA retirée », des
     * colonnes que le PDF n'a plus (point 3). 3 articles à 7,00 € TTC à
     * 20 % : PU HT 5,833333 €, 17,50 € HT ; 12 articles à 25,00 € : PU HT
     * 20,833333 €, 250,00 € HT.
     */
    public function test_une_vente_sans_tva_francaise_ticket_ou_facture_n_imprime_que_les_colonnes_ht_et_sa_note_ne_cite_aucune_colonne_absente(): void
    {
        $this->travelTo('2026-10-09 10:15:00');
        app(TenantContext::class)->set(Magasin::factory()->create());
        $export = $this->emettre(Client::factory()->create(), [['produit_id' => $this->produitAVingtPourCent('EXP-01', '7.00')->id, 'quantite' => 3]], [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);
        $livraison = $this->emettre($this->clientAllemandVerifie(), [['produit_id' => $this->produitAVingtPourCent('LIC-01', '25.00')->id, 'quantite' => 12]], self::LIVRAISON_A_BERLIN);

        foreach ([[$export, '3</td><td class="montant">5,833333 €</td><td class="montant">0 %</td><td class="montant">17,50 €'], [$livraison, '12</td><td class="montant">20,833333 €</td><td class="montant">0 %</td><td class="montant">250,00 €']] as [$vente, $ligne]) {
            $html = $this->rendu($vente);

            $this->assertSame(['Référence', 'Désignation', 'Qté', 'PU HT', 'Taux', 'Total HT'], $this->entetes($this->tableauDesLignes($vente)), $vente->numero);
            $this->assertStringContainsString($ligne, $this->tableauDesLignes($vente), $vente->numero);
            $this->assertStringContainsString('Vente sans TVA française', $html, $vente->numero);
            foreach (['PU TTC', 'Prix catalogue TTC', 'TVA retirée'] as $colonneAbsente) {
                $this->assertStringNotContainsString($colonneAbsente, $html, $vente->numero);
            }
        }
    }

    /**
     * Un ticket du client comptoir de 80,00 € réglé d'un seul geste par un
     * bon d'avoir de 20,00 €, 30,00 € en espèces et 30,00 € par carte
     * imprime chacun de ses règlements, relus dans son contenu signé : la
     * date, le mode et le montant de chaque paiement, le numéro et le
     * montant de l'avoir, puis le restant dû (points 3 et 23, Q5).
     */
    public function test_les_reglements_de_l_emission_s_impriment_par_mode(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->ouvrirUneSociete(['code' => 'LYON1']);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $article = fn (float $prix): array => [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prix, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
        $premier = self::vendreAuComptant(self::auteur(), $comptoir, $article(20));
        $bon = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]]);

        $ticket = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, $article(80), new ReglementComptant([
            new PartDeReglement(ModePaiement::Especes, 3000),
            new PartDeReglement(ModePaiement::CarteBancaire, 3000),
        ], [$bon->numero]));

        $this->assertSame(['09/10/2026 · Espèces : 30,00 €', '09/10/2026 · Carte bancaire : 30,00 €', 'Avoir AV-LYON1-2026-000001 : 20,00 €', 'Restant dû : 0,00 €'], $this->reglementsImprimes($ticket));
    }

    /**
     * Le pied de page porte l'identité de la société et toutes les
     * mentions, dans l'ordre : les conditions de paiement, les mentions
     * entre professionnels, la catégorie de l'opération et l'option pour
     * les débits ; l'en-tête ne garde que le magasin qui vend (point 4). Il
     * s'imprime sous les lignes, sans jamais les recouvrir.
     */
    public function test_l_identite_de_la_societe_et_toutes_les_mentions_sont_dans_le_pied_de_page(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils', 'forme_juridique' => FormeJuridique::Sarl, 'capital_social' => '10000.00', 'capital_variable' => true,
            'siren' => '121212120', 'immatriculation' => 'RCS Marseille', 'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille', 'option_debits_tva' => true,
        ])->create(['nom' => 'Boutique du Port', 'adresse' => '12 quai des Pêcheurs', 'code_postal' => '13002', 'ville' => 'Marseille']));
        $facture = $this->emettre(Client::factory()->professionnel()->create(['nom' => 'Acme SARL']), [['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 1]], ['conditions_paiement' => 'Virement à 30 jours.']);

        $html = $this->compacte($this->rendu($facture));
        $page = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output())[0];

        $this->assertSame([
            'Martin et Fils SARL à capital variable, au capital de 10 000,00 € · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120',
            'Virement à 30 jours.',
            'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.',
            'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
            'Pas d\'escompte pour paiement anticipé.',
            'Catégorie de l\'opération : Livraison de biens',
            'Option pour le paiement de la taxe d\'après les débits',
        ], $this->textesDuPied($html));
        $this->assertStringNotContainsString('SIREN', Str::betweenFirst($html, '<table class="en-tete">', '</table>'));
        $this->assertGreaterThan(
            $this->texte($page, fn (string $texte): bool => str_starts_with($texte, 'Martin et Fils'))['y'],
            $this->texte($page, fn (string $texte): bool => $texte === 'Total TTC')['y'],
        );
    }

    /**
     * Sur une facture de 60 lignes, le pied de page et « Page x sur y » se
     * répètent en bas de chaque page, au même endroit, et aucune ligne ne
     * descend jusqu'à lui (point 4).
     */
    public function test_le_pied_se_repete_sur_chaque_page_d_une_longue_facture(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils', 'siege_adresse' => '1 rue de la République', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille',
        ])->create(['nom' => 'Boutique du Port', 'adresse' => '12 quai des Pêcheurs', 'code_postal' => '13002', 'ville' => 'Marseille']));
        $designation = 'Câble USB-C vers USB-C tressé nylon, charge rapide 100 W, longueur deux mètres, coloris noir';
        $lignes = array_map(
            fn (int $rang): array => ['produit_id' => Produit::factory()->create(['reference' => "CABLE-{$rang}", 'nom' => $designation, 'suivi_stock' => false])->id, 'quantite' => 1],
            range(1, 60),
        );
        $facture = $this->emettre(Client::factory()->professionnel()->create(['nom' => 'Acme SARL']), $lignes);

        $pages = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output());

        $this->assertGreaterThanOrEqual(3, count($pages));
        foreach ($pages as $rang => $page) {
            $this->assertSame(
                $this->texte($pages[0], fn (string $texte): bool => str_starts_with($texte, 'Martin et Fils'))['y'],
                $this->texte($page, fn (string $texte): bool => str_starts_with($texte, 'Martin et Fils'))['y'],
                'Page '.($rang + 1),
            );
            $this->assertLePiedEstSousLesLignes($page, $this->textesDuPied($this->compacte($this->rendu($facture))), 'Page '.($rang + 1).' sur '.count($pages));
        }
    }

    /**
     * Un pied chargé, des mentions et des conditions de paiement de 500
     * caractères chacune, réserve toute sa hauteur au bas de chaque page :
     * les lignes s'arrêtent au-dessus de lui, page après page, et « Page x
     * sur y » reste sous lui.
     */
    public function test_un_pied_charge_reserve_sa_hauteur_sans_recouvrir_les_lignes(): void
    {
        $mention = fn (string $debut): string => mb_substr($debut.str_repeat(' Mention réglée par le magasin, imprimée telle quelle.', 10), 0, 500);
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils', 'siege_adresse' => '1 rue de la République', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille',
        ])->create([
            'nom' => 'Boutique du Port', 'adresse' => '12 quai des Pêcheurs', 'code_postal' => '13002', 'ville' => 'Marseille',
            'mention_penalites_retard' => $mention('Pénalités de retard :'),
            'mention_indemnite_recouvrement' => $mention('Indemnité forfaitaire :'),
            'mention_escompte' => $mention('Escompte :'),
        ]));
        $lignes = array_map(
            fn (int $rang): array => ['produit_id' => Produit::factory()->create(['reference' => "REF-{$rang}", 'nom' => "Article numéro {$rang}", 'suivi_stock' => false])->id, 'quantite' => 1],
            range(1, 40),
        );
        $facture = $this->emettre(Client::factory()->professionnel()->create(['nom' => 'Acme SARL']), $lignes, ['conditions_paiement' => $mention('Conditions :')]);
        $textesDuPied = $this->textesDuPied($this->compacte($this->rendu($facture)));

        $pages = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output());

        $this->assertGreaterThanOrEqual(2, count($pages));
        foreach ($pages as $rang => $page) {
            $this->assertLePiedEstSousLesLignes($page, $textesDuPied, 'Page '.($rang + 1).' sur '.count($pages));
        }
    }

    /**
     * Le siège à l'adresse du magasin ne se répète pas dans le pied : il y
     * est dit, sans son adresse, que le magasin est aussi le siège (S3).
     */
    public function test_le_siege_n_est_pas_repete_quand_il_est_l_etablissement(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils', 'siren' => '121212120',
            'siege_adresse' => '12, quai des Pêcheurs', 'siege_code_postal' => '13002', 'siege_ville' => 'MARSEILLE',
        ])->create(['adresse' => '12 quai des Pêcheurs', 'code_postal' => '13002', 'ville' => 'Marseille']));

        $identite = $this->textesDuPied($this->compacte($this->rendu($this->factureEmise())))[0];

        $this->assertStringContainsString(' · Siège social et établissement · SIREN : 121212120', $identite);
        $this->assertStringNotContainsString('Pêcheurs', $identite);
    }

    /**
     * Le tableau de la TVA imprime, à côté du taux de 0 %, le motif qui
     * l'explique (point 11) : l'exonération d'une livraison
     * intracommunautaire, la franchise en base d'un magasin qui en relève.
     */
    public function test_un_taux_a_zero_imprime_son_motif_dans_le_tableau_de_tva(): void
    {
        $this->travelTo('2026-10-09 10:15:00');
        $tableauDeLaTva = fn (string $motif, string $base): string => '<thead><tr><th>Taux TVA</th><th>Motif</th><th class="montant">Base HT</th><th class="montant">TVA</th><th class="montant">Total TTC</th></tr></thead>'
            .'<tbody><tr><td>0 %</td><td class="motif">'.e($motif).'</td><td class="montant">'.$base.'</td><td class="montant">0,00 €</td><td class="montant">'.$base.'</td></tr></tbody>';
        app(TenantContext::class)->set(Magasin::factory()->create());
        $livraison = $this->rendu($this->emettre($this->clientAllemandVerifie(), [['produit_id' => $this->produitAVingtPourCent('LIC-01', '25.00')->id, 'quantite' => 12]], self::LIVRAISON_A_BERLIN));
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => RegimeTva::FranchiseEnBase])->create());
        $franchise = $this->rendu($this->emettre(Client::factory()->create(), [['produit_id' => $this->produitAVingtPourCent('TAS-01', '12.00')->id, 'quantite' => 1]]));

        $this->assertStringContainsString($tableauDeLaTva('Exonération de TVA, article 262 ter I du CGI', '250,00 €'), $this->compacte($livraison));
        $this->assertStringContainsString($tableauDeLaTva('TVA non applicable, art. 293 B du CGI', '12,00 €'), $this->compacte($franchise));
    }

    /**
     * Un ticket réglé en entier à la caisse n'imprime pas d'échéance ; un
     * ticket émis sans être réglé imprime la sienne.
     */
    public function test_un_ticket_paye_a_la_caisse_n_imprime_pas_d_echeance(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        $paye = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        $du = $this->emettre(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $this->assertStringNotContainsString('Échéance', $this->rendu($paye));
        $this->assertStringContainsString('Échéance le 08/11/2026', $this->rendu($du));
    }

    /**
     * Une facture à un professionnel imprime toujours la date à laquelle le
     * règlement doit intervenir (art. L441-9 du Code de commerce), même
     * réglée par virement à son émission.
     */
    public function test_une_facture_a_un_professionnel_payee_a_l_emission_imprime_son_echeance(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = self::vendreAuComptant(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Virement, 'VIR-42');

        $this->assertSame(['09/10/2026 · Virement · VIR-42 : 30,00 €', 'Restant dû : 0,00 €'], $this->reglementsImprimes($facture));
        $this->assertStringContainsString('Échéance le 08/11/2026', $this->rendu($facture));
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, array $lignes, array $attributs = []): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, $lignes, $attributs));
    }

    private function produitAVingtPourCent(string $reference, string $prixTtc): Produit
    {
        return Produit::factory()->create(['reference' => $reference, 'nom' => "Article {$reference}", 'prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Un professionnel établi en Allemagne dont le n° de TVA est vérifié
     * valide sur VIES le jour même, comme l'écrit VerifierNumeroTvaAction.
     */
    private function clientAllemandVerifie(): Client
    {
        $client = Client::factory()->etranger('DE', 'DE123456789')->create(['nom' => 'Muster GmbH', 'code_postal' => '10115']);
        ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => $client->numero_tva,
            'valide' => true,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => now(),
            'reponse' => '{"valid":true}',
        ]);

        return $client;
    }

    /**
     * Le tableau des lignes du PDF, sans les blancs autour de ses balises.
     */
    private function tableauDesLignes(Facture $facture): string
    {
        return Str::betweenFirst($this->compacte($this->rendu($facture)), '<table class="lignes">', '</table>');
    }

    /**
     * Les titres des colonnes d'un tableau.
     *
     * @return list<string>
     */
    private function entetes(string $tableau): array
    {
        preg_match_all('#<th(?: [^>]*)?>(.*?)</th>#', $tableau, $titres);

        return array_map(fn (string $titre): string => html_entity_decode($titre, ENT_QUOTES), $titres[1]);
    }

    /**
     * Le texte de chaque ligne du pied de page, l'identité de la société
     * d'abord.
     *
     * @return list<string>
     */
    private function textesDuPied(string $html): array
    {
        preg_match_all('#<div class="(?:identite|pied)">(.*?)</div>#', Str::betweenFirst($html, '<div id="pied-de-page">', '</div></div>').'</div>', $textes);

        return array_map(fn (string $texte): string => html_entity_decode($texte, ENT_QUOTES), $textes[1]);
    }

    /**
     * Le pied de la page est sous toutes ses autres lignes, à 3 mm au moins,
     * et « Page x sur y » sous le pied. Un texte de la page de 8 caractères
     * au moins appartient au pied s'il est tout ou partie de l'une de ses
     * lignes, que la largeur de la page a pu couper.
     *
     * @param  array{format: string, textes: list<array{x: float, y: float, texte: string}>}  $page
     * @param  list<string>  $textesDuPied
     */
    private function assertLePiedEstSousLesLignes(array $page, array $textesDuPied, string $numero): void
    {
        $estDuPied = fn (string $texte): bool => mb_strlen($texte) >= 8 && collect($textesDuPied)->contains(
            fn (string $ligne): bool => str_contains($ligne, $texte),
        );
        $pied = array_column(array_filter($page['textes'], fn (array $texte): bool => $estDuPied($texte['texte'])), 'y');
        $corps = array_column(array_filter($page['textes'], fn (array $texte): bool => ! $estDuPied($texte['texte']) && $texte['texte'] !== $numero), 'y');

        $this->assertNotEmpty($pied, $numero);
        $this->assertGreaterThan(max($pied) + 3 * 72 / 25.4, min($corps), $numero);
        $this->assertLessThan(min($pied), $this->texte($page, fn (string $texte): bool => $texte === $numero)['y'], $numero);
    }

    /**
     * Le premier texte de la page qui répond au critère.
     *
     * @param  array{format: string, textes: list<array{x: float, y: float, texte: string}>}  $page
     * @param  callable(string): bool  $critere
     * @return array{x: float, y: float, texte: string}
     */
    private function texte(array $page, callable $critere): array
    {
        $trouves = array_values(array_filter($page['textes'], fn (array $texte): bool => $critere($texte['texte'])));
        $this->assertNotEmpty($trouves, 'Texte introuvable sur la page.');

        return $trouves[0];
    }

    private function rendu(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }

    private function factureEmise(): Facture
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    private function ticketEmis(): Facture
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }
}
