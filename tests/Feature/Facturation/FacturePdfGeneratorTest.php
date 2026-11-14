<?php

namespace Tests\Feature\Facturation;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
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
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacturePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const LIVRAISON_A_BERLIN = ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, 'livraison_adresse' => 'Musterstraße 1', 'livraison_code_postal' => '10115', 'livraison_ville' => 'Berlin', 'livraison_pays' => 'DE', 'livraison_date' => '2026-10-12', 'transport_par' => 'magasin'];

    private const NOTE_DE_CALCUL = 'Montants calculés depuis les prix TTC : pour chaque ligne, la TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime, et le HT est le TTC moins cette TVA ; un centime peut être ajusté sur une ligne pour que la TVA de chaque taux soit égale à sa base HT × le taux.';

    /**
     * La note d'un document qui montre le PU HT : facture à un
     * professionnel, vente sans TVA française. Le PU HT affiché est celui
     * d'un article (décision de l'éditeur du 14/11/2026, qui remplace
     * celle du 09/11/2026 et T105).
     */
    private const NOTE_DE_CALCUL_PU_HT_D_UN_ARTICLE = 'Montants calculés depuis les prix TTC : pour chaque ligne, la TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime, et le HT est le TTC moins cette TVA ; un centime peut être ajusté sur une ligne pour que la TVA de chaque taux soit égale à sa base HT × le taux. Le PU HT affiché est celui d\'un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité. Le PU HT exact, écrit sous chaque article, est celui de la facture électronique : la quantité × ce prix retombe sur le total HT de la ligne à quelques centimes près.';

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
     * Un PDF n'embarque que les caractères qu'il emploie de ses polices
     * (choix du lot ML, contestable par l'éditeur) : une page pèse moins de
     * 150 Ko, au lieu d'environ 880 Ko avec DejaVu Sans entière, se lit en
     * entier (qpdf, que PILIQI demande déjà), et garde ses textes, accents
     * compris.
     */
    public function test_un_pdf_d_une_page_pese_moins_de_150_ko_et_garde_ses_textes(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['nom' => 'Câble électrique tressé', 'prix_vente_ttc' => 50.40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]));

        $contenu = app(FacturePdfGenerator::class)->generer($facture)->output();

        $this->assertLessThan(150 * 1024, strlen($contenu));
        $fichier = (string) tempnam(sys_get_temp_dir(), 'pdf-');
        file_put_contents($fichier, $contenu);
        $verification = Process::run([Reglage::texte('piliqi.qpdf'), '--check', $fichier]);
        unlink($fichier);
        $this->assertSame(0, $verification->exitCode(), $verification->output().$verification->errorOutput());
        $pages = $this->pagesDuPdf($contenu);
        $this->assertCount(1, $pages);
        $textes = implode("\n", array_column($pages[0]['textes'], 'texte'));
        $this->assertStringContainsString((string) $facture->numero, $textes);
        $this->assertStringContainsString('Câble électrique tressé', $textes);
        $this->assertStringContainsString('Page 1 sur 1', $textes);
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
            '<td class="bloc"><div class="titre">Client</div><strong>Jean Dupont</strong><br>3 place du Marché<br>75011 Paris<br></td>',
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
            '<td class="bloc"><div class="titre">Facturé à</div><strong>Brasserie de Namur</strong><br>Rue de Fer 12<br>Boîte 3<br>5000 Namur<br>BE<br>N° TVA : BE0123456789<br></td>',
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
     * bloc « Règlements » nomme l'avoir et dit ce qui restait dû à
     * l'émission (Q5, constat C3).
     */
    public function test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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

        $this->assertSame([['09/10/2026', 'Avoir', $avoir->fresh()->numero, '60,00 €'], ['Restant dû à l\'émission', '0,00 €']], $reglements);
    }

    private function payer(Client $client, Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => Calendrier::deParis()->aujourdhui()],
            [['facture_id' => $facture->id, 'montant' => $montant]],
        );
    }

    /**
     * Les rangées du bloc « Règlements » du PDF, dans l'ordre, chacune avec
     * le texte de ses cases : la date, le mode, la référence et le montant
     * de chaque règlement, puis le libellé et le montant de ce qui restait
     * dû à l'émission ; aucune quand le PDF n'a pas ce bloc.
     *
     * @return list<list<string>>
     */
    private function reglementsImprimes(Facture $facture): array
    {
        $html = $this->compacte(app(FacturePdfGenerator::class)->html($facture->fresh()));

        if (! str_contains($html, '<table class="reglements">')) {
            return [];
        }

        preg_match_all('#<tr(?: class="total")?>(.*?)</tr>#', Str::betweenFirst(Str::betweenFirst($html, '<table class="reglements">', '</table>'), '<tbody>', '</tbody>'), $rangees);

        return array_map(function (string $rangee): array {
            preg_match_all('#<td[^>]*>(.*?)</td>#', $rangee, $cases);

            return array_map(fn (string $case): string => html_entity_decode(strip_tags($case), ENT_QUOTES), $cases[1]);
        }, $rangees[1]);
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
        $this->assertSame([5000, 5000, 0], [$ticket->fresh()->montantPayeEnCentimes(), $ticket->fresh()->montantCrediteParAvoirEnCentimes(), $ticket->fresh()->montantRestantEnCentimes()]);
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $this->assertSame([1000, 8000], [$ticket->fresh()->montantPayeEnCentimes(), $ticket->fresh()->montantCrediteParAvoirEnCentimes()]);

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $errone, 'Saisi sur le mauvais ticket');

        $this->assertSame([['09/10/2026', 'Avoir', 'AV-LYON1-2026-000001', '50,00 €'], ['Restant dû à l\'émission', '50,00 €']], $this->reglementsImprimes($ticket));
        $this->assertSame([0, 8000, 2000], [$ticket->fresh()->montantPayeEnCentimes(), $ticket->fresh()->montantCrediteParAvoirEnCentimes(), $ticket->fresh()->montantRestantEnCentimes()]);
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
     * Toute vente soumise à la TVA, ticket ou facture, se calcule ligne par
     * ligne depuis ses prix TTC, un centime pouvant être ajusté par taux :
     * une note sous les lignes explique le calcul (point 15, règle (b),
     * décision de l'éditeur du 14/11/2026) ; sur la facture à un
     * professionnel (modèle M6-b), elle ajoute le PU HT d'un article et le PU
     * HT exact écrit sous chaque article ; sur le ticket (modèle P1), qui ne
     * montre que des prix TTC, rien d'autre.
     *
     * @return array<string, array{bool, string}>
     */
    public static function ventesAvecTva(): array
    {
        return [
            'ticket à un particulier' => [false, self::NOTE_DE_CALCUL],
            'facture à un professionnel' => [true, self::NOTE_DE_CALCUL_PU_HT_D_UN_ARTICLE],
        ];
    }

    #[DataProvider('ventesAvecTva')]
    public function test_une_vente_explique_le_calcul_de_ses_lignes(bool $professionnel, string $note): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1000],
        ]));

        $html = $this->rendu($facture);

        $this->assertStringContainsString('<td class="montant">833,33 €</td>', $html);
        $this->assertStringContainsString(e($note), $html);
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

        $this->assertStringContainsString('<td class="reference">CABLE USB-C 100W 2M NOIR</td><td>'.e($designation).'<span class="sous">', $this->tableauDesLignes($facture));
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
     * TTC remisés de 12,5 % : 78 749 763,75 € TTC, dont 13 124 960,625 ->
     * 13 124 960,63 € de TVA, soit 65 624 803,12 € HT à 20 % (règle (b)),
     * PU HT d'un article 9 999,97 € moins sa TVA de 1 666,66 €, soit
     * 8 333,31 € (décision de l'éditeur du 14/11/2026).
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
        foreach (['QTÉ', 'PU HT', 'REM.', 'TVA', 'TOTAL HT', $reference, "9\u{202F}000", '8 333,31 €', '12,5 %', '65 624 803,12 €'] as $texte) {
            $this->assertContains($texte, array_column($page['textes'], 'texte'));
        }
        $this->assertMatchesRegularExpression('#<td class="reference" style="font-size: [1-7]\.\dpt">'.$reference.'</td>#', $this->tableauDesLignes($facture));
        $this->assertStringContainsString('<td class="reference">TAS-01</td>', $this->tableauDesLignes($facture));
    }

    /**
     * Une facture imprime, pour chaque ligne et dans cet ordre, la
     * référence, l'article avec en petit son PU HT exact, la quantité, le PU
     * HT, la remise (« — » sans remise), le taux de TVA et le total HT, et
     * ni PU TTC ni total TTC : le TTC n'est qu'au récapitulatif par taux et
     * au montant à régler (point 3, modèle M6-b choisi par l'éditeur le
     * 14/11/2026). 2 écrans à 120,00 € TTC à 20 % : PU HT 100,00 €, total
     * HT 200,00 €.
     */
    public function test_une_facture_n_imprime_ni_pu_ttc_ni_tva_ni_total_ttc_par_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'ECR-15', 'nom' => 'Écran 15 pouces', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);

        $tableau = $this->tableauDesLignes($facture);

        $this->assertSame(['Réf.', 'Article', 'Qté', 'PU HT', 'Rem.', 'TVA', 'Total HT'], $this->entetes($tableau));
        $this->assertStringContainsString('<tr><td class="reference">ECR-15</td><td>Écran 15 pouces<span class="sous">PU HT exact : 100,000000 €</span></td><td class="montant">2</td><td class="montant">100,00 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">200,00 €</td></tr>', $tableau);
        $this->assertStringNotContainsString('PU TTC', $tableau);
        $this->assertStringNotContainsString('240,00 €', $tableau);
    }

    /**
     * Un ticket, dont le client paie le prix affiché TTC, imprime pour
     * chaque ligne la référence, l'article, la quantité, le PU TTC, la
     * remise, le taux de TVA et le total TTC, sans PU HT ni PU HT exact (P3,
     * modèle P1 choisi par l'éditeur le 14/11/2026) : 2 articles à 25,00 €
     * TTC font 50,00 €.
     */
    public function test_un_ticket_imprime_le_pu_ttc_et_le_total_ttc_de_chaque_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'TAS-01', 'nom' => 'Tasse', 'prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = $this->emettre(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);

        $tableau = $this->tableauDesLignes($ticket);

        $this->assertSame(['Réf.', 'Article', 'Qté', 'PU TTC', 'Rem.', 'TVA', 'Total TTC'], $this->entetes($tableau));
        $this->assertStringContainsString('<tr><td class="reference">TAS-01</td><td>Tasse</td><td class="montant">2</td><td class="montant">25,00 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">50,00 €</td></tr>', $tableau);
        $this->assertStringNotContainsString('PU HT', $tableau);
    }

    /**
     * 1 000 articles à 1,00 € TTC à 20 % sur une facture à un
     * professionnel : le PU HT s'enregistre au millionième, 0,833333 €,
     * celui de la facture électronique, et s'imprime comme partout, celui
     * d'un article : 1,00 € moins sa TVA de 0,17 €, 0,83 € (décision de
     * l'éditeur du 14/11/2026, qui remplace celle du 09/11/2026), et, en
     * petit sous l'article, tel qu'il est enregistré (modèle M6-b) ; le
     * total HT de la ligne reste le calcul exact, 1 000,00 € moins sa TVA de
     * 166,67 € (166,666...), 833,33 €, jamais 1 000 x 0,83 € = 830,00 €, ce
     * que dit la note de calcul imprimée sous les lignes.
     */
    public function test_le_pu_ht_d_un_article_s_imprime_sans_changer_le_total_de_la_ligne(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'VIS-01', 'nom' => 'Vis', 'prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1000]]);

        $html = $this->rendu($facture);

        $this->assertSame(['0.833333', '833.33'], [$facture->lignes->sole()->prix_unitaire_ht, $facture->lignes->sole()->montant_ht]);
        $this->assertStringContainsString("<td>Vis<span class=\"sous\">PU HT exact : 0,833333 €</span></td><td class=\"montant\">1\u{202F}000</td><td class=\"montant\">0,83 €</td><td class=\"montant\">—</td><td class=\"montant\">20 %</td><td class=\"montant total-de-la-ligne\">833,33 €</td>", $this->tableauDesLignes($facture));
        $this->assertSame(1, substr_count($html, '0,833333'));
        $this->assertStringNotContainsString('830,00 €', $html);
        $this->assertStringContainsString('<div class="note-calcul">'.e(self::NOTE_DE_CALCUL_PU_HT_D_UN_ARTICLE).'</div>', $this->compacte($html));
    }

    /**
     * La note de calcul d'un document qui montre le PU HT est une seule
     * phrase, quel que soit le nombre d'articles de ses lignes (décision de
     * l'éditeur du 14/11/2026, qui remplace T105) : 100 000 articles à
     * 1,00 € TTC à 20 % font 83 333,33 € HT, PU HT affiché 0,83 €, et la
     * note dit, comme pour 1 000 articles, que la quantité x ce prix peut
     * différer de quelques centimes du total HT de la ligne.
     */
    public function test_la_note_de_calcul_est_la_meme_quel_que_soit_le_nombre_d_articles(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'VIS-01', 'nom' => 'Vis', 'prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 100000]]);

        $html = $this->compacte($this->rendu($facture));

        $this->assertSame(['0.833333', '83333.33'], [$facture->lignes->sole()->prix_unitaire_ht, $facture->lignes->sole()->montant_ht]);
        $this->assertStringContainsString('<td class="montant">0,83 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">83 333,33 €</td>', $this->tableauDesLignes($facture));
        $this->assertStringContainsString('<div class="note-calcul">'.e(self::NOTE_DE_CALCUL_PU_HT_D_UN_ARTICLE).'</div>', $html);
        $this->assertStringNotContainsString('deux centimes', $html);
    }

    /**
     * L'exemple de l'éditeur sur le PDF d'une facture à un professionnel :
     * 4 articles à 65,00 € TTC à 20 % ont un PU HT enregistré de 65 ÷ 1,2 =
     * 54,166667 €, celui de la facture électronique, imprimé comme le PU HT
     * d'un article : 65,00 € moins sa TVA de 10,83 €, 54,17 € (décision de
     * l'éditeur du 14/11/2026) -- le PU HT exact, 54,166667 €, ne s'imprime
     * qu'en petit sous l'article (modèle M6-b) --, et la ligne totalise
     * 260,00 € moins sa TVA de 43,33 € (43,333...), 216,67 € HT, et non 4 x
     * 54,17 € = 216,68 €.
     */
    public function test_une_facture_a_un_professionnel_imprime_le_pu_ht_de_54_17_euros_d_un_article(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'ECR-65', 'nom' => 'Écran', 'prix_vente_ttc' => '65.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 4]]);

        $html = $this->compacte($this->rendu($facture));

        $this->assertSame(['54.166667', '216.67', '43.33', '260.00'], [$facture->lignes->sole()->prix_unitaire_ht, $facture->lignes->sole()->montant_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertStringContainsString('<tr><td class="reference">ECR-65</td><td>Écran<span class="sous">PU HT exact : 54,166667 €</span></td><td class="montant">4</td><td class="montant">54,17 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">216,67 €</td></tr>', $this->tableauDesLignes($facture));
        $this->assertStringContainsString('<td>20 %</td><td class="montant">216,67 €</td><td class="montant">43,33 €</td><td class="montant">260,00 €</td>', $html);
        $this->assertSame(1, substr_count($html, '54,166667 €'));
        foreach (['54,1667 €', '216,68'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    /**
     * L'exemple de l'éditeur du 14/11/2026 : 3 savons à 9,99 € TTC à 20 %
     * ont un PU HT enregistré de 9,99 ÷ 1,2 = 8,325 €, imprimé comme le PU
     * HT d'un article : la TVA d'un savon, 1,665 €, s'arrondit à 1,67 €, et
     * le PU HT est 9,99 - 1,67 = 8,32 €, jamais 8,325 € ni 8,33 € (un
     * arrondi simple de 8,325 €), le PU HT exact, 8,325000 €, ne s'imprimant
     * qu'en petit sous l'article (modèle M6-b) ; la ligne de 29,97 € TTC
     * comprend 29,97 x 20 ÷ 120 = 4,995 -> 5,00 € de TVA et totalise 24,97 €
     * HT (règle (b), décision de l'éditeur du 14/11/2026).
     */
    public function test_un_savon_a_9_99_euros_ttc_imprime_un_pu_ht_de_8_32_euros(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'SAV-01', 'nom' => 'Savon', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 3]]);

        $tableau = $this->tableauDesLignes($facture);

        $this->assertSame(['8.325000', '24.97'], [$facture->lignes->sole()->prix_unitaire_ht, $facture->lignes->sole()->montant_ht]);
        $this->assertStringContainsString('<td>Savon<span class="sous">PU HT exact : 8,325000 €</span></td><td class="montant">3</td><td class="montant">8,32 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">24,97 €</td>', $tableau);
        foreach (['8,33 €', '8,325 €'] as $absent) {
            $this->assertStringNotContainsString($absent, $tableau);
        }
    }

    /**
     * Le PU HT d'un article aux taux réduits, sur le PDF d'une facture à
     * un professionnel : 10,00 € TTC à 5,5 % portent une TVA de 0,5213 €,
     * soit 0,52 €, et un PU HT de 9,48 € ; 10,00 € TTC à 10 %, une TVA de
     * 0,9090 €, soit 0,91 €, et un PU HT de 9,09 € ; 0,99 € TTC à 20 %,
     * une TVA de 0,165 €, soit 0,17 €, et un PU HT de 0,82 €. Chaque ligne
     * d'un seul article totalise le même HT, sa TVA arrondie de même (règle
     * (b), décision de l'éditeur du 14/11/2026) : 9,48 €, 9,09 € et 0,82 €,
     * soit 19,39 € HT et 1,60 € de TVA.
     */
    public function test_le_pu_ht_d_un_article_s_imprime_aux_taux_reduits_et_pour_un_petit_prix(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $livre = Produit::factory()->create(['reference' => 'LIV-01', 'nom' => 'Livre', 'prix_vente_ttc' => '10.00', 'taux_tva' => '5.5', 'suivi_stock' => false]);
        $repas = Produit::factory()->create(['reference' => 'REP-01', 'nom' => 'Repas', 'prix_vente_ttc' => '10.00', 'taux_tva' => 10, 'suivi_stock' => false]);
        $gomme = Produit::factory()->create(['reference' => 'GOM-01', 'nom' => 'Gomme', 'prix_vente_ttc' => '0.99', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [
            ['produit_id' => $livre->id, 'quantite' => 1],
            ['produit_id' => $repas->id, 'quantite' => 1],
            ['produit_id' => $gomme->id, 'quantite' => 1],
        ]);

        $tableau = $this->tableauDesLignes($facture);

        $this->assertStringContainsString('<td class="reference">LIV-01</td><td>Livre<span class="sous">PU HT exact : 9,478673 €</span></td><td class="montant">1</td><td class="montant">9,48 €</td><td class="montant">—</td><td class="montant">5,5 %</td><td class="montant total-de-la-ligne">9,48 €</td>', $tableau);
        $this->assertStringContainsString('<td class="reference">REP-01</td><td>Repas<span class="sous">PU HT exact : 9,090909 €</span></td><td class="montant">1</td><td class="montant">9,09 €</td><td class="montant">—</td><td class="montant">10 %</td><td class="montant total-de-la-ligne">9,09 €</td>', $tableau);
        $this->assertStringContainsString('<td class="reference">GOM-01</td><td>Gomme<span class="sous">PU HT exact : 0,825000 €</span></td><td class="montant">1</td><td class="montant">0,82 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">0,82 €</td>', $tableau);
        $this->assertSame(['19.39', '1.60', '20.99'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * La note de calcul imprimée sous les lignes d'une facture à un
     * professionnel explique le calcul de chaque ligne et y ajoute la
     * phrase du PU HT d'un article et celle du PU HT exact (décision de
     * l'éditeur du 14/11/2026, modèle M6-b) ;
     * le pied de page garde toutes les mentions obligatoires, dans leur
     * ordre : l'identité de la société, les conditions de paiement, les
     * pénalités de retard, l'indemnité forfaitaire de 40 €, l'escompte et
     * la catégorie de l'opération.
     */
    public function test_la_note_de_calcul_explique_le_pu_ht_d_un_article_et_les_mentions_obligatoires_restent(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils', 'forme_juridique' => FormeJuridique::Sarl, 'capital_social' => '10000.00', 'capital_variable' => false,
            'siren' => '121212120', 'immatriculation' => 'RCS Marseille', 'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille', 'option_debits_tva' => false,
        ])->create(['nom' => 'Boutique du Port', 'adresse' => '12 quai des Pêcheurs', 'code_postal' => '13002', 'ville' => 'Marseille']));
        $produit = Produit::factory()->create(['prix_vente_ttc' => '65.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 4]], ['conditions_paiement' => 'Virement à 30 jours.']);

        $html = $this->compacte($this->rendu($facture));

        $this->assertStringContainsString('</table><div class="note-calcul">'.e(self::NOTE_DE_CALCUL_PU_HT_D_UN_ARTICLE).'</div>', $html);
        $this->assertSame([
            'Martin et Fils SARL au capital de 10 000,00 € · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120',
            'Virement à 30 jours.',
            'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.',
            'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
            'Pas d\'escompte pour paiement anticipé.',
            'Catégorie de l\'opération : Livraison de biens',
        ], $this->textesDuPied($html));
    }

    /**
     * Une vente sans TVA française se paie hors taxe : un ticket d'export
     * à un particulier comme la facture d'une livraison intracommunautaire
     * n'impriment que le PU HT et le total HT, au taux de 0 %, au modèle
     * M6-b quel que soit le client (décision de l'éditeur du 14/11/2026), et
     * leur note
     * de calcul ne cite ni « Prix catalogue TTC » ni « TVA retirée », des
     * colonnes que le PDF n'a plus (point 3). Le PU HT s'y imprime comme
     * celui d'un article, le prix catalogue TTC moins sa TVA arrondie au
     * centime (décision de l'éditeur du 14/11/2026). 3 articles à 7,00 €
     * TTC à 20 % : PU HT 5,83 €, 17,50 € HT ; 12 articles à 25,00 € : PU HT
     * 20,83 €, 250,00 € HT.
     */
    public function test_une_vente_sans_tva_francaise_ticket_ou_facture_n_imprime_que_les_colonnes_ht_et_sa_note_ne_cite_aucune_colonne_absente(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());
        $export = $this->emettre(Client::factory()->create(), [['produit_id' => $this->produitAVingtPourCent('EXP-01', '7.00')->id, 'quantite' => 3]], [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);
        $livraison = $this->emettre($this->clientAllemandVerifie(), [['produit_id' => $this->produitAVingtPourCent('LIC-01', '25.00')->id, 'quantite' => 12]], self::LIVRAISON_A_BERLIN);

        foreach ([[$export, '3</td><td class="montant">5,83 €</td><td class="montant">—</td><td class="montant">0 %</td><td class="montant total-de-la-ligne">17,50 €'], [$livraison, '12</td><td class="montant">20,83 €</td><td class="montant">—</td><td class="montant">0 %</td><td class="montant total-de-la-ligne">250,00 €']] as [$vente, $ligne]) {
            $html = $this->rendu($vente);

            $this->assertSame(['Réf.', 'Article', 'Qté', 'PU HT', 'Rem.', 'TVA', 'Total HT'], $this->entetes($this->tableauDesLignes($vente)), $vente->numero);
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
     * montant de l'avoir, puis le restant dû à l'émission (points 3 et 23,
     * Q5, constat C3).
     */
    public function test_les_reglements_de_l_emission_s_impriment_par_mode(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->ouvrirUneSociete(['code' => 'LYON1']);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $article = fn (float $prix): array => [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prix, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
        $premier = self::vendreAuComptant(self::auteur(), $comptoir, $article(20));
        $bon = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]]);

        $ticket = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, $article(80), new ReglementComptant([
            new PartDeReglement(ModePaiement::Especes, 3000),
            new PartDeReglement(ModePaiement::CarteBancaire, 3000),
        ], [$bon->numero]));

        $this->assertSame([
            ['09/10/2026', 'Espèces', '', '30,00 €'],
            ['09/10/2026', 'Carte bancaire', '', '30,00 €'],
            ['09/10/2026', 'Avoir', 'AV-LYON1-2026-000001', '20,00 €'],
            ['Restant dû à l\'émission', '0,00 €'],
        ], $this->reglementsImprimes($ticket));
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
            $this->texte($page, fn (string $texte): bool => $texte === 'NET À PAYER')['y'],
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
        $this->travelTo(self::aParis('2026-10-09 10:15:00'));
        $tableauDeLaTva = fn (string $motif, string $base): string => '<thead><tr><th>Taux</th><th>Motif</th><th class="montant">Base HT</th><th class="montant">TVA</th><th class="montant">TTC</th></tr></thead>'
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = self::vendreAuComptant(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Virement, 'VIR-42');

        $this->assertSame([['09/10/2026', 'Virement', 'VIR-42', '30,00 €'], ['Restant dû à l\'émission', '0,00 €']], $this->reglementsImprimes($facture));
        $this->assertStringContainsString('Échéance le 08/11/2026', $this->rendu($facture));
    }

    /**
     * Les règlements reçus à l'émission s'impriment à gauche du montant à
     * régler, sur la même bande, sous le récapitulatif par taux (modèle
     * M6-b, le même pour un particulier, choix de l'éditeur du 14/11/2026),
     * en colonnes Date, Mode, Référence et Montant, puis ce qui restait dû
     * à l'émission (constat C3) : chaque texte du bloc finit avant que le
     * montant à régler ne commence, le récapitulatif et le montant à régler
     * commencent à la même hauteur, en haut de la bande, et le bloc des
     * règlements sous le récapitulatif. Ticket de 62,40 € réglé 20,00 € en
     * espèces et 42,40 € par carte.
     */
    public function test_les_reglements_s_impriment_a_gauche_des_totaux_sur_la_meme_bande(): void
    {
        $ticket = $this->ticketDeLaCaisse(1, [
            new PartDeReglement(ModePaiement::Especes, 2000),
            new PartDeReglement(ModePaiement::CarteBancaire, 4240, 'CB-TRX-4521'),
        ]);

        $page = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($ticket)->output())[0];

        $totalHt = $this->texte($page, fn (string $texte): bool => $texte === 'Total HT');
        $recapitulatif = $this->texte($page, fn (string $texte): bool => $texte === 'Récapitulatif TVA');
        $montantARegler = $this->texte($page, fn (string $texte): bool => $texte === 'Montant à régler');
        $haut = $this->texte($page, fn (string $texte): bool => $texte === 'RÈGLEMENTS')['y'];
        $bas = $this->texte($page, fn (string $texte): bool => $texte === 'Restant dû à l\'émission')['y'];
        $surLaBande = array_values(array_filter($page['textes'], fn (array $texte): bool => $texte['y'] >= $bas - 0.5 && $texte['y'] <= $haut + 0.5));
        foreach (['RÈGLEMENTS', 'DATE', 'MODE', 'RÉFÉRENCE', 'MONTANT', 'Espèces', 'Carte bancaire', 'CB-TRX-4521', '20,00 €', '42,40 €', 'Restant dû à l\'émission', '0,00 €'] as $libelle) {
            $reglement = collect($surLaBande)->firstWhere('texte', $libelle);
            $this->assertNotNull($reglement, $libelle);
            $this->assertLessThan($totalHt['x'], $reglement['x'] + $reglement['largeur'], $libelle);
        }
        $this->assertCount(2, array_filter($surLaBande, fn (array $texte): bool => $texte['texte'] === '09/10/2026' && $totalHt['x'] > $texte['x'] + $texte['largeur']));
        $this->assertEqualsWithDelta($montantARegler['y'], $recapitulatif['y'], 4.0);
        $this->assertLessThan($recapitulatif['y'], $haut);
        $this->assertLessThan($montantARegler['x'], $recapitulatif['x']);
        $this->assertChaqueTexteTientEntreLesMarges($page);
    }

    /**
     * Une référence passe à la ligne entre deux mots, jamais au milieu d'un
     * mot, alors que dompdf coupe aussi un texte après un trait d'union
     * (avant ce lot, « CB- » puis « TRX-4521 ») : chaque mot de chaque
     * référence s'imprime d'un seul tenant, et la référence de 100
     * caractères de ce virement s'imprime sur plusieurs lignes de sa
     * colonne. Quatre modes et un bon d'avoir, cinq règlements, tiennent
     * sur la bande, entre les marges de la page.
     */
    public function test_une_reference_de_reglement_passe_a_la_ligne_entre_deux_mots_jamais_au_milieu_d_un_mot(): void
    {
        $virement = 'VIR SEPA 2026-10-09 TK-LYON1-2026-000002 ATELIER-DUPONT-SARL REGLEMENT-COMPLET-DE-LA-VENTE VUE MERCI';
        $this->assertSame(100, mb_strlen($virement));
        $ticket = $this->ticketDeLaCaisse(1, [
            new PartDeReglement(ModePaiement::Especes, 1000),
            new PartDeReglement(ModePaiement::CarteBancaire, 1000, 'CB-TRX-4521'),
            new PartDeReglement(ModePaiement::Cheque, 1000, 'CHQ 1234567 BNP PARIBAS'),
            new PartDeReglement(ModePaiement::Virement, 1240, $virement),
        ], bonDAvoir: true);

        $page = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($ticket)->output())[0];

        $mode = $this->texte($page, fn (string $texte): bool => $texte === 'MODE');
        $montant = $this->texte($page, fn (string $texte): bool => $texte === 'MONTANT');
        $reference = collect($page['textes'])->first(fn (array $texte): bool => $texte['texte'] === 'RÉFÉRENCE' && abs($texte['y'] - $mode['y']) < 1);
        $restantDu = $this->texte($page, fn (string $texte): bool => $texte === 'Restant dû à l\'émission');
        $colonne = collect($page['textes'])->filter(fn (array $texte): bool => $texte['x'] >= $reference['x'] - 0.5 && $texte['x'] < $montant['x'] && $texte['y'] < $mode['y'] && $texte['y'] > $restantDu['y']);
        $lignes = $colonne->groupBy(fn (array $texte): string => (string) $texte['y'])
            ->map(fn ($morceaux): string => trim(preg_replace('/\s+/', ' ', $morceaux->sortBy('x')->pluck('texte')->implode(' '))))
            ->values();
        $motsImprimes = $lignes->flatMap(fn (string $ligne): array => explode(' ', $ligne))->filter()->values()->all();
        $this->assertSame(['CB-TRX-4521', 'CHQ', '1234567', 'BNP', 'PARIBAS', ...explode(' ', $virement), 'AV-LYON1-2026-000001'], $motsImprimes);
        $this->assertGreaterThan(1, $lignes->filter(fn (string $ligne): bool => str_contains($virement, $ligne))->count());
        $this->assertLessThan($montant['x'], $colonne->max(fn (array $texte): float => $texte['x'] + $texte['largeur']));
        $this->assertSame(['Restant dû à l\'émission', '0,00 €'], $this->reglementsImprimes($ticket)[5]);
        $this->assertChaqueTexteTientEntreLesMarges($page);
    }

    /**
     * La bande des totaux est une seule rangée de tableau : quel que soit le
     * nombre de lignes qui la pousse vers le bas de la page, « Total HT »,
     * « NET À PAYER », le récapitulatif par taux, le bloc des règlements et
     * ce qui restait dû à
     * l'émission s'impriment sur la même page, au-dessus du pied (avant ce
     * lot, le bloc se coupait entre deux pages de 14 à 20 lignes, et le
     * tableau des totaux lui-même à 20 et 21 lignes).
     *
     * @return array<string, array{int}>
     */
    public static function nombresDeLignes(): array
    {
        return array_combine(array_map(fn (int $nombre): string => "{$nombre} lignes", range(14, 22, 2)), array_map(fn (int $nombre): array => [$nombre], range(14, 22, 2)));
    }

    #[DataProvider('nombresDeLignes')]
    public function test_la_bande_des_totaux_ne_se_coupe_jamais_entre_deux_pages(int $nombreDeLignes): void
    {
        $ticket = $this->ticketDeLaCaisse($nombreDeLignes, [
            new PartDeReglement(ModePaiement::Especes, 1000),
            new PartDeReglement(ModePaiement::CarteBancaire, 1000, 'CB-778899'),
            new PartDeReglement(ModePaiement::Cheque, 1000, 'CHQ 1234567 BNP PARIBAS'),
        ], resteEnVirement: 'VIR-42');

        $pages = $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($ticket)->output());

        $pageDe = fn (string $cherche): array => array_keys(array_filter($pages, fn (array $page): bool => in_array($cherche, array_column($page['textes'], 'texte'), true)));
        $this->assertCount(1, $pageDe('NET À PAYER'));
        foreach (['Total HT', 'Récapitulatif TVA', 'RÈGLEMENTS', 'Restant dû à l\'émission', 'VIR-42'] as $texte) {
            $this->assertSame($pageDe('NET À PAYER'), $pageDe($texte), $texte);
        }
        $this->assertLePiedEstSousLesLignes($pages[$pageDe('NET À PAYER')[0]], $this->textesDuPied($this->compacte($this->rendu($ticket))), 'Page '.($pageDe('NET À PAYER')[0] + 1).' sur '.count($pages));
    }

    /**
     * Le montant à régler garde sa place, au point près, qu'un ticket ait
     * des règlements ou non, et sur un avoir et un devis, qui n'en ont
     * jamais : la case de gauche de leur bande n'a que le récapitulatif par
     * taux. Son dernier libellé est « NET À PAYER » sur un ticket ou une
     * facture, « TOTAL TTC » sur un avoir ou un devis.
     */
    public function test_l_avoir_le_devis_et_un_document_sans_reglement_gardent_leurs_totaux_a_la_meme_place(): void
    {
        $paye = $this->ticketDeLaCaisse(1, [new PartDeReglement(ModePaiement::Especes, 6240)]);
        $client = Client::factory()->create();
        $article = [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
        $sansReglement = $this->emettre($client, $article);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $sansReglement->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, $article);
        $colonnes = fn (string $pdf): array => array_map(
            fn (array $libelles): float => $this->texte($this->pagesDuPdf($pdf)[0], fn (string $texte): bool => in_array($texte, $libelles, true))['x'],
            [['Total HT'], ['Total TVA'], ['NET À PAYER', 'TOTAL TTC']],
        );

        $attendues = $colonnes(app(FacturePdfGenerator::class)->generer($paye)->output());

        $this->assertSame($attendues, $colonnes(app(FacturePdfGenerator::class)->generer($sansReglement)->output()));
        $this->assertSame($attendues, $colonnes(app(AvoirPdfGenerator::class)->generer($avoir)->output()));
        $this->assertSame($attendues, $colonnes(app(DevisPdfGenerator::class)->generer($devis->load('client', 'lignes', 'magasin'))->output()));
        $this->assertStringNotContainsString('Règlements', app(AvoirPdfGenerator::class)->html($avoir));
        $this->assertStringNotContainsString('Règlements', app(FacturePdfGenerator::class)->html($sansReglement));
    }

    /**
     * Un ticket du client comptoir vendu à la caisse le 09/10/2026 à 10 h,
     * de $nombreDeLignes articles à 62,40 € TTC, réglé par ces parts, puis,
     * s'il le faut, par un virement de ce qui reste à payer, et par le bon
     * d'avoir de 20,00 € d'une vente précédente.
     *
     * @param  list<PartDeReglement>  $parts
     */
    private function ticketDeLaCaisse(int $nombreDeLignes, array $parts, bool $bonDAvoir = false, ?string $resteEnVirement = null): Facture
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->ouvrirUneSociete(['code' => 'LYON1']);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $bons = [];

        if ($bonDAvoir) {
            $premier = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
            $bons[] = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]])->numero;
        }

        $lignes = array_map(
            fn (int $rang): array => ['produit_id' => Produit::factory()->create(['reference' => "ART-{$rang}", 'nom' => "Article numéro {$rang}", 'prix_vente_ttc' => '62.40', 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
            range(1, $nombreDeLignes),
        );

        if ($resteEnVirement !== null) {
            $parts[] = new PartDeReglement(ModePaiement::Virement, 6240 * $nombreDeLignes - PartDeReglement::total($parts), $resteEnVirement);
        }

        return app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, $lignes, new ReglementComptant($parts, $bons));
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
