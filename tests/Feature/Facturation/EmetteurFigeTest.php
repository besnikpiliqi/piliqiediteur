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
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\View as VueBlade;
use Tests\TestCase;

/**
 * Un ticket, une facture ou un avoir émis se réimprime et se renvoie avec
 * l'émetteur qu'il a signé au registre fiscal, quoi que devienne ensuite
 * le magasin (voir FigeSonEmetteur) ; un brouillon et un devis impriment
 * le magasin tel qu'il est aujourd'hui.
 */
class EmetteurFigeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'identité du magasin le jour de l'émission (voir magasinDuPort()),
     * telle que l'en-tête l'imprime.
     */
    private const EN_TETE_DU_JOUR_DE_L_EMISSION = '<div class="magasin-nom">Boutique du Port</div>'
        .'Martin et Fils SARL au capital de 10 000,00 €<br>12 quai des Pêcheurs<br>13002 Marseille<br>FR<br>'
        .'SIREN : 123456789<br>SIRET : 12345678900012<br>RCS Marseille<br>TVA intracommunautaire : FR32123456789<br>'
        .'Tél. 04 91 00 00 00 · contact@boutique-du-port.fr';

    /**
     * L'identité du magasin après son changement (voir
     * changerLIdentiteDuMagasin()), telle que l'en-tête l'imprime.
     */
    private const EN_TETE_D_AUJOURD_HUI = '<div class="magasin-nom">Boutique des Quais</div>'
        .'Martin Frères SAS au capital de 50 000,00 €<br>8 rue Neuve<br>69002 Lyon<br>FR<br>'
        .'SIREN : 123456789<br>SIRET : 12345678900020<br>RCS Lyon<br>TVA intracommunautaire : FR32123456789<br>'
        .'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr';

    private const PENALITES = 'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.';

    private const INDEMNITE = 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.';

    private const ESCOMPTE_DU_JOUR_DE_L_EMISSION = 'Pas d\'escompte pour paiement anticipé.';

    private const ESCOMPTE_D_AUJOURD_HUI = 'Escompte de 2 % pour paiement sous 10 jours.';

    private const CATEGORIE = 'Catégorie de l\'opération : Livraison de biens';

    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    public function test_le_pdf_d_une_facture_emise_garde_l_identite_et_les_mentions_du_jour_de_son_emission(): void
    {
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->changerLIdentiteDuMagasin($magasin);

        $html = app(FacturePdfGenerator::class)->html($facture->fresh());

        $this->assertSame(self::EN_TETE_DU_JOUR_DE_L_EMISSION, $this->enTete($html));
        $this->assertSame([self::PENALITES, self::INDEMNITE, self::ESCOMPTE_DU_JOUR_DE_L_EMISSION, self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
        $this->assertSame(RegimeTva::ReelNormalMensuel, $facture->fresh()->emetteur()->regimeTva);
    }

    public function test_le_pdf_d_un_ticket_emis_garde_son_emetteur(): void
    {
        $magasin = $this->magasinDuPort();
        $ticket = $this->emettre(Client::factory()->create());
        $this->changerLIdentiteDuMagasin($magasin);

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertSame(self::EN_TETE_DU_JOUR_DE_L_EMISSION, $this->enTete($html));
        $this->assertSame([self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
    }

    public function test_le_pdf_d_un_avoir_emis_garde_son_emetteur(): void
    {
        $magasin = $this->magasinDuPort();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client);
        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1]]);
        $this->changerLIdentiteDuMagasin($magasin);

        $html = app(AvoirPdfGenerator::class)->html($avoir->fresh());

        $this->assertSame(self::EN_TETE_DU_JOUR_DE_L_EMISSION, $this->enTete($html));
        $this->assertSame([self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
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
        $this->changerLIdentiteDuMagasin($magasin);

        $htmlDuBrouillon = app(FacturePdfGenerator::class)->html($brouillon->fresh());
        $htmlDuDevis = app(DevisPdfGenerator::class)->html($devis->fresh());

        $this->assertSame(self::EN_TETE_D_AUJOURD_HUI, $this->enTete($htmlDuBrouillon));
        $this->assertSame([self::PENALITES, self::INDEMNITE, self::ESCOMPTE_D_AUJOURD_HUI, self::CATEGORIE], $this->pieds($htmlDuBrouillon));
        $this->assertSame(self::EN_TETE_D_AUJOURD_HUI, $this->enTete($htmlDuDevis));
    }

    /**
     * Le PDF joint à l'e-mail se génère au départ de l'envoi : il imprime
     * l'émetteur que la facture a signé, pas le magasin du jour de
     * l'envoi.
     */
    public function test_la_piece_jointe_d_un_envoi_reproduit_le_document_tel_qu_emis(): void
    {
        Mail::fake();
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->changerLIdentiteDuMagasin($magasin);
        $emetteursImprimes = [];
        View::composer('factures.pdf', function (VueBlade $vue) use (&$emetteursImprimes): void {
            $emetteursImprimes[] = $vue->getData()['emetteur'];
        });

        app(EnvoyerDocumentAction::class)->handle($facture->fresh(), 'compta@client.fr', null, null);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('compta@client.fr')
            && $mail->attachments()[0]->as === "facture-{$facture->numero}.pdf");
        $this->assertCount(1, $emetteursImprimes);
        $this->assertInstanceOf(Emetteur::class, $emetteursImprimes[0]);
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
                'escompte' => self::ESCOMPTE_DU_JOUR_DE_L_EMISSION,
            ],
        ], $emetteursImprimes[0]->enTableau());
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
     * Le magasin change de nom, de raison sociale, de forme juridique, de
     * capital, de SIRET, d'immatriculation, d'adresse, de coordonnées, de
     * régime de TVA, renonce à l'option pour les débits et propose
     * désormais un escompte.
     */
    private function changerLIdentiteDuMagasin(Magasin $magasin): void
    {
        $magasin->update([
            'nom' => 'Boutique des Quais',
            'raison_sociale' => 'Martin Frères',
            'forme_juridique' => 'SAS',
            'capital_social' => '50000.00',
            'siret' => '12345678900020',
            'immatriculation' => 'RCS Lyon',
            'adresse' => '8 rue Neuve',
            'code_postal' => '69002',
            'ville' => 'Lyon',
            'telephone' => '04 72 00 00 00',
            'email' => 'bonjour@boutique-des-quais.fr',
            'regime_tva' => RegimeTva::ReelNormalTrimestriel,
            'option_debits_tva' => false,
            'mention_escompte' => self::ESCOMPTE_D_AUJOURD_HUI,
        ]);
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
}
