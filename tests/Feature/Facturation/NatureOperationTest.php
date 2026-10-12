<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Exceptions\NatureOperationRefuseeException;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les ventes hors de France (I1, I2) : chaque nature d'opération ne sort
 * de la TVA française que si toutes ses conditions sont réunies le jour
 * de l'émission, et le client paie alors le prix sans la TVA française
 * (K3) -- un produit au prix TTC de son catalogue ÷ (1 + son taux), un
 * service à son prix saisi sans TVA. Le catalogue des tests vend à 20 %.
 */
class NatureOperationTest extends TestCase
{
    use RefreshDatabase;

    private const LIVRAISON_NEW_YORK = ['livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US'];

    private const LIVRAISON_FORT_DE_FRANCE = ['livraison_adresse' => '1 rue Victor Hugo', 'livraison_code_postal' => '97200', 'livraison_ville' => 'Fort-de-France', 'livraison_pays' => 'FR'];

    private const LIVRAISON_BERLIN = ['livraison_adresse' => 'Musterstraße 1', 'livraison_code_postal' => '10115', 'livraison_ville' => 'Berlin', 'livraison_pays' => 'DE', 'livraison_date' => '2026-10-12', 'transport_par' => 'magasin'];

    /**
     * Le magasin LYON1, magasin actif : le n° de TVA et le régime se
     * règlent sur sa société, le reste sur le magasin.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function magasin(array $attributs = []): Magasin
    {
        $this->travelTo('2026-10-09 10:15:00');
        $deLaSociete = ['numero_tva', 'regime_tva'];
        $magasin = Magasin::factory()
            ->avecLaSociete(array_intersect_key($attributs, array_flip($deLaSociete)))
            ->create(['code' => 'LYON1', 'code_postal' => '69001', ...array_diff_key($attributs, array_flip($deLaSociete))]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function produit(string $prixTtc = '1.00'): Produit
    {
        return Produit::factory()->create(['nom' => 'Clé USB', 'prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function clientAmericain(?string $identifiant = 'EIN 12-3456789'): Client
    {
        return Client::factory()->create([
            'nom' => 'Acme Inc.',
            'type_client' => TypeClient::Professionnel,
            'pays' => 'US',
            'etabli_en_france' => false,
            'identifiant_etranger' => $identifiant,
        ]);
    }

    private function clientAllemand(string $numeroTva = 'DE123456789', array $attributs = []): Client
    {
        return Client::factory()->etranger('DE', $numeroTva)->create(['nom' => 'Muster GmbH', 'code_postal' => '10115', ...$attributs]);
    }

    /**
     * Une vérification VIES de ce client, écrite comme l'écrit
     * VerifierNumeroTvaAction, à cet instant de Paris.
     */
    private function consultation(Client $client, string $instantAParis = '2026-10-09 09:00', bool $valide = true): ConsultationVies
    {
        return ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => $client->numero_tva,
            'valide' => $valide,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => CarbonImmutable::parse($instantAParis, 'Europe/Paris'),
            'reponse' => '{"valid":'.($valide ? 'true' : 'false').'}',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $nature
     */
    private function brouillon(Client $client, array $lignes, NatureOperation $natureOperation, array $nature = []): Facture
    {
        return app(CreerFactureAction::class)->handle(self::auteur(), $client, $lignes, ['nature_operation' => $natureOperation, ...$nature]);
    }

    private function assertEmissionRefusee(Facture $brouillon, string $refus): void
    {
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon), NatureOperationRefuseeException::class, $refus);

