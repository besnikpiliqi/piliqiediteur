<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les preuves d'exonération d'une vente hors de France (I4) : le magasin
 * enregistre leurs références sur la facture émise, et ce qui manque lui
 * est rappelé, sur la fiche comme dans la liste des factures. Le magasin
 * de Lyon vend hors de France depuis le 9 octobre 2026.
 */
class PreuvesOperationTest extends TestCase
{
    use RefreshDatabase;

    private const DECLARATION = 'Déclaration d\'export (MRN) avec certification de sortie, ou déclaration CN23 (colis postal)';

    private const DOCUMENT_DE_TRANSPORT = 'Document de transport : CMR signée, connaissement, LTA, facture du transporteur ou de fret';

    private const SECOND_DOCUMENT = 'Un second document de transport, ou une autre preuve d\'expédition : assurance du transport, paiement bancaire du transport, document d\'une autorité publique, récépissé d\'entrepositaire';

    /**
     * Le magasin de Lyon à l'identité légale complète et son propriétaire
     * connecté, le 9 octobre 2026 à 10 h 15 à Paris : il note les preuves
     * (il a « noter les preuves d'exonération et les bordereaux de
     * détaxe »), mais ne vend jamais ; ses ventes se font par le
     * responsable de test (voir venteEmise()).
     */
    private function magasin(): Magasin
    {
        $this->travelTo('2026-10-09 10:15:00');
        $proprietaire = User::factory()->create();
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire));
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Une vente émise par le responsable de test du magasin actif, de 10
     * clés USB à 12,00 € TTC du catalogue :
     * - « export » : à Acme Inc., expédiée à New York ;
     * - « dom » : à un particulier, expédiée à Fort-de-France ;
     * - « intracommunautaire » : à Muster GmbH, expédiée à Berlin par le
     *   magasin le 12 octobre 2026, son n° de TVA valide sur VIES le matin ;
     * - « intracommunautaire_enlevee » : la même, enlevée par le client le
     *   20 octobre 2026 ;
     * - « france » : une vente en France à un particulier.
     */
    private function venteEmise(string $vente): Facture
    {
        $produit = Produit::factory()->create(['nom' => 'Clé USB', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $berlin = ['livraison_adresse' => 'Musterstraße 1', 'livraison_code_postal' => '10115', 'livraison_ville' => 'Berlin', 'livraison_pays' => 'DE'];
        [$client, $nature] = match ($vente) {
            'export' => [
                Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']),
                ['nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US'],
            ],
            'dom' => [
                Client::factory()->create(),
                ['nature_operation' => NatureOperation::LivraisonDom, 'livraison_adresse' => '1 rue Victor Hugo', 'livraison_code_postal' => '97200', 'livraison_ville' => 'Fort-de-France', 'livraison_pays' => 'FR'],
            ],
            'intracommunautaire' => [
                $this->clientAllemandVerifie(),
                ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, ...$berlin, 'livraison_date' => '2026-10-12', 'transport_par' => 'magasin'],
            ],
            'intracommunautaire_enlevee' => [
                $this->clientAllemandVerifie(),
                ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, ...$berlin, 'livraison_date' => '2026-10-20', 'transport_par' => 'acquereur'],
            ],
            'france' => [Client::factory()->create(), []],
        };

        $vendeur = $this->responsableDeTest(app(TenantContext::class)->magasin());

        return app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => $produit->id, 'quantite' => 10]], $nature));
    }

    /**
     * Muster GmbH, dont le n° DE123456789 a été trouvé valide sur VIES le
     * 9 octobre 2026 à 9 h à Paris.
     */
    private function clientAllemandVerifie(): Client
    {
        $client = Client::factory()->etranger('DE', 'DE123456789')->create(['nom' => 'Muster GmbH', 'code_postal' => '10115']);
        ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => CarbonImmutable::parse('2026-10-09 09:00', 'Europe/Paris'),
            'reponse' => '{"valid":true}',
        ]);

        return $client;
    }

    private function ajouter(Facture $facture, TypePreuve $type, string $reference, ?string $date = null, ?string $emetteur = null): PreuveOperation
    {
        return app(AjouterPreuveOperationAction::class)->handle(self::auteur(), $facture, $type, $reference, $date, $emetteur);
    }

    /**
     * @return list<string>
     */
    private function manquantes(Facture $facture): array
    {
        return app(PreuvesRequises::class)->manquantes($facture);
    }

    public function test_un_export_avec_sa_declaration_d_export_est_complet(): void
    {
        $magasin = $this->magasin();
        $export = $this->venteEmise('export');
        $manquaitAvant = $this->manquantes($export);

        $preuve = $this->ajouter($export, TypePreuve::Mrn, ' 26FR000000123456E1 ', '2026-10-09', ' Bureau de douane de Lyon ');

        $this->assertSame([self::DECLARATION], $manquaitAvant);
        $this->assertSame([], $this->manquantes($export));
        $preuve->refresh();
        $this->assertSame(
            [$magasin->id, $export->id, TypePreuve::Mrn, '26FR000000123456E1', '2026-10-09', 'Bureau de douane de Lyon', $this->proprietaireDe($magasin)->id],
            [$preuve->magasin_id, $preuve->facture_id, $preuve->type, $preuve->reference, $preuve->date_document->toDateString(), $preuve->emetteur, $preuve->note_par],
        );
    }

    public function test_une_livraison_vers_un_dom_avec_un_seul_cn23_attend_un_document_de_transport(): void
    {
        $this->magasin();
        $livraisonDom = $this->venteEmise('dom');

        $this->ajouter($livraisonDom, TypePreuve::Cn23, 'CN23-0001');

        $this->assertSame([self::DOCUMENT_DE_TRANSPORT], $this->manquantes($livraisonDom));
    }

    public function test_une_livraison_intracommunautaire_avec_un_seul_document_de_transport_attend_une_seconde_preuve(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire');
        $this->ajouter($livraison, TypePreuve::TransportA, 'CMR-2026-0042', '2026-10-09', 'Transports Martin');
        $avecUnDocument = $this->manquantes($livraison);

        $this->ajouter($livraison, TypePreuve::TransportB, 'POLICE-778', '2026-10-09', 'Assurances du Rhône');

        $this->assertSame([self::SECOND_DOCUMENT], $avecUnDocument);
        $this->assertSame([], $this->manquantes($livraison));
    }

    /**
     * Enlevés par le client le 20 octobre 2026, les biens attendent en plus
     * son attestation de réception, au plus tard le 10 novembre 2026.
     */
    public function test_une_livraison_enlevee_par_le_client_attend_son_attestation_au_10_du_mois_suivant(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire_enlevee');

        $this->ajouter($livraison, TypePreuve::TransportA, 'CMR-2026-0042');
        $this->ajouter($livraison, TypePreuve::TransportB, 'POLICE-778');

        $this->assertSame(['Attestation de réception de l\'acquéreur, attendue au plus tard le 10/11/2026'], $this->manquantes($livraison));
    }

    public function test_une_attestation_attendue_depuis_un_jour_passe_est_signalee_en_retard(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire_enlevee');
        $this->ajouter($livraison, TypePreuve::TransportA, 'CMR-2026-0042');
        $this->ajouter($livraison, TypePreuve::TransportA, 'FRET-2026-0007');

        $this->travelTo('2026-11-11 08:00:00');

        $this->assertSame(['Attestation de réception de l\'acquéreur, attendue au plus tard le 10/11/2026 : délai dépassé'], $this->manquantes($livraison));
    }

    /**
     * Ce que la fiche dit (PHP) et ce que liste le filtre (SQL) concordent,
     * pour chaque nature et chaque combinaison de preuves.
     *
     * @return array<string, array{0: string, 1: list<TypePreuve>, 2: bool}>
     */
    public static function preuvesParVente(): array
    {
        return [
            'export sans preuve' => ['export', [], false],
            'export avec un CN23' => ['export', [TypePreuve::Cn23], true],
            'DOM avec une déclaration et un document de transport' => ['dom', [TypePreuve::Mrn, TypePreuve::TransportA], true],
            'DOM avec un document de transport seul' => ['dom', [TypePreuve::TransportA], false],
            'intracommunautaire avec deux documents de transport' => ['intracommunautaire', [TypePreuve::TransportA, TypePreuve::TransportA], true],
            'intracommunautaire avec deux autres preuves seulement' => ['intracommunautaire', [TypePreuve::TransportB, TypePreuve::TransportB], false],
            'intracommunautaire sans preuve' => ['intracommunautaire', [], false],
            'enlevée avec ses documents et l\'attestation' => ['intracommunautaire_enlevee', [TypePreuve::TransportA, TypePreuve::TransportB, TypePreuve::AttestationAcquereur], true],
            'enlevée avec l\'attestation sans document de transport' => ['intracommunautaire_enlevee', [TypePreuve::TransportB, TypePreuve::AttestationAcquereur], false],
        ];
    }

    /**
     * @param  list<TypePreuve>  $types
     */
    #[DataProvider('preuvesParVente')]
    public function test_la_fiche_et_le_filtre_disent_la_meme_chose_des_preuves(string $vente, array $types, bool $complete): void
    {
        $this->magasin();
        $facture = $this->venteEmise($vente);
        foreach ($types as $rang => $type) {
            $this->ajouter($facture, $type, "REF-{$rang}");
        }

        $manquantes = $this->manquantes($facture);
        $listees = Facture::query()->preuvesAFournir()->pluck('id')->all();

        $this->assertSame($complete, $manquantes === []);
        $this->assertSame($complete ? [] : [$facture->id], $listees);
    }

    /**
     * Le filtre ne garde que les factures dont une preuve manque, jamais
     * une vente complète ni une vente en France, et le badge du menu les
     * compte.
     */
    public function test_le_filtre_preuves_a_fournir_ne_liste_que_les_factures_incompletes_et_le_badge_les_compte(): void
    {
        $this->magasin();
        $incomplete = $this->venteEmise('dom');
        $complete = $this->venteEmise('export');
        $this->ajouter($complete, TypePreuve::Mrn, '26FR000000123456E1');
        $enFrance = $this->venteEmise('france');

        $liste = Livewire::test(ListFactures::class)->filterTable('preuves_a_fournir');

        $liste->assertCanSeeTableRecords([$incomplete])->assertCanNotSeeTableRecords([$complete, $enFrance]);
        $this->assertSame('1', FactureResource::getNavigationBadge());
    }

    /**
     * Le badge ne compte que les factures que l'utilisateur voit (B16) : un
     * vendeur ne voit pas l'export incomplet de son responsable.
     */
    public function test_le_badge_ne_compte_pas_les_factures_qu_un_vendeur_ne_voit_pas(): void
    {
        $magasin = $this->magasin();
        $this->venteEmise('export');

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));

        $this->assertNull(FactureResource::getNavigationBadge());
    }

    /**
     * Une vente entièrement corrigée par un avoir n'existe plus : elle ne
     * demande plus aucune preuve, et la fiche le dit.
     */
    public function test_une_vente_entierement_corrigee_par_un_avoir_ne_demande_plus_de_preuve(): void
    {
        $this->magasin();
        $export = $this->venteEmise('export');

        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $export->client, [['ligne_facture_id' => $export->lignes->sole()->id, 'quantite' => 10]], 'Commande annulée avant l\'expédition');

        $this->assertSame([], $this->manquantes($export));
        $this->assertSame([], Facture::query()->preuvesAFournir()->pluck('id')->all());
        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])
            ->assertSeeText('Aucune preuve à fournir : la vente est entièrement corrigée par des avoirs');
    }

    public function test_une_preuve_sur_une_vente_en_france_est_refusee(): void
    {
        $this->magasin();
        $enFrance = $this->venteEmise('france');

        $this->assertThrows(
            fn () => $this->ajouter($enFrance, TypePreuve::Mrn, '26FR000000123456E1'),
            LogicException::class,
            'Une vente de nature « Vente en France » ne demande aucune preuve d\'exonération.',
        );

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    public function test_un_type_de_preuve_que_la_nature_n_admet_pas_est_refuse(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire');

        $this->assertThrows(
            fn () => $this->ajouter($livraison, TypePreuve::Mrn, '26FR000000123456E1'),
            LogicException::class,
            'Ce type de preuve ne concerne pas une vente de nature « Livraison intracommunautaire à un professionnel ».',
        );

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    /**
     * Le même document compterait sinon deux fois parmi les deux documents
     * de transport qu'exige une livraison intracommunautaire.
     */
    public function test_la_meme_preuve_ne_s_enregistre_qu_une_fois_sur_une_facture(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire');
        $this->ajouter($livraison, TypePreuve::TransportA, 'CMR-2026-0042');

        $this->assertThrows(
            fn () => $this->ajouter($livraison, TypePreuve::TransportA, ' CMR-2026-0042 '),
            LogicException::class,
            'La preuve « CMR-2026-0042 » est déjà enregistrée sur cette facture.',
        );

        $this->assertSame(1, PreuveOperation::query()->count());
        $this->assertSame([self::SECOND_DOCUMENT], $this->manquantes($livraison));
    }

    /**
     * Un document ne compte qu'une fois, même noté sous un autre type ou
     * dans une autre casse : une seule lettre de voiture ne vaut pas les
     * deux preuves d'une livraison intracommunautaire, ni une seule
     * déclaration d'export la déclaration et le transport d'une livraison
     * vers un DOM.
     *
     * @return array<string, array{0: string, 1: TypePreuve, 2: string, 3: TypePreuve, 4: string, 5: string}>
     */
    public static function memeDocumentNoteDeuxFois(): array
    {
        return [
            'lettre de voiture notée aussi en autre preuve d\'expédition' => ['intracommunautaire', TypePreuve::TransportA, 'CMR-2026-0042', TypePreuve::TransportB, 'CMR-2026-0042', self::SECOND_DOCUMENT],
            'lettre de voiture notée de nouveau en minuscules' => ['intracommunautaire', TypePreuve::TransportA, 'CMR-2026-0042', TypePreuve::TransportA, 'cmr-2026-0042', self::SECOND_DOCUMENT],
            'déclaration d\'export notée aussi en document de transport' => ['dom', TypePreuve::Mrn, '26FR000000123456E1', TypePreuve::TransportA, '26fr000000123456e1', self::DOCUMENT_DE_TRANSPORT],
        ];
    }

    #[DataProvider('memeDocumentNoteDeuxFois')]
    public function test_un_meme_document_ne_compte_qu_une_fois_quels_que_soient_son_type_et_sa_casse(string $vente, TypePreuve $premierType, string $reference, TypePreuve $secondType, string $memeReference, string $manquante): void
    {
        $this->magasin();
        $facture = $this->venteEmise($vente);
        $this->ajouter($facture, $premierType, $reference);

        $this->assertThrows(
            fn () => $this->ajouter($facture, $secondType, $memeReference),
            LogicException::class,
            "La preuve « {$reference} » est déjà enregistrée sur cette facture.",
        );

        $this->assertSame(1, PreuveOperation::query()->count());
        $this->assertSame([$manquante], $this->manquantes($facture));
        $this->assertSame([$facture->id], Facture::query()->preuvesAFournir()->pluck('id')->all());
    }

    public function test_une_preuve_ne_s_enregistre_pas_sur_un_brouillon(): void
    {
        $magasin = $this->magasin();
        $brouillon = app(CreerFactureAction::class)->handle($this->responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '12.00', 'taux_tva' => 20])->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::LivraisonDom, 'livraison_adresse' => '1 rue Victor Hugo', 'livraison_code_postal' => '97200', 'livraison_ville' => 'Fort-de-France', 'livraison_pays' => 'FR',
        ]);

        $this->assertThrows(
            fn () => $this->ajouter($brouillon, TypePreuve::Cn23, 'CN23-0001'),
            LogicException::class,
            'Une preuve s\'enregistre sur une facture émise : émettez d\'abord ce brouillon.',
        );

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    public function test_une_preuve_datee_du_futur_ou_sans_reference_est_refusee(): void
    {
        $this->magasin();
        $export = $this->venteEmise('export');

        $this->assertThrows(fn () => $this->ajouter($export, TypePreuve::Mrn, '26FR000000123456E1', '2026-10-10'), InvalidArgumentException::class, 'La date du document ne peut pas être dans le futur.');
        $this->assertThrows(fn () => $this->ajouter($export, TypePreuve::Mrn, '   '), InvalidArgumentException::class, 'Indiquez la référence de la preuve, en 100 caractères au plus.');

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    /**
     * Suspendu, le magasin ne fait plus que consulter et télécharger.
     */
    public function test_un_magasin_suspendu_n_enregistre_plus_de_preuve(): void
    {
        $magasin = $this->magasin();
        $export = $this->venteEmise('export');
        $magasin->forceFill(['suspendu_le' => now()])->save();

        $this->assertThrows(
            fn () => $this->ajouter($export, TypePreuve::Mrn, '26FR000000123456E1'),
            LogicException::class,
            'Ce magasin est suspendu : seules la consultation et les téléchargements restent possibles.',
        );

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    public function test_une_preuve_ne_se_modifie_ni_ne_se_supprime_par_le_modele(): void
    {
        $this->magasin();
        $preuve = $this->ajouter($this->venteEmise('export'), TypePreuve::Mrn, '26FR000000123456E1');

        $this->assertThrows(
            fn () => $preuve->forceFill(['reference' => '26FR999999999999E9'])->save(),
            DonneeFiscaleInalterableException::class,
            'Une preuve d\'exonération enregistrée ne se modifie ni ne se supprime. Modification refusée : reference.',
        );
        $this->assertThrows(
            fn () => $preuve->delete(),
            DonneeFiscaleInalterableException::class,
            'Une preuve d\'exonération enregistrée ne se modifie ni ne se supprime. Suppression refusée.',
        );

        $this->assertSame('26FR000000123456E1', $preuve->fresh()->reference);
    }

    public function test_la_base_refuse_de_modifier_ou_de_supprimer_une_preuve(): void
    {
        $this->magasin();
        $id = $this->ajouter($this->venteEmise('export'), TypePreuve::Mrn, '26FR000000123456E1')->id;

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('preuves_operation')->where('id', $id)->update(['reference' => 'X'])), QueryException::class, 'preuves_operation : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('preuves_operation')->where('id', $id)->delete()), QueryException::class, 'preuves_operation : table en ajout seul, suppression refusée');

        $this->assertSame(1, DB::table('preuves_operation')->where('id', $id)->where('reference', '26FR000000123456E1')->count());
    }

    /**
     * En production, le compte de l'application n'a pas le droit de
     * modifier une table en ajout seul, que PostgreSQL exige pour un
     * SELECT ... FOR UPDATE : seule la facture se verrouille.
     */
    public function test_enregistrer_une_preuve_ne_verrouille_aucune_preuve(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite n\'écrit aucun verrou de ligne : ce test ne tourne que sous PostgreSQL.');
        }

        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire');
        $verrous = [];
        DB::listen(static function (QueryExecuted $requete) use (&$verrous): void {
            if (preg_match('/\bfor\s+(no\s+key\s+update|update|key\s+share|share)\b/i', $requete->sql) === 1) {
                $verrous[] = $requete->sql;
            }
        });

        $this->ajouter($livraison, TypePreuve::TransportA, 'CMR-2026-0042');
        $this->ajouter($livraison, TypePreuve::TransportB, 'POLICE-778');

        $this->assertCount(2, $verrous);
        $this->assertSame([], array_filter($verrous, fn (string $sql): bool => ! str_starts_with($sql, 'select * from "factures"')));
    }

    /**
     * Depuis la fiche, le bouton propose les types que la nature admet,
     * enregistre la preuve et l'affiche aussitôt, avec ce qui manque.
     */
    public function test_ajouter_une_preuve_depuis_la_fiche_l_enregistre_et_l_affiche(): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('dom');

        Livewire::test(ViewFacture::class, ['record' => $livraison->getRouteKey()])
            ->assertSeeText('À fournir : '.self::DECLARATION)
            ->callAction('ajouter_preuve', ['type' => TypePreuve::Cn23->value, 'reference' => 'CN23-0001', 'date_document' => '2026-10-09', 'emetteur' => 'La Poste'])
            ->assertHasNoActionErrors()
            ->assertNotified('Preuve enregistrée.')
            ->assertSeeText('CN23-0001')
            ->assertSeeText('La Poste')
            ->assertSeeText('À fournir : '.self::DOCUMENT_DE_TRANSPORT)
            ->assertDontSeeText('À fournir : '.self::DECLARATION);

        $this->assertSame(TypePreuve::Cn23, PreuveOperation::query()->sole()->type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesHorsDeLaListe(): array
    {
        return [
            'type inconnu' => ['inconnu'],
            'type que la nature n\'admet pas' => [TypePreuve::Mrn->value],
        ];
    }

    /**
     * Le formulaire n'accepte que les types qu'il propose, ceux que la
     * nature admet : un autre type, envoyé sans passer par la liste, est
     * une erreur de saisie, jamais une page d'erreur.
     */
    #[DataProvider('typesHorsDeLaListe')]
    public function test_un_type_de_preuve_hors_de_la_liste_est_une_erreur_du_formulaire(string $type): void
    {
        $this->magasin();
        $livraison = $this->venteEmise('intracommunautaire');

        Livewire::test(ViewFacture::class, ['record' => $livraison->getRouteKey()])
            ->callAction('ajouter_preuve', ['type' => $type, 'reference' => 'CMR-2026-0042'])
            ->assertHasActionErrors(['type' => 'in']);

        $this->assertSame(0, PreuveOperation::query()->count());
    }

    /**
     * Le bouton est réservé à qui est autorisé à noter les preuves
     * d'exonération et les bordereaux de détaxe (FacturePolicy::justifier(),
     * réponse (b) de l'éditeur à Q-V2) : le propriétaire, qui ne vend pas,
     * l'a ; un comptable, qui voit toutes les factures, ne l'a pas, ni un
     * expert-comptable. Il n'apparaît pas non plus sur une vente en France.
     */
    public function test_le_bouton_ajouter_une_preuve_est_reserve_a_qui_est_autorise_a_noter_les_preuves_d_une_vente_exoneree(): void
    {
        $magasin = $this->magasin();
        $export = $this->venteEmise('export');
        $enFrance = $this->venteEmise('france');

        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionVisible('ajouter_preuve');
        Livewire::test(ViewFacture::class, ['record' => $enFrance->getRouteKey()])->assertActionHidden('ajouter_preuve');
        $expert = $this->expertComptable($magasin);
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable()));

        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('ajouter_preuve');
        $this->actingAs($expert);

        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('ajouter_preuve');
    }
}
