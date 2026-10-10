<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\View as VueBlade;
use Tests\TestCase;

/**
 * Un ticket, une facture ou un avoir émis se réimprime et se renvoie avec
 * l'émetteur qu'il a signé au registre fiscal, quoi que devienne ensuite
 * le magasin (voir FigeSonEmetteur), et l'e-mail qui le porte présente ce
 * même émetteur (voir DocumentMail) ; un brouillon et un devis impriment
 * le magasin tel qu'il est aujourd'hui.
 *
 * Le magasin y est d'abord la Boutique du Port (voir magasinDuPort()),
 * puis devient la Boutique des Quais, puis, quand un troisième émetteur
 * est nécessaire, la Boutique du Canal : chacune change tout ce qui se
 * change de son identité, de ses coordonnées, de son régime de TVA, de
 * son option pour les débits et de ses mentions.
 */
class EmetteurFigeTest extends TestCase
{
    use RefreshDatabase;

    private const PENALITES = 'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.';

    private const INDEMNITE = 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.';

    private const ESCOMPTE_DU_PORT = 'Pas d\'escompte pour paiement anticipé.';

    private const ESCOMPTE_DES_QUAIS = 'Escompte de 2 % pour paiement sous 10 jours.';

    private const ESCOMPTE_DU_CANAL = 'Escompte de 1 % pour paiement sous 8 jours.';

    private const CATEGORIE = 'Catégorie de l\'opération : Livraison de biens';

    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    /**
     * Le magasin du Port renonce à l'option pour les débits et propose un
     * escompte : tout ce qu'imprime un document change, le pays excepté
     * (le logiciel ne gère que les magasins établis en France).
     */
    private const IDENTITE_DES_QUAIS = [
        'nom' => 'Boutique des Quais',
        'raison_sociale' => 'Martin Frères',
        'forme_juridique' => 'SAS',
        'capital_social' => '50000.00',
        'siren' => '987654324',
        'siret' => '98765432400019',
        'immatriculation' => 'RCS Lyon',
        'numero_tva' => 'FR14987654324',
        'adresse' => '8 rue Neuve',
        'complement_adresse' => 'Bâtiment B',
        'code_postal' => '69002',
        'ville' => 'Lyon',
        'telephone' => '04 72 00 00 00',
        'email' => 'bonjour@boutique-des-quais.fr',
        'regime_tva' => RegimeTva::ReelNormalTrimestriel,
        'option_debits_tva' => false,
        'mention_escompte' => self::ESCOMPTE_DES_QUAIS,
    ];

    /**
     * Le magasin des Quais reprend l'option pour les débits.
     */
    private const IDENTITE_DU_CANAL = [
        'nom' => 'Boutique du Canal',
        'raison_sociale' => 'Martin Associés',
        'forme_juridique' => 'SA',
        'capital_social' => '80000.00',
        'siren' => '552032534',
        'siret' => '55203253400018',
        'immatriculation' => 'RCS Paris',
        'numero_tva' => 'FR27552032534',
        'adresse' => '3 quai de Valmy',
        'complement_adresse' => null,
        'code_postal' => '75010',
        'ville' => 'Paris',
        'telephone' => '01 40 00 00 00',
        'email' => 'contact@boutique-du-canal.fr',
        'regime_tva' => RegimeTva::ReelSimplifie,
        'option_debits_tva' => true,
        'mention_escompte' => self::ESCOMPTE_DU_CANAL,
    ];

    /**
     * L'en-tête de la Boutique du Port (voir magasinDuPort()).
     */
    private const EN_TETE_DU_PORT = '<div class="magasin-nom">Boutique du Port</div>'
        .'Martin et Fils SARL au capital de 10 000,00 €<br>12 quai des Pêcheurs<br>13002 Marseille<br>FR<br>'
        .'SIREN : 123456789<br>SIRET : 12345678900012<br>RCS Marseille<br>TVA intracommunautaire : FR32123456789<br>'
        .'Tél. 04 91 00 00 00 · contact@boutique-du-port.fr';