        $brouillon->refresh();
        $this->assertSame([StatutFacture::Brouillon, null, null], [$brouillon->statut, $brouillon->numero, $brouillon->consultation_vies_id]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('document_type', $brouillon->getMorphClass())->count());
    }

    private function pdf(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture);
    }

    public function test_un_export_a_un_professionnel_se_paie_au_prix_hors_taxe_sans_tva_avec_sa_mention(): void
    {
        $this->magasin();

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($this->clientAmericain(), [['produit_id' => $this->produit()->id, 'quantite' => 1000]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $ligne = $facture->lignes->sole();
        $this->assertSame([StatutFacture::Emise, 'FAC-LYON1-2026-000001'], [$facture->statut, $facture->numero]);
        $this->assertSame(['833.33', '0.00', '833.33'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame(['0.00', '20.00', '1.00', '0.83'], [$ligne->taux_tva, $ligne->taux_tva_du_prix, $ligne->prix_unitaire_ttc, $ligne->prix_unitaire_ht]);
        $this->assertSame(TransportPar::Magasin, $facture->transport_par);
        $this->assertStringContainsString('Exonération de TVA, article 262 I du CGI', $this->pdf($facture));
        $this->assertStringContainsString('New York', $this->pdf($facture));
        $this->assertStringNotContainsString('PU TTC', $this->pdf($facture));
    }

    /**
     * Le PDF d'un export à un professionnel imprime, à côté du taux de 0 %,
     * le prix TTC du catalogue et la TVA qui en est retirée, que cite sa
     * note de calcul : 3 x 7,00 € ÷ 1,20 = 17,50 € HT, quand 3 x 5,83 €
     * font 17,49 €. Les frais de port, saisis sans TVA, n'ont ni l'un ni
     * l'autre.
     */
    public function test_le_pdf_d_un_export_a_un_professionnel_imprime_le_prix_catalogue_et_la_tva_retiree(): void
    {
        $this->magasin();
        $port = Service::factory()->fraisAccessoires()->create(['nom' => 'Frais de port']);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($this->clientAmericain(), [
            ['produit_id' => $this->produit('7.00')->id, 'quantite' => 3],
            ['service_id' => $port->id, 'prix_unitaire_ttc' => '15.00', 'quantite' => 1],
        ], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $pdf = $this->pdf($facture);
        $this->assertSame(['32.50', '0.00', '32.50'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertMatchesRegularExpression('#<th class="montant">PU HT</th>\s*<th class="montant">Prix catalogue TTC</th>\s*<th class="montant">TVA retirée</th>\s*<th class="montant">Taux TVA</th>#', $pdf);
        $this->assertMatchesRegularExpression('#<td class="montant">3</td>\s*<td class="montant">5,83 €</td>\s*<td class="montant">7,00 €</td>\s*<td class="montant">20 %</td>\s*<td class="montant">0 %</td>\s*<td class="montant">17,50 €</td>#', $pdf);
        $this->assertMatchesRegularExpression('#<td class="montant">1</td>\s*<td class="montant">15,00 €</td>\s*<td class="montant">—</td>\s*<td class="montant">—</td>\s*<td class="montant">0 %</td>\s*<td class="montant">15,00 €</td>#', $pdf);
        $this->assertStringContainsString('HT de chaque ligne de produit = quantité × prix catalogue TTC ÷ (1 + TVA retirée), arrondi une fois', $pdf);
        $this->assertStringNotContainsString('PU TTC', $pdf);
    }

    public function test_un_export_a_un_particulier_se_paie_0_83_euro_l_article(): void
    {
        $this->magasin();

        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1000]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $this->assertSame('TK-LYON1-2026-000001', $ticket->numero);
        $this->assertSame('0.83', $ticket->lignes->sole()->prix_unitaire_ht);
        $this->assertSame(['830.00', '0.00', '830.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function destinationsHorsUe(): array
    {
        return [
            'îles Canaries' => ['ES', '35001'],
            'Londres' => ['GB', 'SW1A 1AA'],
            'Polynésie française' => ['FR', '98714'],
        ];
    }

    #[DataProvider('destinationsHorsUe')]
    public function test_un_export_part_vers_une_destination_hors_du_territoire_de_tva_de_l_union(string $pays, string $codePostal): void
    {
        $this->magasin();

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($this->clientAmericain(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::ExportHorsUe, [...self::LIVRAISON_NEW_YORK, 'livraison_pays' => $pays, 'livraison_code_postal' => $codePostal]));

        $this->assertSame(StatutFacture::Emise, $facture->statut);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function destinationsRefuseesALExport(): array
    {
        return [
            'Allemagne' => ['DE', '10115', 'la livraison reste dans le territoire de TVA de l\'Union européenne'],
            'Monaco' => ['MC', '98000', 'Monaco suit la TVA française'],
            'Martinique' => ['FR', '97200', 'la livraison va dans un département d\'outre-mer : utilisez « Livraison vers un DOM »'],
            'Irlande du Nord, dans l\'Union pour des biens' => ['GB', 'BT1 1AA', 'la livraison reste dans le territoire de TVA de l\'Union européenne'],
        ];
    }

    #[DataProvider('destinationsRefuseesALExport')]
    public function test_un_export_vers_une_destination_de_l_union_ou_francaise_est_refuse(string $pays, string $codePostal, string $manquement): void
    {
        $this->magasin();
        $brouillon = $this->brouillon($this->clientAmericain(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::ExportHorsUe, [...self::LIVRAISON_NEW_YORK, 'livraison_pays' => $pays, 'livraison_code_postal' => $codePostal]);

        $this->assertEmissionRefusee($brouillon, "Impossible d'émettre en « Export hors UE expédié par le magasin » : {$manquement}. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.");
    }

    /**
     * Les frais de port suivent les biens qu'ils accompagnent : saisis
     * sans TVA, ils se facturent 15,00 €, sans TVA.
     */
    public function test_les_frais_de_port_d_un_export_se_facturent_sans_tva(): void
    {
        $this->magasin();
        $port = Service::factory()->fraisAccessoires()->create(['nom' => 'Frais de port']);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($this->clientAmericain(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1000],
            ['service_id' => $port->id, 'prix_unitaire_ttc' => '15.00', 'quantite' => 1],
        ], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $fraisDePort = $facture->lignes->firstWhere('service_id', $port->id);
        $this->assertSame(['15.00', '0.00', '15.00', '0.00'], [$fraisDePort->montant_ht, $fraisDePort->montant_tva, $fraisDePort->montant_ttc, $fraisDePort->taux_tva]);
        $this->assertSame(['848.33', '0.00', '848.33'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Tous les manquements d'un coup : un service qui n'accompagne pas la
     * livraison, une adresse incomplète et un magasin sans n° de TVA.
     */
    public function test_un_export_refuse_dit_tout_ce_qui_manque(): void
    {
        $this->magasin(['numero_tva' => null]);
        $reparation = Service::factory()->create(['nom' => 'Réparation']);
        $brouillon = $this->brouillon(Client::factory()->create(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
            ['service_id' => $reparation->id, 'prix_unitaire_ttc' => '30.00', 'quantite' => 1],
        ], NatureOperation::ExportHorsUe, ['livraison_pays' => 'US']);

        $this->assertEmissionRefusee($brouillon, 'Impossible d\'émettre en « Export hors UE expédié par le magasin » : indiquez l\'adresse de livraison complète (adresse, code postal, ville et pays) ; « Réparation » : seuls des biens et leurs frais de livraison ou accessoires (port, emballage, assurance) se vendent ainsi ; renseignez le n° de TVA intracommunautaire de la société (Administration de la société > Identité). Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');
    }

    public function test_une_livraison_vers_un_dom_se_paie_au_prix_hors_taxe_avec_sa_mention(): void
    {
        $this->magasin();

        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon(Client::factory()->create(), [['produit_id' => $this->produit('49.90')->id, 'quantite' => 3]], NatureOperation::LivraisonDom, self::LIVRAISON_FORT_DE_FRANCE));

        $this->assertSame('41.58', $ticket->lignes->sole()->prix_unitaire_ht);
        $this->assertSame(['124.74', '0.00', '124.74'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertStringContainsString('Exonération de TVA en application de l&#039;article 294 du Code général des impôts', $this->pdf($ticket));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function livraisonsRefuseesVersUnDom(): array
    {
        return [
            'en métropole' => ['75001', 'la livraison doit aller dans un département d\'outre-mer (Guadeloupe, Martinique, Guyane, La Réunion ou Mayotte)'],
            'à Saint-Barthélemy, collectivité d\'outre-mer' => ['97133', 'la livraison doit aller dans un département d\'outre-mer (Guadeloupe, Martinique, Guyane, La Réunion ou Mayotte)'],
        ];
    }

    #[DataProvider('livraisonsRefuseesVersUnDom')]
    public function test_une_livraison_vers_un_dom_hors_d_un_departement_d_outre_mer_est_refusee(string $codePostal, string $manquement): void
    {
        $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::LivraisonDom, [...self::LIVRAISON_FORT_DE_FRANCE, 'livraison_code_postal' => $codePostal]);

        $this->assertEmissionRefusee($brouillon, "Impossible d'émettre en « Livraison vers un DOM » : {$manquement}. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.");
    }

    public function test_un_magasin_de_martinique_ne_livre_pas_en_exoneration_dans_son_propre_departement(): void
    {
        $this->magasin(['code_postal' => '97200', 'ville' => 'Fort-de-France']);
        $brouillon = $this->brouillon(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::LivraisonDom, self::LIVRAISON_FORT_DE_FRANCE);

        $this->assertEmissionRefusee($brouillon, 'Impossible d\'émettre en « Livraison vers un DOM » : la livraison reste dans le département d\'outre-mer du magasin. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');
    }

    public function test_une_livraison_intracommunautaire_retient_la_consultation_vies_du_jour(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $consultation = $this->consultation($client);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $this->produit('25.00')->id, 'quantite' => 12]], NatureOperation::LivraisonIntracommunautaire, self::LIVRAISON_BERLIN));

        $this->assertSame(['250.00', '0.00', '250.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame($consultation->id, $facture->consultation_vies_id);
        $this->assertSame(['2026-10-12', TransportPar::Magasin], [$facture->livraison_date->toDateString(), $facture->transport_par]);
        $this->assertStringContainsString('Exonération de TVA, article 262 ter I du CGI', $this->pdf($facture));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string|null, bool, string}>
     */
    public static function livraisonsIntracommunautairesRefusees(): array
    {
        return [
            'n° vérifié la veille' => [[], 'DE123456789', '2026-10-08 18:00', true, 'le n° de TVA DE123456789 n\'a pas été trouvé valide sur VIES le 09/10/2026'],
            'n° inconnu de VIES aujourd\'hui' => [[], 'DE123456789', '2026-10-09 09:00', false, 'le n° de TVA DE123456789 n\'a pas été trouvé valide sur VIES le 09/10/2026'],
            'n° de TVA français' => [[], 'FR56121212120', '2026-10-09 09:00', true, 'le client doit avoir un n° de TVA d\'un autre État membre de l\'Union (ou XI pour l\'Irlande du Nord)'],
            'livraison en France' => [['livraison_code_postal' => '69001', 'livraison_ville' => 'Lyon', 'livraison_pays' => 'FR'], 'DE123456789', '2026-10-09 09:00', true, 'la livraison doit arriver dans un autre État membre de l\'Union européenne'],
            'sans date de livraison' => [['livraison_date' => null], 'DE123456789', '2026-10-09 09:00', true, 'indiquez la date de livraison'],
            'sans transport' => [['transport_par' => null], 'DE123456789', '2026-10-09 09:00', true, 'indiquez qui transporte les biens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $livraison
     */
    #[DataProvider('livraisonsIntracommunautairesRefusees')]
    public function test_une_livraison_intracommunautaire_sans_toutes_ses_conditions_est_refusee(array $livraison, string $numeroTva, string $verifieLe, bool $valide, string $manquement): void
    {
        $this->magasin();
        $client = $this->clientAllemand($numeroTva);
        $this->consultation($client, $verifieLe, $valide);
        $brouillon = $this->brouillon($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::LivraisonIntracommunautaire, [...self::LIVRAISON_BERLIN, ...$livraison]);

        $this->assertEmissionRefusee($brouillon, "Impossible d'émettre en « Livraison intracommunautaire à un professionnel » : {$manquement}. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.");
    }

    public function test_une_livraison_intracommunautaire_admet_un_client_d_irlande_du_nord_identifie_en_xi(): void
    {
        $this->magasin();
        $client = Client::factory()->etranger('GB', 'XI123456789')->create(['code_postal' => 'BT1 1AA']);
        $this->consultation($client);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]], NatureOperation::LivraisonIntracommunautaire, [...self::LIVRAISON_BERLIN, 'livraison_code_postal' => 'BT1 1AA', 'livraison_ville' => 'Belfast', 'livraison_pays' => 'GB']));

        $this->assertSame(StatutFacture::Emise, $facture->statut);
    }

    /**
     * La dernière vérification du jour décide : trouvé valide à 8 h puis
     * inconnu de VIES à 9 h 30, le n° ne vaut plus, et la vente reste à la
     * TVA française.
     */
    public function test_une_verification_vies_invalide_plus_recente_du_meme_jour_refuse_l_exoneration(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $this->consultation($client, '2026-10-09 08:00');
        $this->consultation($client, '2026-10-09 09:30', valide: false);
        $brouillon = $this->brouillon($client, [['produit_id' => $this->produit('25.00')->id, 'quantite' => 12]], NatureOperation::LivraisonIntracommunautaire, self::LIVRAISON_BERLIN);

        $this->assertEmissionRefusee($brouillon, 'Impossible d\'émettre en « Livraison intracommunautaire à un professionnel » : le n° de TVA DE123456789 n\'a pas été trouvé valide sur VIES le 09/10/2026. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');
    }

    /**
     * Inconnu de VIES à 8 h puis trouvé valide à 9 h 30 : la vérification
     * de 9 h 30, la dernière, est retenue.
     */
    public function test_une_verification_vies_valide_plus_recente_du_meme_jour_est_retenue(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $this->consultation($client, '2026-10-09 08:00', valide: false);
        $derniere = $this->consultation($client, '2026-10-09 09:30');

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $this->produit('25.00')->id, 'quantite' => 12]], NatureOperation::LivraisonIntracommunautaire, self::LIVRAISON_BERLIN));

        $this->assertSame([StatutFacture::Emise, $derniere->id], [$facture->statut, $facture->consultation_vies_id]);
    }

    public function test_une_prestation_autoliquidee_se_facture_sans_tva_avec_la_mention_autoliquidation(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $consultation = $this->consultation($client);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['service_id' => Service::factory()->create(['nom' => 'Conseil'])->id, 'prix_unitaire_ttc' => '500.00', 'quantite' => 1]], NatureOperation::PrestationUeAutoliquidation));

        $this->assertSame(['500.00', '0.00', '500.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame($consultation->id, $facture->consultation_vies_id);
        $this->assertStringContainsString('Autoliquidation', $this->pdf($facture));
    }

    public function test_une_prestation_autoliquidee_ne_vend_ni_bien_ni_service_au_lieu_fixe_par_la_loi(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $this->consultation($client);
        $brouillon = $this->brouillon($client, [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
            ['service_id' => Service::factory()->lieuSpecifique()->create(['nom' => 'Location de salle'])->id, 'prix_unitaire_ttc' => '200.00', 'quantite' => 1],
        ], NatureOperation::PrestationUeAutoliquidation);

        $this->assertEmissionRefusee($brouillon, 'Impossible d\'émettre en « Prestation à un professionnel de l\'UE (autoliquidation) » : « Clé USB » : seuls des services se facturent ainsi ; le lieu de « Location de salle » est fixé par la loi (art. 259 A du CGI) : la TVA française s\'applique. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');
    }

    /**
     * @return array<string, array{\Closure(): Client, string}>
     */
    public static function clientsRefusesALAutoliquidation(): array
    {
        return [
            'établi en France' => [fn (): Client => Client::factory()->etranger('DE', 'DE123456789')->create(['etabli_en_france' => true, 'siren' => '123456789']), 'le client doit être établi hors de France'],
            'd\'Irlande du Nord, identifié en XI' => [fn (): Client => Client::factory()->etranger('GB', 'XI123456789')->create(['code_postal' => 'BT1 1AA']), 'le client doit être établi dans un autre État membre de l\'Union européenne ; le client doit avoir un n° de TVA d\'un autre État membre de l\'Union (XI ne vaut que pour des biens)'],
        ];
    }

    /**
     * @param  \Closure(): Client  $client
     */
    #[DataProvider('clientsRefusesALAutoliquidation')]
    public function test_une_prestation_autoliquidee_exige_un_client_etabli_dans_un_autre_etat_membre(\Closure $client, string $manquements): void
    {
        $this->magasin();
        $client = $client();
        $this->consultation($client);
        $brouillon = $this->brouillon($client, [['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => '500.00', 'quantite' => 1]], NatureOperation::PrestationUeAutoliquidation);

        $this->assertEmissionRefusee($brouillon, "Impossible d'émettre en « Prestation à un professionnel de l'UE (autoliquidation) » : {$manquements}. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.");
    }

    public function test_une_prestation_hors_ue_se_facture_sans_tva_avec_sa_mention(): void
    {
        $this->magasin();

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($this->clientAmericain(), [['service_id' => Service::factory()->create(['nom' => 'Conseil'])->id, 'prix_unitaire_ttc' => '1200.00', 'quantite' => 1]], NatureOperation::PrestationHorsUe));

        $this->assertSame(['1200.00', '0.00', '1200.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertNull($facture->consultation_vies_id);
        $this->assertStringContainsString('TVA non applicable – article 259-1° du CGI', $this->pdf($facture));
    }

    public function test_une_prestation_hors_ue_exige_l_identifiant_du_client(): void
    {
        $this->magasin();
        $brouillon = $this->brouillon($this->clientAmericain(identifiant: null), [['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => '1200.00', 'quantite' => 1]], NatureOperation::PrestationHorsUe);

        $this->assertEmissionRefusee($brouillon, 'Impossible d\'émettre en « Prestation à un professionnel hors UE » : indiquez le n° de TVA ou un autre identifiant du client sur sa fiche. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');
    }

    /**
     * Une nature réservée aux professionnels ne s'enregistre même pas pour
     * un particulier : aucun brouillon ne la garde.
     */
    public function test_une_nature_reservee_aux_professionnels_est_refusee_a_un_particulier(): void
    {
        $this->magasin();
        $service = Service::factory()->create();

        $this->assertThrows(
            fn () => $this->brouillon(Client::factory()->create(), [['service_id' => $service->id, 'prix_unitaire_ttc' => '100.00', 'quantite' => 1]], NatureOperation::PrestationHorsUe),
            LogicException::class,
            'Impossible d\'enregistrer en « Prestation à un professionnel hors UE » : cette nature est réservée aux clients professionnels. Choisissez une autre nature.',
        );

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un devis réenregistré après que son client est devenu particulier ne
     * garde pas une nature réservée aux professionnels : rien ne change,
     * et son envoi reste refusé tant qu'il n'est pas réenregistré dans une
     * nature permise.
     */
    public function test_un_devis_ne_se_reenregistre_pas_dans_une_nature_que_son_client_ne_peut_plus_choisir(): void
    {
        $this->magasin();
        $client = $this->clientAllemand();
        $service = Service::factory()->create(['nom' => 'Conseil']);
        $lignes = [['service_id' => $service->id, 'prix_unitaire_ttc' => '500.00', 'quantite' => 1]];
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, $lignes, ['nature_operation' => NatureOperation::PrestationUeAutoliquidation]);
        $client->update(['type_client' => TypeClient::Particulier, 'numero_tva' => null]);

        $this->assertThrows(
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client->fresh(), $lignes, ['nature_operation' => NatureOperation::PrestationUeAutoliquidation]),
            LogicException::class,
            'Impossible d\'enregistrer en « Prestation à un professionnel de l\'UE (autoliquidation) » : cette nature est réservée aux clients professionnels. Choisissez une autre nature.',
        );

        $devis->refresh();
        $this->assertSame([NatureOperation::PrestationUeAutoliquidation, TypeClient::Professionnel], [$devis->nature_operation, $devis->type_client]);
        $this->assertSame(['500.00', '0.00', '500.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle(self::auteur(), $devis), LogicException::class, 'Ce client est devenu particulier depuis l\'enregistrement du brouillon : ouvrez-le et enregistrez-le de nouveau.');
    }

    /**
     * @return array<string, array{NatureOperation}>
     */
    public static function naturesExonerees(): array
    {
        return collect(NatureOperation::cases())
            ->filter(fn (NatureOperation $nature): bool => $nature->exonere())
            ->mapWithKeys(fn (NatureOperation $nature): array => [$nature->value => [$nature]])
            ->all();
    }

    /**
     * Un magasin en franchise en base ne facture jamais de TVA : sa
     * franchise couvre aussi ses ventes hors de France, qu'aucune autre
     * nature n'exonère ; aucun brouillon ne s'enregistre dans une autre
     * nature.
     */
    #[DataProvider('naturesExonerees')]
    public function test_un_magasin_en_franchise_ne_vend_qu_en_france(NatureOperation $nature): void
    {
        $this->magasin(['regime_tva' => RegimeTva::FranchiseEnBase]);
        $client = $this->clientAllemand();
        $produit = $this->produit();

        $this->assertThrows(
            fn () => $this->brouillon($client, [['produit_id' => $produit->id, 'quantite' => 1]], $nature, self::LIVRAISON_BERLIN),
            LogicException::class,
            "Impossible d'enregistrer en « {$nature->getLabel()} » : votre société est en franchise en base de TVA, qui couvre aussi ses ventes hors de France. Choisissez « Vente en France ».",
        );

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le brouillon d'un export revenu en vente en France se recalcule avec
     * la TVA française, sans livraison.
     */
    public function test_un_brouillon_revenu_en_vente_en_france_reprend_la_tva_et_oublie_sa_livraison(): void
    {
        $this->magasin();
        $client = $this->clientAmericain();
        $produit = $this->produit();
        $brouillon = $this->brouillon($client, [['produit_id' => $produit->id, 'quantite' => 1000]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK);

        $brouillon = app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $client, [['produit_id' => $produit->id, 'quantite' => 1000]], ['nature_operation' => NatureOperation::VenteEnFrance->value]);

        $this->assertSame(NatureOperation::VenteEnFrance, $brouillon->nature_operation);
        $this->assertSame([null, null, null], [$brouillon->livraison_pays, $brouillon->livraison_ville, $brouillon->transport_par]);
        $this->assertSame(['833.33', '166.67', '1000.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    public function test_un_avoir_ne_melange_pas_des_factures_de_natures_differentes(): void
    {
        $this->magasin();
        $client = $this->clientAmericain();
        $produit = $this->produit();
        $enFrance = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $produit->id, 'quantite' => 1]], NatureOperation::VenteEnFrance));
        $export = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $produit->id, 'quantite' => 1]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $this->assertThrows(fn () => app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $enFrance->lignes->sole()->id, 'quantite' => 1],
            ['ligne_facture_id' => $export->lignes->sole()->id, 'quantite' => 1],
        ]), InvalidArgumentException::class, 'Un avoir ne mélange pas des factures de natures d\'opération différentes : faites un avoir par nature.');

        $this->assertSame(0, Avoir::query()->count());
    }

    /**
     * L'avoir d'un export reprend sa nature et sa mention, et se calcule
     * comme lui : 500 des 1 000 articles rendent 416,67 € HT, sans TVA.
     */
    public function test_l_avoir_d_un_export_reprend_sa_nature_et_se_calcule_comme_lui(): void
    {
        $this->magasin();
        $client = $this->clientAmericain();
        $export = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $this->produit()->id, 'quantite' => 1000]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $export->lignes->sole()->id, 'quantite' => 500]], 'Retour partiel');

        $this->assertSame(NatureOperation::ExportHorsUe, $avoir->nature_operation);
        $this->assertSame(['416.67', '0.00', '416.67'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame(['0.00', '20.00'], [$avoir->lignes->sole()->taux_tva, $avoir->lignes->sole()->taux_tva_du_prix]);
        $this->assertStringContainsString('Exonération de TVA, article 262 I du CGI', app(AvoirPdfGenerator::class)->html($avoir));
    }

    /**
     * L'avoir d'un export à un particulier se calcule à son PU HT, le prix
     * qu'il a payé : 1 000 articles à 1,00 € TTC à 20 % vendus 830,00 €,
     * 500 rendus créditent 415,00 €, sans TVA, et les 500 derniers les
     * 415,00 € qui restent, la ligne soldée au centime.
     */
    public function test_l_avoir_d_un_export_a_un_particulier_se_calcule_a_son_pu_ht(): void
    {
        $this->magasin();
        $client = Client::factory()->create();
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillon($client, [['produit_id' => $this->produit()->id, 'quantite' => 1000]], NatureOperation::ExportHorsUe, self::LIVRAISON_NEW_YORK));
        $ligne = $ticket->lignes->sole();

        $premier = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 500]], 'Retour partiel');
        $dernier = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 500]], 'Retour du reste');

        $this->assertSame(['415.00', '0.00', '415.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['415.00', '0.00', '415.00'], [$dernier->total_ht, $dernier->total_tva, $dernier->total_ttc]);
        $this->assertSame(0, $ligne->fresh()->quantiteCreditableRestante());
    }

    public function test_un_devis_d_export_imprime_sa_mention_et_sa_livraison_sans_prix_ttc(): void
    {
        $this->magasin();

        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $this->clientAmericain(), [['produit_id' => $this->produit()->id, 'quantite' => 1000]], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::LIVRAISON_NEW_YORK]);

        $pdf = app(DevisPdfGenerator::class)->html($devis);
        $this->assertSame(['833.33', '0.00', '833.33'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertStringContainsString('Exonération de TVA, article 262 I du CGI', $pdf);
        $this->assertStringContainsString('1 Main Street', $pdf);
        $this->assertStringNotContainsString('PU TTC', $pdf);
    }

    public function test_la_facture_tiree_d_un_devis_d_export_garde_sa_nature_et_sa_livraison(): void
    {
        $this->magasin();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $this->clientAmericain(), [['produit_id' => $this->produit()->id, 'quantite' => 1000]], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::LIVRAISON_NEW_YORK, 'livraison_date' => '2026-10-20']);
        app(AccepterDevisAction::class)->handle(self::auteur(), app(EmettreDevisAction::class)->handle(self::auteur(), $devis));

        $facture = app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis->fresh());

        $this->assertSame(NatureOperation::ExportHorsUe, $facture->nature_operation);
        $this->assertSame(['1 Main Street', '10001', 'New York', 'US', '2026-10-20', TransportPar::Magasin], [$facture->livraison_adresse, $facture->livraison_code_postal, $facture->livraison_ville, $facture->livraison_pays, $facture->livraison_date->toDateString(), $facture->transport_par]);
        $this->assertSame(['833.33', '0.00', '833.33'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame('20.00', $facture->lignes->sole()->taux_tva_du_prix);
    }
}
