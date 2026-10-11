<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Art. 286 I-3° bis du CGI : un ticket, une facture ou un avoir est signé
 * dans la chaîne des ventes du registre fiscal à son émission, un paiement
 * dans la chaîne des encaissements à son enregistrement, dans la même
 * transaction, sous le verrou du registre pris avant tout autre. Les
 * tests signent avec la paire éphémère que Tests\TestCase épingle.
 */
class EmissionSigneeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les attributs que magasinActif() règle sur la société du magasin, et
     * non sur le magasin.
     */
    private const ATTRIBUTS_DE_LA_SOCIETE = ['raison_sociale', 'forme_juridique', 'capital_social', 'siren', 'immatriculation', 'numero_tva', 'siege_adresse', 'siege_code_postal', 'siege_ville', 'regime_tva', 'option_debits_tva'];

    /**
     * Le magasin LYON1 d'une nouvelle société validée, magasin actif : ces
     * attributs règlent la société (ATTRIBUTS_DE_LA_SOCIETE) ou le
     * magasin.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function magasinActif(array $attributs = []): Magasin
    {
        $magasin = Magasin::factory()
            ->avecLaSociete(Arr::only($attributs, self::ATTRIBUTS_DE_LA_SOCIETE))
            ->create(['code' => 'LYON1', ...Arr::except($attributs, self::ATTRIBUTS_DE_LA_SOCIETE)]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function produit(string $reference, string $nom, float $prixTtc, float $tauxTva = 20): Produit
    {
        return Produit::factory()->create(['reference' => $reference, 'nom' => $nom, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $tauxTva, 'suivi_stock' => false]);
    }

    /**
     * @param  list<array{0: Produit, 1: int}>  $articles
     */
    private function encaisser(Client $client, array $articles, ModePaiement $mode = ModePaiement::CarteBancaire, ?string $reference = null): Facture
    {
        return app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            array_map(static fn (array $article): array => ['produit_id' => $article[0]->id, 'quantite' => $article[1]], $articles),
            $mode,
            $reference,
        );
    }

    /**
     * @param  list<array{0: Produit, 1: int}>  $articles
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, array $articles, array $attributs = []): Facture
    {
        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(
            $client,
            array_map(static fn (array $article): array => ['produit_id' => $article[0]->id, 'quantite' => $article[1]], $articles),
            $attributs,
        ));
    }

    private function enregistrementDe(Model $document, ChaineFiscale $chaine): EnregistrementFiscal
    {
        return EnregistrementFiscal::query()
            ->where('chaine', $chaine->value)
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function contenu(EnregistrementFiscal $enregistrement): array
    {
        return json_decode($enregistrement->contenu, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Le registre du magasin actif, dans l'ordre d'écriture : chaîne,
     * séquence et type de chaque enregistrement.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function registre(): array
    {
        return EnregistrementFiscal::query()->orderBy('id')->get()
            ->map(fn (EnregistrementFiscal $enregistrement): array => [$enregistrement->chaine->value, $enregistrement->sequence, $enregistrement->type])
            ->all();
    }

    private function assertSignatureValide(EnregistrementFiscal $enregistrement): void
    {
        $clePublique = Arr::first(config('caisse_securisee.cles_publiques'));

        $this->assertSame(1, openssl_verify($enregistrement->contenu, base64_decode(strtr($enregistrement->signature, '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256));
    }

    /**
     * Rien dans le contenu signé ne se relit en nombre à virgule flottante.
     *
     * @param  array<array-key, mixed>  $valeurs
     */
    private function assertAucunFlottant(array $valeurs): void
    {
        array_walk_recursive($valeurs, function (mixed $valeur, int|string $cle): void {
            $this->assertIsNotFloat($valeur, "{$cle} est un nombre à virgule flottante.");
        });
    }

    /**
     * Les requêtes SQL que lance $action, dans l'ordre.
     *
     * @return list<string>
     */
    private function requetesDe(Closure $action): array
    {
        $requetes = [];
        DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $action();

        return $requetes;
    }

    /**
     * La position de la première requête qui commence par $debut.
     *
     * @param  list<string>  $requetes
     */
    private function position(array $requetes, string $debut): int
    {
        foreach ($requetes as $position => $requete) {
            if (str_starts_with($requete, $debut)) {
                return $position;
            }
        }

        $this->fail("Aucune requête ne commence par : {$debut}");
    }

    public function test_un_ticket_au_comptoir_signe_ses_lignes_sa_ventilation_son_emetteur_son_client_et_son_reglement(): void
    {
        $this->travelTo('2026-10-09 10:15:30');
        $this->magasinActif([
            'nom' => 'Boutique Lefèvre',
            'raison_sociale' => 'Lefèvre et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'capital_social' => null,
            'siren' => '100000009',
            'siret' => '10000000900017',
            'immatriculation' => 'RCS Paris',
            'numero_tva' => 'FR88100000009',
            'siege_adresse' => '4 avenue Foch',
            'siege_code_postal' => '75016',
            'siege_ville' => 'Paris',
            'adresse' => '12 rue des Lilas',
            'code_postal' => '75011',
            'ville' => 'Paris',
            'email' => 'contact@lefevre.test',
            'telephone' => '01 23 45 67 89',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
        ]);
        $vendeuse = User::factory()->create(['name' => 'Zoé Lefèvre']);
        $this->actingAs($vendeuse);
        $client = Client::factory()->create(['nom' => 'Alice Martin', 'adresse' => '3 place du Marché', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'alice@example.test', 'telephone' => '06 00 00 00 01']);
        $cafe = $this->produit('CAF-250', 'Café moulu 250 g', 12);
        $livre = $this->produit('LIV-001', 'Livre de recettes', 5.50, 5.5);

        $ticket = $this->encaisser($client, [[$cafe, 2], [$livre, 1]], ModePaiement::CarteBancaire, 'TRX-42');

        $vente = $this->enregistrementDe($ticket, ChaineFiscale::Vente);
        $paiement = Paiement::query()->sole();
        $encaissement = $this->enregistrementDe($paiement, ChaineFiscale::Encaissement);
        $ficheDuClient = [
            'id' => $client->id,
            'nom' => 'Alice Martin',
            'type_client' => 'particulier',
            'comptoir' => false,
            'siret' => null,
            'numero_tva' => null,
            'adresse' => '3 place du Marché',
            'complement_adresse' => null,
            'code_postal' => '75011',
            'ville' => 'Paris',
            'pays' => 'FR',
            'email' => 'alice@example.test',
            'telephone' => '06 00 00 00 01',
        ];
        $this->assertSame([['evenement', 1, '260'], ['encaissement', 1, 'paiement'], ['vente', 1, 'ticket']], $this->registre());
        $this->assertSame([
            'document' => [
                'numero' => 'TK-LYON1-2026-000001',
                'date_emission' => '2026-10-09T10:15:30+02:00',
                'client' => [
                    'id' => $client->id,
                    'comptoir' => false,
                    'type_client' => 'particulier',
                    'nom' => 'Alice Martin',
                    'adresse' => '3 place du Marché',
                    'complement_adresse' => null,
                    'code_postal' => '75011',
                    'ville' => 'Paris',
                    'pays' => 'FR',
                    'siren' => null,
                    'siret' => null,
                    'numero_tva' => null,
                    'identifiant_etranger' => null,
                    'etabli_en_france' => true,
                ],
                'franchise_en_base' => false,
                'nature_operation' => 'vente_france',
                'livraison' => ['adresse' => null, 'complement' => null, 'code_postal' => null, 'ville' => null, 'pays' => null, 'date' => null, 'transport_par' => null],
                'consultation_vies_id' => null,
                'cree_par' => $vendeuse->id,
                'devis_id' => null,
                'devise' => 'EUR',
                'date_echeance' => '2026-11-08',
                'conditions_paiement' => null,
                'notes' => null,
                'lignes' => [
                    ['ordre' => 0, 'nature' => 'bien', 'produit_id' => $cafe->id, 'service_id' => null, 'reference' => 'CAF-250', 'description' => 'Café moulu 250 g', 'quantite' => 2, 'prix_unitaire_ht' => 1000, 'prix_unitaire_ttc' => 1200, 'taux_tva' => 2000, 'taux_tva_du_prix' => 2000, 'remise' => 0, 'ht' => 2000, 'tva' => 400, 'ttc' => 2400],
                    ['ordre' => 1, 'nature' => 'bien', 'produit_id' => $livre->id, 'service_id' => null, 'reference' => 'LIV-001', 'description' => 'Livre de recettes', 'quantite' => 1, 'prix_unitaire_ht' => 521, 'prix_unitaire_ttc' => 550, 'taux_tva' => 550, 'taux_tva_du_prix' => 550, 'remise' => 0, 'ht' => 521, 'tva' => 29, 'ttc' => 550],
                ],
                'ventilation' => [
                    ['taux' => 2000, 'ht' => 2000, 'tva' => 400, 'ttc' => 2400],
                    ['taux' => 550, 'ht' => 521, 'tva' => 29, 'ttc' => 550],
                ],
                'totaux' => ['ht' => 2521, 'tva' => 429, 'ttc' => 2950],
            ],
            'contexte' => [
                'emetteur' => [
                    'nom' => 'Boutique Lefèvre',
                    'raison_sociale' => 'Lefèvre et Fils',
                    'forme_juridique' => 'SARL',
                    'entrepreneur_individuel' => false,
                    'capital_social' => null,
                    'capital_variable' => false,
                    'siren' => '100000009',
                    'siret' => '10000000900017',
                    'immatriculation' => 'RCS Paris',
                    'numero_tva' => 'FR88100000009',
                    'adresse' => '12 rue des Lilas',
                    'complement_adresse' => null,
                    'code_postal' => '75011',
                    'ville' => 'Paris',
                    'pays' => 'FR',
                    'siege' => [
                        'adresse' => '4 avenue Foch',
                        'complement_adresse' => null,
                        'code_postal' => '75016',
                        'ville' => 'Paris',
                    ],
                    'email' => 'contact@lefevre.test',
                    'telephone' => '01 23 45 67 89',
                    'regime_tva' => 'reel_normal_mensuel',
                    'option_debits_tva' => false,
                    'mentions' => [
                        'penalites_retard' => 'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.',
                        'indemnite_recouvrement' => 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
                        'escompte' => 'Pas d\'escompte pour paiement anticipé.',
                    ],
                ],
                'categorie_operation' => 'livraison_de_biens',
                'mentions' => ["Catégorie de l'opération : Livraison de biens"],
                'consultation_vies' => null,
                'reglements' => [['paiement_id' => $paiement->id, 'mode' => 'carte_bancaire', 'reference' => 'TRX-42', 'montant' => 2950]],
                'credits_avoir' => [],
            ],
        ], $this->contenu($vente)['donnees']);
        $this->assertSame(['montant' => 2950, 'perpetuel_reel' => 2950, 'perpetuel_absolu' => 2950], $this->contenu($vente)['gt']);
        $this->assertSame([
            'paiement' => ['client_id' => $client->id, 'mode' => 'carte_bancaire', 'montant' => 2950, 'reference' => 'TRX-42', 'notes' => null, 'recu_le' => '2026-10-09', 'cree_par' => $vendeuse->id],
            'contre_passe' => null,
            'client' => $ficheDuClient,
            'allocations' => [['facture_id' => $ticket->id, 'numero' => 'TK-LYON1-2026-000001', 'montant' => 2950]],
            'credits_imputes' => [],
        ], $this->contenu($encaissement)['donnees']);
        $this->assertSignatureValide($vente);
        $this->assertSignatureValide($encaissement);
        $this->assertAucunFlottant($this->contenu($vente));
        $this->assertAucunFlottant($this->contenu($encaissement));
        $this->assertSame(StatutFacture::Payee, $ticket->statut);
    }

    /**
     * Une vente en franchise en base signe sa franchise et la mention
     * qu'elle imprime (B10) ; l'option pour les débits, sans objet sans TVA
     * due, n'y figure pas.
     */
    public function test_une_vente_en_franchise_signe_sa_franchise_et_sa_mention(): void
    {
        $this->magasinActif(['regime_tva' => RegimeTva::FranchiseEnBase, 'option_debits_tva' => true]);
        $ticket = $this->encaisser(Client::factory()->create(), [[$this->produit('CAF-250', 'Café moulu 250 g', 12), 1]]);

        $donnees = $this->contenu($this->enregistrementDe($ticket, ChaineFiscale::Vente))['donnees'];

        $this->assertTrue($donnees['document']['franchise_en_base']);
        $this->assertSame([['taux' => 0, 'ht' => 1200, 'tva' => 0, 'ttc' => 1200]], $donnees['document']['ventilation']);
        $this->assertSame(["Catégorie de l'opération : Livraison de biens", 'TVA non applicable, art. 293 B du CGI'], $donnees['contexte']['mentions']);
    }

    /**
     * Une facture à un professionnel signe les montants calculés depuis le
     * HT de la ligne (E1) : 1 000 x 1,00 € à 20 % font 833,33 € HT et
     * 166,67 € de TVA, sur la ligne comme dans la ventilation.
     */
    public function test_une_facture_professionnelle_signe_la_tva_calculee_sur_son_total_ht(): void
    {
        $this->magasinActif();
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [[$this->produit('VIS-001', 'Vis à bois', 1), 1000]]);

        $vente = $this->enregistrementDe($facture, ChaineFiscale::Vente);
        $document = $this->contenu($vente)['donnees']['document'];

        $this->assertSignatureValide($vente);
        $this->assertSame(['prix_unitaire_ht' => 83, 'prix_unitaire_ttc' => 100, 'ht' => 83333, 'tva' => 16667, 'ttc' => 100000], Arr::only($document['lignes'][0], ['prix_unitaire_ht', 'prix_unitaire_ttc', 'ht', 'tva', 'ttc']));
        $this->assertSame([['taux' => 2000, 'ht' => 83333, 'tva' => 16667, 'ttc' => 100000]], $document['ventilation']);
        $this->assertSame(['ht' => 83333, 'tva' => 16667, 'ttc' => 100000], $document['totaux']);
    }

    /**
     * Une livraison intracommunautaire signe sa nature, sa livraison, sa
     * ligne à 0 % au prix sans la TVA de son produit, sa mention et la
     * vérification VIES qu'elle retient ; la vérification de l'intégrité
     * la recalcule sans anomalie.
     */
    public function test_une_livraison_intracommunautaire_signe_sa_nature_sa_livraison_et_sa_verification_vies(): void
    {
        $this->travelTo('2026-10-09 10:15:30');
        $magasin = $this->magasinActif();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $consultation = ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => CarbonImmutable::parse('2026-10-09 09:00:00', 'Europe/Paris'),
            'reponse' => '{"valid":true}',
        ]);
        $facture = $this->emettre($client, [[$this->produit('VIS-001', 'Vis à bois', 25), 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'livraison_date' => '2026-10-12',
            'transport_par' => TransportPar::Acquereur,
        ]);

        $vente = $this->enregistrementDe($facture, ChaineFiscale::Vente);
        $donnees = $this->contenu($vente)['donnees'];

        $this->assertSignatureValide($vente);
        $this->assertSame([
            'nature_operation' => 'livraison_intracommunautaire',
            'livraison' => ['adresse' => 'Musterstraße 1', 'complement' => null, 'code_postal' => '10115', 'ville' => 'Berlin', 'pays' => 'DE', 'date' => '2026-10-12', 'transport_par' => 'acquereur'],
            'consultation_vies_id' => $consultation->id,
        ], Arr::only($donnees['document'], ['nature_operation', 'livraison', 'consultation_vies_id']));
        $this->assertSame(['prix_unitaire_ht' => 2083, 'prix_unitaire_ttc' => 2500, 'taux_tva' => 0, 'taux_tva_du_prix' => 2000, 'ht' => 25000, 'tva' => 0, 'ttc' => 25000], Arr::only($donnees['document']['lignes'][0], ['prix_unitaire_ht', 'prix_unitaire_ttc', 'taux_tva', 'taux_tva_du_prix', 'ht', 'tva', 'ttc']));
        $this->assertSame(["Catégorie de l'opération : Livraison de biens", 'Exonération de TVA, article 262 ter I du CGI'], $donnees['contexte']['mentions']);
        $this->assertSame(['numero_tva' => 'DE123456789', 'valide' => true, 'identifiant' => 'WAPIAAAAW1234567', 'consultee_le' => '2026-10-09T09:00:00+02:00'], $donnees['contexte']['consultation_vies']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * La date d'émission est l'instant du verrou du registre, celui que
     * signe l'enregistrement, à la seconde ; l'année de numérotation est
     * celle de cet instant à Paris.
     */
    public function test_la_date_d_emission_est_l_horodatage_signe(): void
    {
        $this->travelTo('2027-01-01 00:30:12');
        $this->magasinActif();
        $produit = $this->produit('CAF-250', 'Café moulu 250 g', 12);

        $ticket = $this->encaisser(Client::factory()->create(), [[$produit, 1]]);

        $vente = $this->enregistrementDe($ticket, ChaineFiscale::Vente);
        $this->assertSame('2027-01-01 00:30:12', $ticket->date_emission->format('Y-m-d H:i:s'));
        $this->assertTrue($ticket->date_emission->equalTo($vente->horodatage));
        $this->assertSame('20261231233012Z', $this->contenu($vente)['horodatage']);
        $this->assertSame('TK-LYON1-2027-000001', $ticket->numero);
    }

    /**
     * La nuit du passage à l'heure d'hiver, 2 h 30 à Paris revient deux
     * fois : à 0 h 30 UTC (heure d'été) puis à 1 h 30 UTC. Un ticket émis
     * à la première, puis un avoir émis à 2 h 10 de l'heure d'hiver (1 h 10
     * UTC), gardent chacun leur instant : la date d'émission relue et la
     * date signée sont l'horodatage du registre, et l'avoir reste après le
     * ticket.
     */
    public function test_l_heure_qui_se_repete_au_passage_a_l_heure_d_hiver_garde_l_instant_de_chaque_emission(): void
    {
        $this->magasinActif();
        $client = Client::factory()->create();
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC'));
        $ticket = $this->encaisser($client, [[$produit, 1]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 01:10:00', 'UTC'));

        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);

        $venteDuTicket = $this->enregistrementDe($ticket, ChaineFiscale::Vente);
        $venteDeLAvoir = $this->enregistrementDe($avoir, ChaineFiscale::Vente);
        $this->assertSame(['20261025003000Z', '2026-10-25T02:30:00+02:00'], [$this->contenu($venteDuTicket)['horodatage'], $this->contenu($venteDuTicket)['donnees']['document']['date_emission']]);
        $this->assertSame(['20261025011000Z', '2026-10-25T02:10:00+01:00'], [$this->contenu($venteDeLAvoir)['horodatage'], $this->contenu($venteDeLAvoir)['donnees']['document']['date_emission']]);
        $this->assertTrue($ticket->fresh()->date_emission->equalTo($venteDuTicket->horodatage));
        $this->assertTrue($avoir->fresh()->date_emission->equalTo($venteDeLAvoir->horodatage));
        $this->assertTrue($avoir->fresh()->date_emission->greaterThan($ticket->fresh()->date_emission));
    }

    public function test_une_facture_professionnelle_sans_reglement_signe_son_echeance_et_aucun_reglement(): void
    {
        $this->travelTo('2026-10-09 10:15:30');
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create(['nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009']);
        $produit = $this->produit('CAF-250', 'Café moulu 250 g', 12);

        $facture = $this->emettre($client, [[$produit, 10]], ['conditions_paiement' => 'Paiement à 30 jours', 'notes' => 'Livraison au dépôt']);

        $donnees = $this->contenu($this->enregistrementDe($facture, ChaineFiscale::Vente))['donnees'];
        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'facture']], $this->registre());
        $this->assertSame('FAC-LYON1-2026-000001', $donnees['document']['numero']);
        $this->assertSame(['2026-11-08', 'Paiement à 30 jours', 'Livraison au dépôt'], [$donnees['document']['date_echeance'], $donnees['document']['conditions_paiement'], $donnees['document']['notes']]);
        $this->assertSame(['ht' => 10000, 'tva' => 2000, 'ttc' => 12000], $donnees['document']['totaux']);
        $this->assertSame(
            ['type_client' => 'professionnel', 'nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009', 'etabli_en_france' => true],
            Arr::only($donnees['document']['client'], ['type_client', 'nom', 'siren', 'siret', 'numero_tva', 'etabli_en_france']),
        );
        $this->assertSame([], $donnees['contexte']['reglements']);
        $this->assertSame([], $donnees['contexte']['credits_avoir']);
        $this->assertSame(StatutFacture::Emise, $facture->statut);
    }

    /**
     * Le capital social et l'immatriculation de la société, et les
     * mentions entre professionnels réglées par le magasin, le jour de
     * l'émission (H2, B9, K7), se signent avec l'émetteur : le contenu
     * signé garde ces valeurs même si la société et le magasin les
     * changent ensuite.
     */
    public function test_l_emetteur_signe_son_capital_son_immatriculation_et_ses_mentions_entre_professionnels(): void
    {
        $this->magasinActif([
            'capital_social' => '10000.00',
            'immatriculation' => 'RCS Lyon',
            'mention_penalites_retard' => 'Pénalités : 3 fois le taux légal.',
            'mention_indemnite_recouvrement' => 'Indemnité de recouvrement : 40 €.',
            'mention_escompte' => 'Escompte de 1 % sous 8 jours.',
        ]);
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [[$this->produit('A-020', 'Article à 20 €', 20), 1]]);
        $this->reglerLaSociete(app(TenantContext::class)->magasin(), ['capital_social' => '20000.00']);
        app(TenantContext::class)->magasin()->update(['mention_escompte' => 'Pas d\'escompte.']);

        $emetteur = $this->contenu($this->enregistrementDe($facture, ChaineFiscale::Vente))['donnees']['contexte']['emetteur'];

        $this->assertSame([1000000, 'RCS Lyon'], [$emetteur['capital_social'], $emetteur['immatriculation']]);
        $this->assertSame([
            'penalites_retard' => 'Pénalités : 3 fois le taux légal.',
            'indemnite_recouvrement' => 'Indemnité de recouvrement : 40 €.',
            'escompte' => 'Escompte de 1 % sous 8 jours.',
        ], $emetteur['mentions']);
    }

    /**
     * Le client signé est la copie figée à l'émission : la fiche qui change
     * ensuite (nom, type, identifiants) ne change ni le document ni ce
     * qu'en recalcule la vérification de l'intégrité.
     */
    public function test_le_client_signe_est_la_copie_figee_que_la_fiche_modifiee_ne_change_pas(): void
    {
        $this->freezeTime();
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create(['nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009']);
        $facture = $this->emettre($client, [[$this->produit('A-020', 'Article à 20 €', 20), 1]]);
        $signe = $this->contenu($this->enregistrementDe($facture, ChaineFiscale::Vente))['donnees']['document'];

        $client->update(['nom' => 'Jean Durand', 'type_client' => TypeClient::Particulier]);

        $this->assertSame($signe, app(ContenuFiscal::class)->blocDocument($facture->fresh()));
        $this->assertSame(
            ['type_client' => 'professionnel', 'nom' => 'Atelier Durand', 'siren' => '100000009', 'etabli_en_france' => true],
            Arr::only($signe['client'], ['type_client', 'nom', 'siren', 'etabli_en_france']),
        );
    }

    /**
     * Le réel somme les ventes et retranche les avoirs, l'absolu ajoute
     * leurs valeurs absolues : 120 € + 50 € - 20 € donnent 150 € de réel
     * et 190 € d'absolu.
     */
    public function test_les_grands_totaux_avancent_a_chaque_ticket_et_a_chaque_avoir(): void
    {
        $this->freezeTime();
        $this->magasinActif();
        $client = Client::factory()->create();
        $centEuros = $this->produit('A-100', 'Article à 100 €', 100);
        $vingtEuros = $this->produit('A-020', 'Article à 20 €', 20);
        $cinquanteEuros = $this->produit('A-050', 'Article à 50 €', 50);
        $premier = $this->encaisser($client, [[$centEuros, 1], [$vingtEuros, 1]]);
        $deuxieme = $this->encaisser($client, [[$cinquanteEuros, 1]]);

        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $premier->lignes->firstWhere('produit_id', $vingtEuros->id)->id, 'quantite' => 1]]);

        $this->assertSame(['montant' => 12000, 'perpetuel_reel' => 12000, 'perpetuel_absolu' => 12000], $this->contenu($this->enregistrementDe($premier, ChaineFiscale::Vente))['gt']);
        $this->assertSame(['montant' => 5000, 'perpetuel_reel' => 17000, 'perpetuel_absolu' => 17000], $this->contenu($this->enregistrementDe($deuxieme, ChaineFiscale::Vente))['gt']);
        $this->assertSame(['montant' => -2000, 'perpetuel_reel' => 15000, 'perpetuel_absolu' => 19000], $this->contenu($this->enregistrementDe($avoir, ChaineFiscale::Vente))['gt']);
        $this->assertSame(['gt_reel' => 15000, 'gt_absolu' => 19000], Arr::only(TeteRegistreFiscal::query()->sole()->chaines['vente'], ['gt_reel', 'gt_absolu']));
    }

    /**
     * Un avoir sur un ticket s'écrit en négatif, référence la ligne et le
     * numéro qu'il corrige, et inscrit une annulation de ticket (JET 190).
     */
    public function test_un_avoir_sur_un_ticket_signe_en_negatif_ses_lignes_d_origine_et_journalise_l_annulation(): void
    {
        $this->travelTo('2026-10-09 10:15:30');
        $this->magasinActif();
        $vendeuse = User::factory()->create(['name' => 'Zoé Lefèvre']);
        $this->actingAs($vendeuse);
        $client = Client::factory()->create(['nom' => 'Claire Petit']);
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        $ticket = $this->encaisser($client, [[$produit, 2]]);
        $ligne = $ticket->lignes->sole();

        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $ligne->id, 'quantite' => 1]], 'Article abîmé');

        $vente = $this->enregistrementDe($avoir, ChaineFiscale::Vente);
        $annulation = $this->enregistrementDe($avoir, ChaineFiscale::Evenement);
        $donnees = $this->contenu($vente)['donnees'];
        $this->assertSame([['evenement', 1, '260'], ['encaissement', 1, 'paiement'], ['vente', 1, 'ticket'], ['vente', 2, 'avoir'], ['evenement', 2, '190']], $this->registre());
        $this->assertSame([
            'numero' => 'AV-LYON1-2026-000001',
            'date_emission' => '2026-10-09T10:15:30+02:00',
            'client' => [
                'id' => $client->id,
                'comptoir' => false,
                'type_client' => 'particulier',
                'nom' => 'Claire Petit',
                'adresse' => null,
                'complement_adresse' => null,
                'code_postal' => null,
                'ville' => null,
                'pays' => 'FR',
                'siren' => null,
                'siret' => null,
                'numero_tva' => null,
                'identifiant_etranger' => null,
                'etabli_en_france' => true,
            ],
            'franchise_en_base' => false,
            'nature_operation' => 'vente_france',
            'cree_par' => $vendeuse->id,
            'devise' => 'EUR',
            'motif' => 'Article abîmé',
            'corrige' => 'ticket',
            'documents_corriges' => [['numero' => 'TK-LYON1-2026-000001', 'date_emission' => '2026-10-09']],
            'lignes' => [
                ['ordre' => 0, 'nature' => 'bien', 'produit_id' => $produit->id, 'reference' => 'A-020', 'description' => 'Article à 20 €', 'quantite' => -1, 'prix_unitaire_ht' => 1667, 'prix_unitaire_ttc' => 2000, 'taux_tva' => 2000, 'taux_tva_du_prix' => 2000, 'remise' => 0, 'ht' => -1667, 'tva' => -333, 'ttc' => -2000, 'origine' => ['ligne_facture_id' => $ligne->id, 'facture_id' => $ticket->id, 'numero' => 'TK-LYON1-2026-000001']],
            ],
            'ventilation' => [['taux' => 2000, 'ht' => -1667, 'tva' => -333, 'ttc' => -2000]],
            'totaux' => ['ht' => -1667, 'tva' => -333, 'ttc' => -2000],
        ], $donnees['document']);
        $this->assertSame([], $donnees['contexte']['imputations']);
        $this->assertSame(-2000, $this->contenu($vente)['gt']['montant']);
        $this->assertSame([
            'code' => 190,
            'libelle' => 'Annulation de ticket (avoir sur des tickets de caisse)',
            'details' => ['avoir' => 'AV-LYON1-2026-000001', 'tickets' => ['TK-LYON1-2026-000001'], 'montant_ttc' => -2000, 'sequence_vente' => 2, 'motif' => 'Article abîmé'],
        ], $this->contenu($annulation)['donnees']);
        $this->assertDatabaseMissing('traces_evenements', ['enregistrement_fiscal_id' => $annulation->id]);
        $this->assertSignatureValide($vente);
        $this->assertSignatureValide($annulation);
    }

    /**
     * Un avoir sur une facture impayée s'impute sur elle dans la même
     * transaction : l'imputation figure dans son contenu signé. Aucune
     * annulation de ticket n'est journalisée.
     */
    public function test_un_avoir_sur_une_facture_signe_son_imputation_sans_annulation_de_ticket(): void
    {
        $this->travelTo('2026-10-09 10:15:30');
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        $facture = $this->emettre($client, [[$produit, 3]]);

        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);

        $donnees = $this->contenu($this->enregistrementDe($avoir, ChaineFiscale::Vente))['donnees'];
        $this->assertSame(['facture', [['numero' => 'FAC-LYON1-2026-000001', 'date_emission' => '2026-10-09']]], [$donnees['document']['corrige'], $donnees['document']['documents_corriges']]);
        $this->assertSame([['facture_id' => $facture->id, 'numero' => 'FAC-LYON1-2026-000001', 'montant' => 2000]], $donnees['contexte']['imputations']);
        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'facture'], ['vente', 2, 'avoir']], $this->registre());
    }

    /**
     * Le crédit d'avoir du client couvre la nouvelle vente au comptoir :
     * le ticket signe ce crédit, et aucun paiement n'est encaissé.
     */
    public function test_une_vente_couverte_par_un_credit_d_avoir_signe_le_credit_et_aucun_reglement(): void
    {
        $this->freezeTime();
        $this->magasinActif();
        $client = Client::factory()->create();
        $produit = $this->produit('A-060', 'Article à 60 €', 60);
        $premier = $this->encaisser($client, [[$produit, 1]]);
        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]]);

        $ticket = $this->encaisser($client, [[$produit, 1]]);

        $contexte = $this->contenu($this->enregistrementDe($ticket, ChaineFiscale::Vente))['donnees']['contexte'];
        $this->assertSame([['avoir_id' => $avoir->id, 'numero' => 'AV-LYON1-2026-000001', 'montant' => 6000]], $contexte['credits_avoir']);
        $this->assertSame([], $contexte['reglements']);
        $this->assertSame(1, Paiement::query()->count());
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->count());
        $this->assertSame(StatutFacture::Payee, $ticket->statut);
    }

    public function test_un_paiement_de_trois_factures_signe_un_encaissement_avec_ses_trois_imputations(): void
    {
        $this->freezeTime();
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        $factures = [$this->emettre($client, [[$produit, 1]]), $this->emettre($client, [[$produit, 2]]), $this->emettre($client, [[$produit, 3]])];

        $paiement = app(EnregistrerPaiementAction::class)->handle(
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '120.00', 'reference' => 'VIR-7', 'recu_le' => today()->toDateString()],
            [['facture_id' => $factures[0]->id, 'montant' => '20.00'], ['facture_id' => $factures[1]->id, 'montant' => '40.00'], ['facture_id' => $factures[2]->id, 'montant' => '60.00']],
        );

        $encaissement = $this->enregistrementDe($paiement, ChaineFiscale::Encaissement);
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->count());
        $this->assertSame(['mode' => 'virement', 'montant' => 12000, 'reference' => 'VIR-7'], Arr::only($this->contenu($encaissement)['donnees']['paiement'], ['mode', 'montant', 'reference']));
        $this->assertSame([
            ['facture_id' => $factures[0]->id, 'numero' => 'FAC-LYON1-2026-000001', 'montant' => 2000],
            ['facture_id' => $factures[1]->id, 'numero' => 'FAC-LYON1-2026-000002', 'montant' => 4000],
            ['facture_id' => $factures[2]->id, 'numero' => 'FAC-LYON1-2026-000003', 'montant' => 6000],
        ], $this->contenu($encaissement)['donnees']['allocations']);
        $this->assertArrayNotHasKey('gt', $this->contenu($encaissement));
        $this->assertSignatureValide($encaissement);
    }

    /**
     * Sans clé de signature, rien n'est émis : le brouillon le reste, aucun
     * numéro n'est consommé, le stock ne bouge pas, rien n'est inscrit.
     */
    public function test_sans_cle_de_signature_l_emission_echoue_entierement(): void
    {
        $this->magasinActif();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 10]);
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3]]);
        config(['caisse_securisee.cle_privee' => null]);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($brouillon), ModuleFiscalNonConfigureException::class);

        $this->assertSame([StatutFacture::Brouillon, null, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero, $brouillon->fresh()->date_emission]);
        $this->assertDatabaseCount('sequences_documents', 0);
        $this->assertSame(10, $produit->fresh()->stock_quantite);
        $this->assertDatabaseCount('mouvements_stock', 0);
        $this->assertDatabaseCount('enregistrements_fiscaux', 0);
    }

    public function test_sans_cle_de_signature_la_caisse_n_enregistre_rien(): void
    {
        $this->magasinActif();
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        config(['caisse_securisee.cle_privee' => null]);

        $this->assertThrows(fn () => $this->encaisser(Client::factory()->create(), [[$produit, 1]]), ModuleFiscalNonConfigureException::class);

        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
        $this->assertDatabaseCount('enregistrements_fiscaux', 0);
    }

    /**
     * Une vente du magasin A n'écrit rien dans le registre du magasin B,
     * dont la chaîne des ventes et les grands totaux restent les siens.
     */
    public function test_une_vente_n_ecrit_que_dans_le_registre_de_son_magasin(): void
    {
        $this->freezeTime();
        $magasinB = $this->magasinActif(['code' => 'LYON2']);
        $this->encaisser(Client::factory()->create(), [[$this->produit('B-050', 'Article de B', 50), 1]]);
        $registreDeB = fn (): array => DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinB->id)->orderBy('id')->get()->map(fn (object $ligne): array => (array) $ligne)->all();
        $teteDeB = fn (): ?string => DB::table('tetes_registre_fiscal')->where('magasin_id', $magasinB->id)->value('chaines');
        [$avantRegistreDeB, $avantTeteDeB] = [$registreDeB(), $teteDeB()];
        $this->magasinActif();

        $ticket = $this->encaisser(Client::factory()->create(), [[$this->produit('A-020', 'Article de A', 20), 1]]);

        $vente = $this->enregistrementDe($ticket, ChaineFiscale::Vente);
        $this->assertSame([1, null], [$vente->sequence, $vente->signature_precedente]);
        $this->assertSame(['montant' => 2000, 'perpetuel_reel' => 2000, 'perpetuel_absolu' => 2000], $this->contenu($vente)['gt']);
        $this->assertSame($avantRegistreDeB, $registreDeB());
        $this->assertSame($avantTeteDeB, $teteDeB());
    }

    /**
     * Le registre se verrouille avant la facture, relue verrouillée, puis
     * la numérotation : un autre ordre exposerait deux ventes simultanées
     * à un interblocage.
     */
    public function test_l_emission_verrouille_le_registre_puis_la_facture_puis_la_numerotation(): void
    {
        $this->magasinActif();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit('A-020', 'Article à 20 €', 20)->id, 'quantite' => 1]]);

        $requetes = $this->requetesDe(fn () => app(EmettreFactureAction::class)->handle($brouillon));

        $registre = $this->position($requetes, 'select * from "tetes_registre_fiscal"');
        $facture = $this->position($requetes, 'select * from "factures"');
        $numerotation = $this->position($requetes, 'select * from "sequences_documents"');
        $this->assertLessThan($facture, $registre);
        $this->assertLessThan($numerotation, $facture);
    }

    /**
     * La caisse verrouille le registre avant toute lecture, puis crée la
     * vente et encaisse son règlement sous ce verrou.
     */
    public function test_la_caisse_verrouille_le_registre_avant_toute_lecture(): void
    {
        $this->magasinActif();
        $produit = $this->produit('A-020', 'Article à 20 €', 20);
        $client = Client::query()->findOrFail(Client::factory()->create()->id);

        $requetes = $this->requetesDe(fn () => $this->encaisser($client, [[$produit, 1]]));

        $this->assertSame(0, $this->position($requetes, 'select * from "tetes_registre_fiscal"'));
        $this->assertLessThan($this->position($requetes, 'insert into "factures"'), $this->position($requetes, 'select * from "tetes_registre_fiscal"'));
    }

    /**
     * Un avoir impute son crédit sur ce qui reste dû de ses factures, comme
     * un paiement simultané : le verrou du registre est la toute première
     * lecture de sa transaction (ni le magasin du client, ni les lignes
     * qu'il corrige ne sont lus avant lui).
     */
    public function test_un_avoir_verrouille_le_registre_avant_toute_lecture(): void
    {
        $this->magasinActif();
        $ticket = $this->encaisser(Client::factory()->create(), [[$this->produit('A-020', 'Article à 20 €', 20), 1]]);
        $ligne = $ticket->lignes->sole();
        $client = Client::query()->findOrFail($ticket->client_id);

        $requetes = $this->requetesDe(fn () => app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $ligne->id, 'quantite' => 1]]));

        $this->assertSame(0, $this->position($requetes, 'select * from "tetes_registre_fiscal"'));
        $this->assertLessThan($this->position($requetes, 'insert into "avoirs"'), $this->position($requetes, 'select * from "lignes_facture"'));
    }

    /**
     * Le client d'un autre magasin est refusé sous le verrou du registre
     * du magasin actif, avant tout avoir.
     */
    public function test_un_avoir_pour_le_client_d_un_autre_magasin_est_refuse(): void
    {
        $this->magasinActif(['code' => 'LYON2']);
        $clientDeB = Client::factory()->create();
        $this->magasinActif();

        $this->assertThrows(fn () => app(EnregistrerAvoirAction::class)->handle($clientDeB, [['ligne_facture_id' => 1, 'quantite' => 1]]), LogicException::class, 'Ce client n\'est pas un client du magasin.');

        $this->assertSame(0, Avoir::query()->withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * Le verrou du registre est la toute première lecture du paiement :
     * rien n'est lu avant lui (ni le magasin du client, ni la facture, ni
     * ce qui en reste dû), qu'un paiement simultané pourrait avoir changé
     * pendant l'attente du verrou.
     */
    public function test_un_paiement_verrouille_le_registre_avant_toute_lecture(): void
    {
        $this->magasinActif();
        $facture = $this->emettre(Client::factory()->professionnel()->create(), [[$this->produit('A-020', 'Article à 20 €', 20), 1]]);
        $client = Client::query()->findOrFail($facture->client_id);

        $requetes = $this->requetesDe(fn () => app(EnregistrerPaiementAction::class)->handle(
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => '20.00']],
        ));

        $this->assertSame(0, $this->position($requetes, 'select * from "tetes_registre_fiscal"'));
        $this->assertLessThan($this->position($requetes, 'select * from "factures"'), $this->position($requetes, 'insert into "paiements"'));
    }
}