    /**
     * L'en-tête de la Boutique des Quais (voir IDENTITE_DES_QUAIS).
     */
    private const EN_TETE_DES_QUAIS = '<div class="magasin-nom">Boutique des Quais</div>'
        .'Martin Frères SAS au capital de 50 000,00 €<br>8 rue Neuve<br>Bâtiment B<br>69002 Lyon<br>FR<br>'
        .'SIREN : 987654324<br>SIRET : 98765432400019<br>RCS Lyon<br>TVA intracommunautaire : FR14987654324<br>'
        .'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr';

    public function test_le_pdf_d_une_facture_emise_garde_l_identite_et_les_mentions_du_jour_de_son_emission(): void
    {
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $html = app(FacturePdfGenerator::class)->html($facture->fresh());

        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($html));
        $this->assertSame([self::PENALITES, self::INDEMNITE, self::ESCOMPTE_DU_PORT, self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
        $this->assertSame(RegimeTva::ReelNormalMensuel, $facture->fresh()->emetteur()->regimeTva);
    }

    public function test_le_pdf_d_un_ticket_emis_garde_son_emetteur(): void
    {
        $magasin = $this->magasinDuPort();
        $ticket = $this->emettre(Client::factory()->create());
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($html));
        $this->assertSame([self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
    }

    /**
     * L'avoir signe son propre émetteur, celui du jour de son émission à
     * lui : ni celui de la facture qu'il corrige, émise avant un premier
     * changement d'identité, ni celui d'aujourd'hui, après un second. Sans
     * option pour les débits ce jour-là, il n'en porte pas la mention.
     */
    public function test_le_pdf_d_un_avoir_emis_garde_son_emetteur(): void
    {
        $magasin = $this->magasinDuPort();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1]]);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DU_CANAL);

        $htmlDeLAvoir = app(AvoirPdfGenerator::class)->html($avoir->fresh());
        $htmlDeLaFacture = app(FacturePdfGenerator::class)->html($facture->fresh());

        $this->assertSame(self::EN_TETE_DES_QUAIS, $this->enTete($htmlDeLAvoir));
        $this->assertSame([self::CATEGORIE], $this->pieds($htmlDeLAvoir));
        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($htmlDeLaFacture));
    }

    /**
     * Ni le brouillon, qui n'est pas encore signé, ni le devis, qui ne
     * l'est jamais, même envoyé, n'ont d'émetteur figé : ils impriment le
     * magasin, ses mentions et son option pour les débits d'aujourd'hui.
     */
    public function test_un_brouillon_et_un_devis_lisent_l_identite_actuelle(): void
    {
        $magasin = $this->magasinDuPort();
        $client = Client::factory()->professionnel()->create();
        $brouillon = app(CreerFactureAction::class)->handle($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $devis = app(EmettreDevisAction::class)->handle(app(CreerDevisAction::class)->handle($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $htmlDuBrouillon = app(FacturePdfGenerator::class)->html($brouillon->fresh());
        $htmlDuDevis = app(DevisPdfGenerator::class)->html($devis->fresh());

        $this->assertSame(self::EN_TETE_DES_QUAIS, $this->enTete($htmlDuBrouillon));
        $this->assertSame([self::PENALITES, self::INDEMNITE, self::ESCOMPTE_DES_QUAIS, self::CATEGORIE], $this->pieds($htmlDuBrouillon));
        $this->assertSame(self::EN_TETE_DES_QUAIS, $this->enTete($htmlDuDevis));
    }

    /**
     * Le PDF joint à l'e-mail se génère au départ de l'envoi : il imprime
     * l'émetteur que la facture a signé, pas le magasin du jour de
     * l'envoi, dont l'objet ne prend pas non plus le nom.
     */
    public function test_la_piece_jointe_d_un_envoi_reproduit_le_document_tel_qu_emis(): void
    {
        Mail::fake();
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $emetteursImprimes = $this->emetteursImprimesSur('factures.pdf');

        app(EnvoyerDocumentAction::class)->handle($facture->fresh(), 'compta@client.fr', null, null);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('compta@client.fr')
            && $mail->hasSubject("Facture {$facture->numero} — Boutique du Port")
            && $mail->attachments()[0]->as === "facture-{$facture->numero}.pdf");
        $this->assertSame([
            'nom' => 'Boutique du Port',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'capital_social' => 1000000,
            'siren' => '123456789',
            'siret' => '12345678900012',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => null,
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'email' => 'contact@boutique-du-port.fr',
            'telephone' => '04 91 00 00 00',
            'regime_tva' => 'reel_normal_mensuel',
            'option_debits_tva' => true,
            'mentions' => [
                'penalites_retard' => self::PENALITES,
                'indemnite_recouvrement' => self::INDEMNITE,
                'escompte' => self::ESCOMPTE_DU_PORT,
            ],
        ], $emetteursImprimes->sole()->enTableau());
    }

    /**
     * L'envoi est demandé avant le changement d'identité et part après lui
     * (file d'attente chargée, nouvelle tentative) : l'expéditeur, l'objet,
     * l'en-tête, la signature et le pied présentent le vendeur que la pièce
     * jointe imprime. Seuls les moyens de joindre le magasin (adresse de
     * réponse, téléphone et adresse e-mail du pied) sont ceux
     * d'aujourd'hui.
     */
    public function test_l_e_mail_d_un_document_emis_presente_l_emetteur_de_sa_piece_jointe_meme_parti_apres_un_changement(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $envoi = app(EnvoyerDocumentAction::class)->handle($facture, 'compta@client.fr', null, null);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $emetteursImprimes = $this->emetteursImprimesSur('factures.pdf');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $pieceJointe = $emetteursImprimes->sole();
        $this->assertSame(
            ['Boutique du Port', 'Martin et Fils', '12 quai des Pêcheurs', '13002', 'Marseille', '12345678900012'],
            [$pieceJointe->nom, $pieceJointe->raisonSociale, $pieceJointe->adresse, $pieceJointe->codePostal, $pieceJointe->ville, $pieceJointe->siret],
        );
        $this->assertSame([
            'expediteur' => 'Boutique du Port',
            'reponse' => ['bonjour@boutique-des-quais.fr', 'Boutique du Port'],
            'objet' => "Facture {$facture->numero} — Boutique du Port",
            'en_tete' => ['Boutique du Port'],
            'signature' => ['Cordialement, Boutique du Port'],
            'pied' => [
                'Martin et Fils · 12 quai des Pêcheurs · 13002 Marseille',
                'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr',
                'SIRET 12345678900012',
            ],
        ], $this->donneesDe(Mail::sent(DocumentMail::class)->sole()));
        $this->assertSame("Facture {$facture->numero} — Boutique du Port", $envoi->fresh()->objet);
    }

    /**
     * Un devis n'est jamais signé : demandé avant le changement d'identité
     * et parti après lui, son e-mail, objet compris, et son PDF présentent
     * le magasin du jour de son départ.
     */
    public function test_l_e_mail_d_un_devis_presente_le_magasin_du_jour_de_son_depart(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasinDuPort();
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $envoi = app(EnvoyerDocumentAction::class)->handle($devis, 'achats@client.fr', null, null);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $emetteursImprimes = $this->emetteursImprimesSur('devis.pdf');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $numero = $devis->fresh()->numero;
        $this->assertSame(['Boutique des Quais', '98765432400019'], [$emetteursImprimes->sole()->nom, $emetteursImprimes->sole()->siret]);
        $this->assertSame([
            'expediteur' => 'Boutique des Quais',
            'reponse' => ['bonjour@boutique-des-quais.fr', 'Boutique des Quais'],
            'objet' => "Devis {$numero} — Boutique des Quais",
            'en_tete' => ['Boutique des Quais'],
            'signature' => ['Cordialement, Boutique des Quais'],
            'pied' => [
                'Martin Frères · 8 rue Neuve · 69002 Lyon',
                'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr',
                'SIRET 98765432400019',
            ],
        ], $this->donneesDe(Mail::sent(DocumentMail::class)->sole()));
        $this->assertSame("Devis {$numero} — Boutique des Quais", $envoi->fresh()->objet);
    }

    /**
     * Un ticket numéroté directement en base, hors de son émission, n'a
     * aucun enregistrement signé : son PDF n'est pas reconstitué depuis le
     * magasin d'aujourd'hui.
     */
    public function test_un_document_emis_sans_enregistrement_signe_refuse_de_produire_son_pdf(): void
    {
        $magasin = $this->magasinDuPort();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-PORT-2026-000009', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);

        $this->assertThrows(
            fn () => app(FacturePdfGenerator::class)->generer($brouillon->fresh()),
            DocumentSansEnregistrementSigneException::class,
            'Le ticket TK-PORT-2026-000009 n\'a aucun enregistrement signé au registre fiscal : son PDF n\'est pas produit.',
        );
    }

    /**
     * Un magasin à l'identité légale complète, qui a opté pour le paiement
     * de la TVA d'après les débits.
     */
    private function magasinDuPort(): Magasin
    {
        $magasin = Magasin::factory()->create([
            'nom' => 'Boutique du Port',
            'code' => 'PORT',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'capital_social' => '10000.00',
            'siren' => '123456789',
            'siret' => '12345678900012',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => null,
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'telephone' => '04 91 00 00 00',
            'email' => 'contact@boutique-du-port.fr',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
            'option_debits_tva' => true,
        ]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * @param  array<string, mixed>  $identite
     */
    private function changerLIdentiteDuMagasin(Magasin $magasin, array $identite): void
    {
        $magasin->update($identite);
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function emettre(Client $client): Facture
    {
        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ]));
    }

    /**
     * Les émetteurs que la vue d'un PDF reçoit, dans l'ordre de ses rendus.
     *
     * @return Collection<int, Emetteur>
     */
    private function emetteursImprimesSur(string $vue): Collection
    {
        $emetteurs = collect();
        View::composer($vue, function (VueBlade $rendu) use ($emetteurs): void {
            $emetteurs->push($rendu->getData()['emetteur']);
        });

        return $emetteurs;
    }

    /**
     * Le bloc de l'émetteur dans l'en-tête, sans les blancs qui entourent
     * ses balises.
     */
    private function enTete(string $html): string
    {
        return Str::betweenFirst(preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html), '<table class="en-tete"><tr><td>', '</td>');
    }

    /**
     * Le texte de chaque mention en pied du document, dans l'ordre.
     *
     * @return list<string>
     */
    private function pieds(string $html): array
    {
        preg_match_all('/<div class="pied">(.*?)<\/div>/s', $html, $pieds);

        return array_map(fn (string $pied): string => html_entity_decode(trim($pied), ENT_QUOTES), $pieds[1]);
    }

    /**
     * Ce que l'e-mail rendu présente de son vendeur : le nom de
     * l'expéditeur, l'adresse de réponse et son nom, l'objet, puis le texte
     * de l'en-tête, de la signature et de chaque ligne du pied.
     *
     * @return array{expediteur: string, reponse: list<string>, objet: string, en_tete: list<string>, signature: list<string>, pied: list<string>}
     */
    private function donneesDe(DocumentMail $mail): array
    {
        $enveloppe = $mail->envelope();
        $page = new DOMDocument;
        $page->loadHTML('<?xml encoding="utf-8"?>'.$mail->render(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $chemins = new DOMXPath($page);
        $textes = fn (string $chemin): array => array_map(
            fn (DOMNode $noeud): string => trim(preg_replace('/\s+/u', ' ', $noeud->textContent)),
            iterator_to_array($chemins->query($chemin)),
        );

        return [
            'expediteur' => $enveloppe->from->name,
            'reponse' => [$enveloppe->replyTo[0]->address, $enveloppe->replyTo[0]->name],
            'objet' => $enveloppe->subject,
            'en_tete' => $textes('//td[@class="header"]'),
            'signature' => $textes('//p[starts-with(normalize-space(.), "Cordialement,")]'),
            'pied' => $textes('//table[@class="footer"]//p'),
        ];
    }
}
