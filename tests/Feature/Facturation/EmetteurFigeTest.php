<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Exceptions\IdentiteDeLaSocieteIncompleteException;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\MentionsFacultativesDeLIdentite;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\FormeJuridique;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un ticket, une facture ou un avoir émis se réimprime et se renvoie avec
 * l'émetteur qu'il a signé au registre fiscal, quoi que deviennent ensuite
 * la société et le magasin (voir FigeSonEmetteur), et l'e-mail qui le
 * porte présente ce même émetteur (voir DocumentMail) ; un devis fige le
 * sien à son envoi (voir Devis::emetteur()) ; un brouillon imprime la
 * société et le magasin tels qu'ils sont aujourd'hui.
 *
 * Le magasin y est d'abord la Boutique du Port (voir magasinDuPort()),
 * puis devient la Boutique des Quais, puis, quand un troisième émetteur
 * est nécessaire, la Boutique du Canal : chacune change tout ce qui se
 * change de l'identité de la société (dénomination, forme, capital,
 * immatriculation, n° de TVA, siège, assurance professionnelle), de ses
 * réglages fiscaux, et des
 * coordonnées et des mentions du magasin. Le SIREN d'une société validée
 * et le SIRET d'un magasin, eux, ne se changent pas ici.
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
     * L'assurance professionnelle de la société du Port (voir
     * magasinDuPort()), sous son identité dans le pied de chaque page.
     */
    private const ASSURANCE_DU_PORT = 'Assurance professionnelle : contrat n° RC-2026-118, Mutuelle du Littoral, 4 rue Paradis, 13001 Marseille, couverture : France métropolitaine';

    /**
     * L'assurance professionnelle de la société des Quais (voir
     * IDENTITE_DES_QUAIS).
     */
    private const ASSURANCE_DES_QUAIS = 'Assurance professionnelle : contrat n° DEC-77-0042, Assurances du Rhône, 10 quai Saint-Antoine, 69002 Lyon, couverture : France et Union européenne';

    /**
     * Ce que règle la société, et non le magasin, dans une identité.
     */
    private const CHAMPS_DE_LA_SOCIETE = [
        'siren', 'raison_sociale', 'forme_juridique', 'forme_juridique_autre', 'capital_social', 'capital_variable', 'immatriculation', 'numero_tva',
        'siege_adresse', 'siege_complement_adresse', 'siege_code_postal', 'siege_ville', 'regime_tva', 'option_debits_tva',
        'assurance_assureur', 'assurance_adresse', 'assurance_contrat', 'assurance_couverture',
        'en_liquidation', 'exploitation_en_gerance', 'eirl', 'eirl_objet', 'membre_assujetti_unique',
    ];

    /**
     * La société du Port renonce à l'option pour les débits et le magasin
     * propose un escompte : tout ce qu'imprime un document et qui se
     * change ici change, le pays excepté (le logiciel ne gère que les
     * magasins établis en France).
     */
    private const IDENTITE_DES_QUAIS = [
        'nom' => 'Boutique des Quais',
        'raison_sociale' => 'Martin Frères',
        'forme_juridique' => FormeJuridique::Sas,
        'capital_social' => '50000.00',
        'immatriculation' => 'RCS Lyon',
        'numero_tva' => 'FR14987654324',
        'siege_adresse' => '20 cours Lafayette',
        'siege_code_postal' => '69003',
        'siege_ville' => 'Lyon',
        'adresse' => '8 rue Neuve',
        'complement_adresse' => 'Bâtiment B',
        'code_postal' => '69002',
        'ville' => 'Lyon',
        'telephone' => '04 72 00 00 00',
        'email' => 'bonjour@boutique-des-quais.fr',
        'regime_tva' => RegimeTva::ReelNormalTrimestriel,
        'option_debits_tva' => false,
        'assurance_assureur' => 'Assurances du Rhône',
        'assurance_adresse' => '10 quai Saint-Antoine, 69002 Lyon',
        'assurance_contrat' => 'DEC-77-0042',
        'assurance_couverture' => 'France et Union européenne',
        'mention_escompte' => self::ESCOMPTE_DES_QUAIS,
    ];

    /**
     * La société des Quais reprend l'option pour les débits.
     */
    private const IDENTITE_DU_CANAL = [
        'nom' => 'Boutique du Canal',
        'raison_sociale' => 'Martin Associés',
        'forme_juridique' => FormeJuridique::Sa,
        'capital_social' => '80000.00',
        'immatriculation' => 'RCS Paris',
        'numero_tva' => 'FR27552032534',
        'siege_adresse' => '5 rue de Lancry',
        'siege_code_postal' => '75010',
        'siege_ville' => 'Paris',
        'adresse' => '3 quai de Valmy',
        'complement_adresse' => null,
        'code_postal' => '75010',
        'ville' => 'Paris',
        'telephone' => '01 40 00 00 00',
        'email' => 'contact@boutique-du-canal.fr',
        'regime_tva' => RegimeTva::ReelSimplifie,
        'option_debits_tva' => true,
        'assurance_assureur' => 'Garantie Parisienne',
        'assurance_adresse' => '2 rue de la Paix, 75002 Paris',
        'assurance_contrat' => 'GP-2027-9',
        'assurance_couverture' => 'France',
        'mention_escompte' => self::ESCOMPTE_DU_CANAL,
    ];

    /**
     * L'en-tête de la Boutique du Port (voir magasinDuPort()) : le magasin
     * qui vend.
     */
    private const EN_TETE_DU_PORT = '<div class="magasin-nom">Boutique du Port</div>'
        .'12 quai des Pêcheurs<br>13002 Marseille<br>FR<br>SIRET : 12121212000012<br>'
        .'Tél. 04 91 00 00 00 · contact@boutique-du-port.fr';

    /**
     * L'identité de la société de la Boutique du Port, dans le pied de
     * chaque page.
     */
    private const SOCIETE_DU_PORT = 'Martin et Fils SARL au capital de 10 000,00 € · Siège social : 1 rue de la République, 13001 Marseille · '
        .'SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120';

    /**
     * L'en-tête de la Boutique des Quais (voir IDENTITE_DES_QUAIS).
     */
    private const EN_TETE_DES_QUAIS = '<div class="magasin-nom">Boutique des Quais</div>'
        .'8 rue Neuve<br>Bâtiment B<br>69002 Lyon<br>FR<br>SIRET : 12121212000012<br>'
        .'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr';

    /**
     * L'identité de la société de la Boutique des Quais, dans le pied de
     * chaque page.
     */
    private const SOCIETE_DES_QUAIS = 'Martin Frères SAS au capital de 50 000,00 € · Siège social : 20 cours Lafayette, 69003 Lyon · '
        .'SIREN : 121212120 · RCS Lyon · TVA intracommunautaire : FR14987654324';

    public function test_le_pdf_d_une_facture_emise_garde_l_identite_et_les_mentions_du_jour_de_son_emission(): void
    {
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $html = app(FacturePdfGenerator::class)->html($facture->fresh());

        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($html));
        $this->assertSame(self::SOCIETE_DU_PORT, $this->societe($html));
        $this->assertSame([self::ASSURANCE_DU_PORT, self::PENALITES, self::INDEMNITE, self::ESCOMPTE_DU_PORT, self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
        $this->assertSame(RegimeTva::ReelNormalMensuel, $facture->fresh()->emetteur()->regimeTva);
    }

    public function test_le_pdf_d_un_ticket_emis_garde_son_emetteur(): void
    {
        $magasin = $this->magasinDuPort();
        $ticket = $this->emettre(Client::factory()->create());
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($html));
        $this->assertSame(self::SOCIETE_DU_PORT, $this->societe($html));
        $this->assertSame([self::ASSURANCE_DU_PORT, self::CATEGORIE, self::OPTION_DEBITS], $this->pieds($html));
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
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1]]);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DU_CANAL);

        $htmlDeLAvoir = app(AvoirPdfGenerator::class)->html($avoir->fresh());
        $htmlDeLaFacture = app(FacturePdfGenerator::class)->html($facture->fresh());

        $this->assertSame([self::EN_TETE_DES_QUAIS, self::SOCIETE_DES_QUAIS], [$this->enTete($htmlDeLAvoir), $this->societe($htmlDeLAvoir)]);
        $this->assertSame([self::ASSURANCE_DES_QUAIS, self::CATEGORIE], $this->pieds($htmlDeLAvoir));
        $this->assertSame([self::EN_TETE_DU_PORT, self::SOCIETE_DU_PORT], [$this->enTete($htmlDeLaFacture), $this->societe($htmlDeLaFacture)]);
    }

    /**
     * Le brouillon, qui n'est pas encore émis, n'a pas d'émetteur figé :
     * il imprime le magasin, ses mentions et son option pour les débits
     * d'aujourd'hui. Le devis, jamais signé, fige le sien à son envoi et
     * l'imprime ensuite tel quel (règle 7 de la décision du 24/10/2026 :
     * « Un devis le fige avec le reste de son émetteur »).
     */
    public function test_un_brouillon_lit_l_identite_actuelle_et_un_devis_envoye_garde_celle_de_son_envoi(): void
    {
        $magasin = $this->magasinDuPort();
        $client = Client::factory()->professionnel()->create();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $htmlDuBrouillon = app(FacturePdfGenerator::class)->html($brouillon->fresh());
        $htmlDuDevis = app(DevisPdfGenerator::class)->html($devis->fresh());

        $this->assertSame([self::EN_TETE_DES_QUAIS, self::SOCIETE_DES_QUAIS], [$this->enTete($htmlDuBrouillon), $this->societe($htmlDuBrouillon)]);
        $this->assertSame([self::ASSURANCE_DES_QUAIS, self::PENALITES, self::INDEMNITE, self::ESCOMPTE_DES_QUAIS, self::CATEGORIE], $this->pieds($htmlDuBrouillon));
        $this->assertSame([self::EN_TETE_DU_PORT, self::SOCIETE_DU_PORT], [$this->enTete($htmlDuDevis), $this->societe($htmlDuDevis)]);
        $this->assertSame(self::ASSURANCE_DU_PORT, $this->pieds($htmlDuDevis)[0]);
    }

    /**
     * Modifié après son envoi, le devis repasse en brouillon : il perd
     * l'émetteur de son envoi et imprime celui d'aujourd'hui ; renvoyé, il
     * fige celui de ce nouvel envoi, qu'un changement suivant ne touche
     * plus. Un brouillon jamais envoyé n'a rien de figé.
     */
    public function test_un_devis_repasse_en_brouillon_perd_son_emetteur_fige_et_fige_celui_de_son_nouvel_envoi(): void
    {
        $magasin = $this->magasinDuPort();
        $client = Client::factory()->professionnel()->create();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->assertNull($devis->fresh()->emetteur_fige);
        app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
        $this->assertSame('Boutique du Port', $devis->fresh()->emetteur_fige['nom']);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis->fresh(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 2]]);
        $htmlDuBrouillon = app(DevisPdfGenerator::class)->html($modifie);
        app(EmettreDevisAction::class)->handle(self::auteur(), $modifie);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DU_CANAL);
        $htmlDuRenvoi = app(DevisPdfGenerator::class)->html($devis->fresh());

        $this->assertSame([StatutDevis::Brouillon, null], [$modifie->statut, $modifie->emetteur_fige]);
        $this->assertSame([self::EN_TETE_DES_QUAIS, self::SOCIETE_DES_QUAIS, self::ASSURANCE_DES_QUAIS], [$this->enTete($htmlDuBrouillon), $this->societe($htmlDuBrouillon), $this->pieds($htmlDuBrouillon)[0]]);
        $this->assertSame([self::EN_TETE_DES_QUAIS, self::SOCIETE_DES_QUAIS, self::ASSURANCE_DES_QUAIS], [$this->enTete($htmlDuRenvoi), $this->societe($htmlDuRenvoi), $this->pieds($htmlDuRenvoi)[0]]);
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

        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture->fresh(), 'compta@client.fr', null);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('compta@client.fr')
            && $mail->hasSubject("Facture {$facture->numero} — Boutique du Port")
            && $mail->attachments()[0]->as === "facture-{$facture->numero}.pdf");
        $this->assertSame([
            'nom' => 'Boutique du Port',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'entrepreneur_individuel' => false,
            'capital_social' => 1000000,
            'capital_variable' => false,
            'siren' => '121212120',
            'siret' => '12121212000012',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => null,
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'siege' => [
                'adresse' => '1 rue de la République',
                'complement_adresse' => null,
                'code_postal' => '13001',
                'ville' => 'Marseille',
            ],
            'email' => 'contact@boutique-du-port.fr',
            'telephone' => '04 91 00 00 00',
            'regime_tva' => 'reel_normal_mensuel',
            'option_debits_tva' => true,
            'mentions' => [
                'penalites_retard' => self::PENALITES,
                'indemnite_recouvrement' => self::INDEMNITE,
                'escompte' => self::ESCOMPTE_DU_PORT,
            ],
            'assurance' => [
                'assureur' => 'Mutuelle du Littoral',
                'adresse' => '4 rue Paradis, 13001 Marseille',
                'contrat' => 'RC-2026-118',
                'couverture' => 'France métropolitaine',
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
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'compta@client.fr', null);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $emetteursImprimes = $this->emetteursImprimesSur('factures.pdf');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $pieceJointe = $emetteursImprimes->sole();
        $this->assertSame(
            ['Boutique du Port', 'Martin et Fils', '12 quai des Pêcheurs', '13002', 'Marseille', '12121212000012'],
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
                'SIRET 12121212000012',
            ],
        ], $this->donneesDe(Mail::sent(DocumentMail::class)->sole()));
        $this->assertSame("Facture {$facture->numero} — Boutique du Port", $envoi->fresh()->objet);
    }

    /**
     * Le devis fige son émetteur à son envoi : demandé avant le changement
     * d'identité et parti après lui (file d'attente chargée, nouvelle
     * tentative), son e-mail, objet compris, et son PDF présentent le
     * vendeur du jour de son envoi, comme ceux d'une facture émise. Seuls
     * les moyens de joindre le magasin sont ceux d'aujourd'hui.
     */
    public function test_l_e_mail_d_un_devis_presente_l_emetteur_fige_a_son_envoi_meme_parti_apres_un_changement(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasinDuPort();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'achats@client.fr', null);
        $this->changerLIdentiteDuMagasin($magasin, self::IDENTITE_DES_QUAIS);
        $emetteursImprimes = $this->emetteursImprimesSur('devis.pdf');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $numero = $devis->fresh()->numero;
        $this->assertSame(['Boutique du Port', 'Martin et Fils', self::ASSURANCE_DU_PORT], [$emetteursImprimes->sole()->nom, $emetteursImprimes->sole()->raisonSociale, $emetteursImprimes->sole()->ligneDeLAssurance()]);
        $this->assertSame([
            'expediteur' => 'Boutique du Port',
            'reponse' => ['bonjour@boutique-des-quais.fr', 'Boutique du Port'],
            'objet' => "Devis {$numero} — Boutique du Port",
            'en_tete' => ['Boutique du Port'],
            'signature' => ['Cordialement, Boutique du Port'],
            'pied' => [
                'Martin et Fils · 12 quai des Pêcheurs · 13002 Marseille',
                'Tél. 04 72 00 00 00 · bonjour@boutique-des-quais.fr',
                'SIRET 12121212000012',
            ],
        ], $this->donneesDe(Mail::sent(DocumentMail::class)->sole()));
        $this->assertSame("Devis {$numero} — Boutique du Port", $envoi->fresh()->objet);
    }

    /**
     * Le siège, la forme et le capital de la société se figent avec
     * l'émetteur (S3) : la facture émise avant que la société devienne
     * une SAS à capital variable et transfère son siège les réimprime tels
     * qu'ils étaient.
     */
    public function test_le_pdf_d_une_facture_emise_garde_le_siege_la_forme_et_le_capital_du_jour_de_son_emission(): void
    {
        $magasin = $this->magasinDuPort();
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->reglerLaSociete($magasin, [
            'forme_juridique' => FormeJuridique::Sas,
            'capital_variable' => true,
            'capital_social' => '25000.00',
            'siege_adresse' => '2 boulevard Longchamp',
            'siege_code_postal' => '13004',
            'siege_ville' => 'Marseille',
        ]);

        $html = app(FacturePdfGenerator::class)->html($facture->fresh());
        $htmlDUnBrouillon = app(FacturePdfGenerator::class)->html(app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]));

        $this->assertSame(self::SOCIETE_DU_PORT, $this->societe($html));
        $this->assertStringStartsWith('Martin et Fils SAS à capital variable, au capital de 25 000,00 € · Siège social : 2 boulevard Longchamp, 13004 Marseille · ', $this->societe($htmlDUnBrouillon));
    }

    /**
     * Un siège à l'adresse du magasin, à la casse, aux accents et à la
     * ponctuation près, ne se répète pas : l'adresse du magasin s'imprime
     * en tête, et l'identité de la société, dans le pied, dit sans la
     * répéter qu'elle est aussi celle du siège.
     */
    public function test_le_siege_identique_a_l_adresse_du_magasin_n_est_imprime_qu_une_fois(): void
    {
        $this->magasinDuPort(['siege_adresse' => '12, Quai des pecheurs', 'siege_code_postal' => '13002', 'siege_ville' => 'MARSEILLE']);
        $ticket = $this->emettre(Client::factory()->create());

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertStringContainsString('</div>12 quai des Pêcheurs<br>13002 Marseille<br>FR<br>SIRET : 12121212000012<br>', $this->enTete($html));
        $this->assertSame('Martin et Fils SARL au capital de 10 000,00 € · Siège social et établissement · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120', $this->societe($html));
    }

    /**
     * Un entrepreneur individuel n'a pas de capital : sa dénomination
     * porte la mention « EI » (S5), et c'est l'adresse de son entreprise,
     * et non un siège social, qui s'imprime.
     */
    public function test_un_entrepreneur_individuel_imprime_la_mention_ei_son_adresse_d_entreprise_et_aucun_capital(): void
    {
        $this->magasinDuPort([
            'raison_sociale' => 'Jean Martin',
            'forme_juridique' => FormeJuridique::EntrepreneurIndividuel,
            'capital_social' => null,
            'immatriculation' => 'RM 13',
        ]);
        $ticket = $this->emettre(Client::factory()->create());

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertSame(self::EN_TETE_DU_PORT, $this->enTete($html));
        $this->assertSame('Jean Martin EI · Adresse de l\'entreprise : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RM 13 · TVA intracommunautaire : FR56121212120', $this->societe($html));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function denominationsDEntrepreneurs(): array
    {
        return [
            'sans la mention' => ['Jean Martin', 'Jean Martin EI'],
            'la mention en fin' => ['Jean Martin EI', 'Jean Martin EI'],
            'la mention en tête' => ['EI Jean Martin', 'EI Jean Martin'],
            'la mention en minuscules' => ['Jean Martin ei', 'Jean Martin ei'],
            'la mention en toutes lettres' => ['Jean Martin Entrepreneur individuel', 'Jean Martin Entrepreneur individuel'],
            'la mention entre parenthèses' => ['Jean Martin (EI)', 'Jean Martin (EI)'],
            'la mention suivie d\'un point' => ['Jean Martin EI.', 'Jean Martin EI.'],
            'la mention après une virgule' => ['Jean Martin,EI', 'Jean Martin,EI'],
            'la mention EIRL en fin (P14.4)' => ['Garage Martin EIRL', 'Garage Martin EIRL'],
            'la mention EIRL en tête' => ['EIRL Garage Martin', 'EIRL Garage Martin'],
            'la mention EIRL entre parenthèses' => ['Garage Martin (EIRL)', 'Garage Martin (EIRL)'],
            'la mention EIRL en minuscules' => ['Garage Martin eirl', 'Garage Martin eirl'],
            'la mention EIRL en toutes lettres' => ['Garage Martin Entrepreneur individuel à responsabilité limitée', 'Garage Martin Entrepreneur individuel à responsabilité limitée'],
        ];
    }

    /**
     * La mention « EI » s'ajoute juste après le nom quand la dénomination
     * ne la porte pas déjà en mot entier, en tête ou en fin, abrégée ou en
     * toutes lettres, entourée d'espaces ou de signes ; elle ne se double
     * jamais.
     */
    #[DataProvider('denominationsDEntrepreneurs')]
    public function test_la_mention_ei_est_ajoutee_quand_elle_manque_et_jamais_doublee(string $raisonSociale, string $denominationImprimee): void
    {
        $magasin = $this->magasinDuPort(['raison_sociale' => $raisonSociale, 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null]);

        $this->assertSame($denominationImprimee, Emetteur::duMagasin($magasin)->denominationImprimee());
    }

    /**
     * « EI » au milieu d'un mot (SEIGNEUR, Peinture) n'est pas la mention :
     * elle s'ajoute ; et une autre forme que l'entrepreneur individuel ne
     * la reçoit jamais.
     */
    public function test_une_sous_chaine_ei_dans_un_mot_ne_vaut_pas_mention(): void
    {
        $seigneur = $this->magasinDuPort(['raison_sociale' => 'SEIGNEUR', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null]);
        $peinture = $this->magasinDuPort(['code' => 'PORT2', 'raison_sociale' => 'Peinture Dupont', 'siren' => '100000009', 'siret' => '10000000900017', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null]);
        $societe = $this->magasinDuPort(['code' => 'PORT3', 'raison_sociale' => 'SEIGNEUR', 'siren' => '200000008', 'siret' => '20000000800017']);

        $this->assertSame(
            ['SEIGNEUR EI', 'Peinture Dupont EI', 'SEIGNEUR'],
            [Emetteur::duMagasin($seigneur)->denominationImprimee(), Emetteur::duMagasin($peinture)->denominationImprimee(), Emetteur::duMagasin($societe)->denominationImprimee()],
        );
    }

    /**
     * Un EIRL (décision P14.4) porte « EIRL » à la place de « EI » dans sa
     * dénomination, que la case soit cochée ou que sa raison sociale la
     * porte déjà, et « EI » n'est jamais ajouté à un nom qui porte « EIRL ».
     */
    public function test_un_eirl_imprime_eirl_a_la_place_de_ei(): void
    {
        $eirl = $this->magasinDuPort(['raison_sociale' => 'Jean Martin', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null, 'eirl' => true, 'eirl_objet' => 'Vente de plantes et de fleurs']);
        $dejaEirl = $this->magasinDuPort(['code' => 'PORT2', 'raison_sociale' => 'Garage Martin EIRL', 'siren' => '100000009', 'siret' => '10000000900017', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null, 'eirl' => true, 'eirl_objet' => 'Réparation automobile']);
        $sansCase = $this->magasinDuPort(['code' => 'PORT3', 'raison_sociale' => 'Jean Martin', 'siren' => '200000008', 'siret' => '20000000800017', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'capital_social' => null]);

        $this->assertSame(
            ['Jean Martin EIRL', 'Garage Martin EIRL', 'Jean Martin EI'],
            [Emetteur::duMagasin($eirl)->denominationImprimee(), Emetteur::duMagasin($dejaEirl)->denominationImprimee(), Emetteur::duMagasin($sansCase)->denominationImprimee()],
        );
    }

    /**
     * Le capital variable s'imprime dès que la case est cochée, même sans
     * capital saisi (décision P14.1, art. L231-2 et L238-3), puis avec lui
     * quand il l'est ; sans la case, rien ne le dit. Une SNC n'imprime son
     * capital que s'il est saisi (décision P14.3).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function capitauxImprimes(): array
    {
        return [
            'capital variable sans capital' => [['capital_variable' => true, 'capital_social' => null], 'Martin et Fils SARL à capital variable'],
            'capital variable avec capital' => [['capital_variable' => true, 'capital_social' => '8000.00'], 'Martin et Fils SARL à capital variable, au capital de 8 000,00 €'],
            'capital fixe' => [['capital_variable' => false, 'capital_social' => '8000.00'], 'Martin et Fils SARL au capital de 8 000,00 €'],
            'SNC sans capital' => [['forme_juridique' => FormeJuridique::Snc, 'capital_social' => null], 'Martin et Fils SNC'],
            'SNC avec capital' => [['forme_juridique' => FormeJuridique::Snc, 'capital_social' => '3000.00'], 'Martin et Fils SNC au capital de 3 000,00 €'],
            'société en liquidation à capital variable' => [['en_liquidation' => true, 'capital_variable' => true, 'capital_social' => '8000.00'], 'Martin et Fils SARL société en liquidation, à capital variable, au capital de 8 000,00 €'],
        ];
    }

    /**
     * @param  array<string, mixed>  $societe
     */
    #[DataProvider('capitauxImprimes')]
    public function test_la_premiere_ligne_du_pied_dit_la_forme_les_mentions_et_le_capital_que_la_societe_declare(array $societe, string $premiereLigne): void
    {
        $magasin = $this->magasinDuPort($societe);

        $this->assertSame($premiereLigne, Emetteur::duMagasin($magasin)->lignesDeLaSociete()[0]);
    }

    /**
     * Un document émis par une société à capital variable sans capital
     * imprime « à capital variable » sans montant, et le garde quand la
     * société change : le capital variable se fige avec l'émetteur.
     */
    public function test_le_capital_variable_sans_montant_s_imprime_et_se_fige_avec_l_emetteur(): void
    {
        $magasin = $this->magasinDuPort(['capital_variable' => true, 'capital_social' => null]);
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $this->reglerLaSociete($magasin, ['capital_variable' => false, 'capital_social' => '25000.00']);

        $html = app(FacturePdfGenerator::class)->html($facture->fresh());
        $htmlDUnBrouillon = app(FacturePdfGenerator::class)->html(app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]));

        $this->assertSame('Martin et Fils SARL à capital variable · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120', $this->societe($html));
        $this->assertStringStartsWith('Martin et Fils SARL au capital de 25 000,00 € · ', $this->societe($htmlDUnBrouillon));
    }

    /**
     * La facture à un professionnel d'une SNC s'émet sans capital, que sa
     * forme n'exige pas (décision P14.3), et le pied n'en imprime aucun ;
     * celle d'une SARL à capital fixe sans capital attend toujours qu'il soit
     * saisi.
     */
    public function test_une_snc_emet_sa_facture_a_un_professionnel_sans_capital_et_une_sarl_a_capital_fixe_l_attend(): void
    {
        $snc = $this->magasinDuPort(['forme_juridique' => FormeJuridique::Snc, 'capital_social' => null]);
        $facture = $this->emettre(Client::factory()->professionnel()->create());

        $this->assertNotNull($facture->numero);
        $this->assertStringStartsWith('Martin et Fils SNC · Siège social : ', $this->societe(app(FacturePdfGenerator::class)->html($facture->fresh())));

        $this->reglerLaSociete($snc, ['forme_juridique' => FormeJuridique::Sarl]);

        $this->assertThrows(
            fn () => $this->emettre(Client::factory()->professionnel()->create()),
            IdentiteDeLaSocieteIncompleteException::class,
            'Pour émettre une facture à un professionnel, complétez d\'abord l\'identité de la société (Administration de la société > Identité) : capital social.',
        );
    }

    /**
     * Les mentions facultatives de l'identité (décision P14.7) : la
     * liquidation à côté de la dénomination, puis une ligne pour la qualité
     * d'exploitant, pour l'objet de l'activité d'un EIRL et pour
     * l'appartenance à un assujetti unique, sous l'identité et avant le
     * siège ; elles se figent à l'émission, et un devis les fige à son
     * envoi : la société qui les retire ou les change n'y change rien.
     */
    public function test_les_mentions_facultatives_s_impriment_dans_le_pied_et_se_figent_a_l_emission(): void
    {
        $magasin = $this->magasinDuPort(['en_liquidation' => true, 'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant]);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client);
        $ticket = $this->emettre(Client::factory()->create());
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
        $this->reglerLaSociete($magasin, ['en_liquidation' => false, 'exploitation_en_gerance' => ExploitationEnGerance::GerantMandataire]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $avant = 'Martin et Fils SARL société en liquidation, au capital de 10 000,00 € · Locataire-gérant · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120';
        $apres = 'Martin et Fils SARL au capital de 10 000,00 € · Gérant-mandataire · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120';

        $this->assertSame($avant, $this->societe(app(FacturePdfGenerator::class)->html($facture->fresh())));
        $this->assertSame($avant, $this->societe(app(FacturePdfGenerator::class)->html($ticket->fresh())));
        $this->assertSame($avant, $this->societe(app(DevisPdfGenerator::class)->html($devis->fresh())));
        $this->assertSame($apres, $this->societe(app(FacturePdfGenerator::class)->html($brouillon->fresh())));
        $this->assertSame(
            ['en_liquidation' => true, 'exploitation_en_gerance' => 'locataire_gerant', 'eirl' => false, 'objet_de_l_eirl' => null, 'membre_assujetti_unique' => false],
            $facture->fresh()->emetteur()->enTableau()['mentions_facultatives'],
        );
        $this->assertSame(['en_liquidation' => true, 'exploitation_en_gerance' => 'locataire_gerant'], array_intersect_key($devis->fresh()->emetteur_fige['mentions_facultatives'], array_flip(['en_liquidation', 'exploitation_en_gerance'])));
    }

    /**
     * Un EIRL imprime « EIRL » et l'objet de son activité (art. R123-237,
     * 8°), sur la ligne de son identité et sous elle, avant l'adresse de
     * son entreprise ; son ticket les garde quand l'entrepreneur renonce à
     * la case.
     */
    public function test_un_eirl_imprime_l_objet_de_son_activite_et_le_garde_apres_son_ticket(): void
    {
        $magasin = $this->magasinDuPort([
            'raison_sociale' => 'Jean Martin',
            'forme_juridique' => FormeJuridique::EntrepreneurIndividuel,
            'capital_social' => null,
            'immatriculation' => 'RM 13',
            'eirl' => true,
            'eirl_objet' => 'Vente de plantes et de fleurs',
        ]);
        $ticket = $this->emettre(Client::factory()->create());
        $this->reglerLaSociete($magasin, ['eirl' => false]);

        $html = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $this->assertSame('Jean Martin EIRL · Objet de l\'activité à laquelle le patrimoine est affecté : Vente de plantes et de fleurs · Adresse de l\'entreprise : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RM 13 · TVA intracommunautaire : FR56121212120', $this->societe($html));
        $this->assertNull($magasin->societe->fresh()->eirl_objet);
    }

    /**
     * L'appartenance à un assujetti unique (groupe TVA) s'imprime sous
     * l'identité d'un brouillon : la société ne peut pas encore émettre (voir
     * Magasin::REFUS_ASSUJETTI_UNIQUE), mais son pied dit déjà ce que la loi
     * veut (art. 242 nonies A, I-5° bis de l'annexe II au CGI), et son
     * émetteur le signerait.
     */
    public function test_l_appartenance_a_un_assujetti_unique_s_imprime_et_se_signe(): void
    {
        $magasin = $this->magasinDuPort(['membre_assujetti_unique' => true]);
        $emetteur = Emetteur::duMagasin($magasin);

        $this->assertSame('Martin et Fils SARL au capital de 10 000,00 € · Membre d\'un assujetti unique · Siège social : 1 rue de la République, 13001 Marseille · SIREN : 121212120 · RCS Marseille · TVA intracommunautaire : FR56121212120', implode(' · ', $emetteur->lignesDeLaSociete()));
        $this->assertTrue($emetteur->mentionsFacultatives->membreAssujettiUnique);
        $this->assertEquals($emetteur, Emetteur::duContenuSigne($emetteur->enTableau()));
    }

    /**
     * Le bloc « mentions_facultatives » du contenu signé n'existe que pour
     * une société qui en déclare au moins une : l'émetteur de toute autre
     * garde la forme qu'il avait, et se relit sans le bloc. Celui qui
     * l'écrit se relit à l'identique.
     */
    public function test_le_contenu_signe_ne_porte_le_bloc_des_mentions_facultatives_que_si_la_societe_en_declare(): void
    {
        $sans = Emetteur::duMagasin($this->magasinDuPort());
        $avec = Emetteur::duMagasin($this->magasinDuPort(['code' => 'PORT2', 'siren' => '100000009', 'siret' => '10000000900017', 'en_liquidation' => true]));

        $this->assertArrayNotHasKey('mentions_facultatives', $sans->enTableau());
        $this->assertTrue($sans->mentionsFacultatives->estVide());
        $this->assertEquals($sans, Emetteur::duContenuSigne($sans->enTableau()));
        $this->assertFalse($avec->mentionsFacultatives->estVide());
        $this->assertSame(['en_liquidation' => true, 'exploitation_en_gerance' => null, 'eirl' => false, 'objet_de_l_eirl' => null, 'membre_assujetti_unique' => false], $avec->enTableau()['mentions_facultatives']);
        $this->assertEquals($avec, Emetteur::duContenuSigne($avec->enTableau()));
        $this->assertEquals(MentionsFacultativesDeLIdentite::aucune(), Emetteur::duContenuSigne($sans->enTableau())->mentionsFacultatives);
    }

    /**
     * Un ticket numéroté directement en base, hors de son émission, n'a
     * aucun enregistrement signé : son PDF n'est pas reconstitué depuis le
     * magasin d'aujourd'hui.
     */
    public function test_un_document_emis_sans_enregistrement_signe_refuse_de_produire_son_pdf(): void
    {
        $magasin = $this->magasinDuPort();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-PORT-2026-000009', 'statut' => 'emise', 'date_emission' => Instant::enBase(self::enUtc('2026-10-09 08:00:00'))]);

        $this->assertThrows(
            fn () => app(FacturePdfGenerator::class)->generer($brouillon->fresh()),
            DocumentSansEnregistrementSigneException::class,
            'Le ticket TK-PORT-2026-000009 n\'a aucun enregistrement signé au registre fiscal : son PDF n\'est pas produit.',
        );
    }

    /**
     * L'émetteur d'un magasin ne porte jamais le marqueur
     * « demonstration » ni de filigrane : il n'est écrit, à vrai, que dans
     * le contenu signé d'un document du site de démonstration. Un contenu
     * signé qui le porte se relit avec lui, son filigrane et son bandeau,
     * quel que soit le moment de l'impression.
     */
    public function test_l_emetteur_d_un_magasin_n_a_aucun_marqueur_et_un_contenu_signe_marque_garde_son_filigrane(): void
    {
        $emetteur = Emetteur::duMagasin($this->magasinDuPort());
        $signe = $emetteur->enTableau();

        $this->assertArrayNotHasKey('demonstration', $signe);
        $this->assertSame([false, null, null], [$emetteur->estDemonstration, $emetteur->filigrane(), $emetteur->bandeau(true)]);
        $this->assertEquals($emetteur, Emetteur::duContenuSigne($signe));

        $marque = Emetteur::duContenuSigne([...$signe, 'demonstration' => true]);

        $this->assertSame(
            [true, 'DÉMONSTRATION — SANS VALEUR', 'TICKET DE DÉMONSTRATION — NE PAS REMETTRE À UN CLIENT', 'DÉMONSTRATION — SANS VALEUR'],
            [$marque->estDemonstration, $marque->filigrane(), $marque->bandeau(true), $marque->bandeau(false)],
        );
        $this->assertTrue($marque->enTableau()['demonstration']);
    }

    /**
     * Le magasin d'une société à l'identité légale complète, assurance
     * professionnelle comprise, qui a opté pour le paiement de la TVA
     * d'après les débits ; son siège n'est pas à l'adresse du magasin. Ces attributs remplacent ceux de la société
     * ou du magasin (CHAMPS_DE_LA_SOCIETE).
     *
     * @param  array<string, mixed>  $attributs
     */
    private function magasinDuPort(array $attributs = []): Magasin
    {
        $magasin = Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'capital_social' => '10000.00',
            'siren' => '121212120',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République',
            'siege_code_postal' => '13001',
            'siege_ville' => 'Marseille',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
            'option_debits_tva' => true,
            'assurance_assureur' => 'Mutuelle du Littoral',
            'assurance_adresse' => '4 rue Paradis, 13001 Marseille',
            'assurance_contrat' => 'RC-2026-118',
            'assurance_couverture' => 'France métropolitaine',
            ...array_intersect_key($attributs, array_flip(self::CHAMPS_DE_LA_SOCIETE)),
        ])->create([
            'nom' => 'Boutique du Port',
            'code' => 'PORT',
            'siret' => '12121212000012',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => null,
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'telephone' => '04 91 00 00 00',
            'email' => 'contact@boutique-du-port.fr',
            ...array_diff_key($attributs, array_flip(self::CHAMPS_DE_LA_SOCIETE)),
        ]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Change l'identité de la société (CHAMPS_DE_LA_SOCIETE) et celle du
     * magasin, chacune où elle se règle.
     *
     * @param  array<string, mixed>  $identite
     */
    private function changerLIdentiteDuMagasin(Magasin $magasin, array $identite): void
    {
        $this->reglerLaSociete($magasin, array_intersect_key($identite, array_flip(self::CHAMPS_DE_LA_SOCIETE)));
        $magasin->update(array_diff_key($identite, array_flip(self::CHAMPS_DE_LA_SOCIETE)));
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function emettre(Client $client): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
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
        return Str::betweenFirst(preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html), '<table class="en-tete"><tr><td class="magasin">', '</td>');
    }

    /**
     * L'identité de la société, en tête du pied de page du document.
     */
    private function societe(string $html): string
    {
        preg_match('/<div class="identite">(.*?)<\/div>/s', $html, $identite);

        return html_entity_decode(trim($identite[1]), ENT_QUOTES);
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
