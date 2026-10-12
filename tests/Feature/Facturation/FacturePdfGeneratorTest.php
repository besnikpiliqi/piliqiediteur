<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\FacturePdfGenerator;
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

    private const NOTE_VENTE_PROFESSIONNELLE = 'Client professionnel : HT de chaque ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA calculée sur le total HT de chaque taux.';

    public function test_genere_un_pdf_non_vide_pour_une_facture_emise(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 42, 'taux_tva' => 20]);
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
        $this->assertStringContainsString('Jeanne Archivée', app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir')));
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
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

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

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringContainsString(e("Catégorie de l'opération : {$categorie}").'</div>', $html);
    }

    /**
     * Les mentions du vendeur (voir IdentiteSociete) : raison sociale,
     * forme juridique et capital social de la société (H2), adresse
     * complète et SIRET du magasin, siège, SIREN, immatriculation (K7),
     * n° de TVA, et coordonnées du magasin.
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

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringContainsString(
            '<div class="magasin-nom">Boutique du Port</div>'
            .'Martin et Fils SARL au capital de 10 000,00 €<br>12 quai des Pêcheurs<br>Bâtiment B<br>13002 Marseille<br>FR<br>'
            .'SIRET : 12121212000012<br>Siège social : 1 rue de la République, 13001 Marseille<br>'
            .'SIREN : 121212120<br>RCS Marseille<br>TVA intracommunautaire : FR56121212120<br>'
            .'Tél. 04 91 00 00 00 · contact@boutique-du-port.fr',
            preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html),
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

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $enTete = Str::betweenFirst($html, '<table class="en-tete">', '</table>');
        $this->assertStringContainsString('<div class="magasin-nom">Boutique du Port</div>SIRET : 12121212000012<br>SIREN : 121212120</td>', $this->compacte($enTete));
        $this->assertStringNotContainsString('Siège', $enTete);
        $this->assertStringNotContainsString('TVA intracommunautaire', $enTete);
        $this->assertStringNotContainsString('Tél.', $enTete);
    }

    /**
     * Un capital social non renseigné ne s'imprime pas : pour une forme
     * qui n'est pas une société commerciale, dont il n'est pas exigé, la
     * raison sociale et la forme juridique restent seules sur leur ligne.
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

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringContainsString('</div>Martin et Fils Société civile<br>', $this->compacte($html));
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
        $rendu = fn (Facture $facture): string => app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $facture = $rendu($this->factureEmise());
        $ticket = $rendu($this->ticketEmis());

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

        $html = app(FacturePdfGenerator::class)->html($this->factureEmise()->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringContainsString('<div class="pied">Escompte de 1 % pour paiement sous 8 jours.</div>', $html);
        $this->assertStringNotContainsString(e('Pas d\'escompte pour paiement anticipé.'), $html);
    }

    public function test_le_nom_de_fichier_utilise_le_numero_ou_brouillon(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
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
        $produit = Produit::factory()->create();
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
     * Un montant déjà réglé (encaissement et/ou crédit d'avoir) doit
     * apparaître sur le document remis au client -- sans ça, rien ne
     * prouve sur le PDF qu'une vente entièrement couverte par un avoir a
     * bien été soldée (voir AppliquerAvoirsDisponiblesAction).
     */
    public function test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [
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

        $html = app(FacturePdfGenerator::class)->html($nouvelleFacture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringContainsString('Crédité par avoir', $html);
        $this->assertStringContainsString($avoir->fresh()->numero, $html);
        $this->assertStringContainsString('60,00', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    private function payer(Client $client, Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => $montant]],
        );
    }

    private function libelleDuCredit(Facture $facture): string
    {
        $html = app(FacturePdfGenerator::class)->html($facture->fresh());

        return trim((string) preg_replace('/\s+/', ' ', Str::betweenFirst($html, 'Crédité par avoir', '</td>')));
    }

    /**
     * Un avoir imputé sur sa facture à son émission (20,00 €), puis de
     * nouveau par le bon paiement qui suit la contre-passation d'un
     * paiement erroné (30,00 €), n'est nommé qu'une fois.
     */
    public function test_un_avoir_impute_deux_fois_sur_la_facture_n_y_est_nomme_qu_une_fois(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]]));
        $errone = $this->payer($client, $ticket, '80.00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Montant erroné');

        $this->payer($client, $ticket, '50.00');

        $this->assertSame('(AV-LYON1-2026-000001)', $this->libelleDuCredit($ticket));
        $this->assertSame([50.0, 50.0, 0.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir(), $ticket->fresh()->montantRestant()]);
    }

    /**
     * Le crédit qu'une contre-passation a rendu à son avoir ne crédite plus
     * la facture : seul l'avoir dont le crédit y reste imputé est nommé. Le
     * second avoir est émis quand le ticket est réglé, et n'a donc rien à y
     * imputer (B6) ; la contre-passation de ce règlement rouvre le ticket,
     * et c'est le paiement erroné suivant qui y impute son crédit.
     */
    public function test_l_avoir_dont_la_contre_passation_a_rendu_le_credit_n_est_plus_nomme(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $client = Client::factory()->create();
        $article = fn (float $prixTtc): Produit => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
        $premierAchat = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $article(50)->id, 'quantite' => 1]], ModePaiement::Especes);
        $secondAchat = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $article(30)->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $premierAchat->lignes->sole()->id, 'quantite' => 1]]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $article(100)->id, 'quantite' => 1]]));
        $reglementRejete = $this->payer($client, $ticket, '50.00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $secondAchat->lignes->sole()->id, 'quantite' => 1]]);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $reglementRejete, 'Chèque rejeté');
        $errone = $this->payer($client, $ticket, '10.00');
        $this->assertSame([10.0, 80.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir()]);

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Saisi sur le mauvais ticket');

        $this->assertSame('(AV-LYON1-2026-000001)', $this->libelleDuCredit($ticket));
        $this->assertSame([0.0, 50.0, 50.0], [$ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir(), $ticket->fresh()->montantRestant()]);
    }

    public function test_aucune_mention_de_reglement_sur_une_facture_non_payee(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertStringNotContainsString('Payé', $html);
        $this->assertStringNotContainsString('Crédité par avoir', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    /**
     * Sur une facture à un professionnel, PU HT x quantité diffère du HT
     * de la ligne (1 000 x 0,83 € pour 833,33 €) : une note sous les
     * lignes explique le calcul (E1).
     */
    public function test_une_facture_a_un_professionnel_explique_le_calcul_de_ses_lignes(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1000],
        ]));

        $html = $this->rendu($facture);

        $this->assertStringContainsString('<td class="montant">0,83 €</td>', $html);
        $this->assertStringContainsString('<td class="montant">833,33 €</td>', $html);
        $this->assertStringContainsString(self::NOTE_VENTE_PROFESSIONNELLE, $html);
    }

    /**
     * Un ticket se calcule article par article, et en franchise en base le
     * HT est le TTC, même pour un professionnel : aucune note de calcul.
     *
     * @return array<string, array{RegimeTva, bool}>
     */
    public static function ventesSansNoteDeCalcul(): array
    {
        return [
            'ticket à un particulier' => [RegimeTva::ReelNormalMensuel, false],
            'facture à un professionnel en franchise' => [RegimeTva::FranchiseEnBase, true],
        ];
    }

    #[DataProvider('ventesSansNoteDeCalcul')]
    public function test_une_vente_calculee_sans_tva_sur_le_total_n_a_pas_de_note_de_calcul(RegimeTva $regime, bool $professionnel): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => $regime])->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1000],
        ]));

        $this->assertStringNotContainsString('Client professionnel :', $this->rendu($facture));
    }

    private function rendu(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));
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
