<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Facturation\Support\GardesDesClotures;
use App\Domain\Facturation\Support\GardesDesDocumentsEmis;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Art. 286 I-3° bis du CGI : une donnée fiscale validée ne se modifie ni
 * ne se supprime. Une facture émise ne change plus que de statut de
 * règlement ; un avoir émis, les lignes des documents émis, les paiements
 * et les imputations ne changent plus du tout. Le modèle le refuse (voir
 * ProtegeLesDonneesValidees), la base aussi pour les tables où l'on ne
 * fait qu'ajouter, et les clés étrangères refusent de supprimer une fiche
 * qu'un document référence.
 */
class InalterabiliteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function emettre(Client $client, int $quantite = 3): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => $quantite]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneEnBase(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    /**
     * @return array<string, array{0: Closure(): array<string, mixed>, 1: string}>
     */
    public static function modificationsDUneFactureEmise(): array
    {
        return [
            'total' => [static fn (): array => ['total_ttc' => '1.00'], 'total_ttc'],
            'client' => [static fn (): array => ['client_id' => Client::factory()->create()->id], 'client_id'],
            'nom du client figé' => [static fn (): array => ['client_nom' => 'Autre nom'], 'client_nom'],
            'type du client' => [static fn (): array => ['type_client' => TypeClient::Professionnel], 'type_client'],
            'numéro' => [static fn (): array => ['numero' => 'TK-LYON1-2026-999999'], 'numero'],
            'date d\'émission' => [static fn (): array => ['date_emission' => self::enUtc('2020-01-01 00:00:00')], 'date_emission'],
            'notes' => [static fn (): array => ['notes' => 'Réécrite après coup'], 'notes'],
        ];
    }

    /**
     * @param  Closure(): array<string, mixed>  $modification
     */
    #[DataProvider('modificationsDUneFactureEmise')]
    public function test_une_facture_emise_ne_se_modifie_plus(Closure $modification, string $colonne): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $avant = $this->ligneEnBase('factures', $facture->id);
        $attributs = $modification();

        $this->assertThrows(
            fn () => $facture->forceFill($attributs)->save(),
            DonneeFiscaleInalterableException::class,
            "Une facture ou un ticket émis ne se modifie plus : seul son statut de règlement évolue, et une correction passe par un avoir. Modification refusée : {$colonne}.",
        );

        $this->assertSame($avant, $this->ligneEnBase('factures', $facture->id));
    }

    /**
     * La garde lit la base, pas la copie en mémoire : une fiche chargée
     * quand la facture était encore un brouillon ne la réécrit pas une fois
     * émise.
     */
    public function test_une_copie_chargee_avant_l_emission_ne_reecrit_pas_la_facture_emise(): void
    {
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $copieDuBrouillon = Facture::query()->findOrFail($brouillon->id);
        app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $this->assertThrows(fn () => $copieDuBrouillon->forceFill(['notes' => 'Réécrite après coup'])->save(), DonneeFiscaleInalterableException::class, 'Modification refusée : notes.');

        $this->assertNull($brouillon->fresh()->notes);
    }

    public function test_une_facture_emise_ne_se_supprime_pas(): void
    {
        $facture = $this->emettre(Client::factory()->create());

        $this->assertThrows(fn () => $facture->delete(), DonneeFiscaleInalterableException::class, 'Une facture ou un ticket émis ne se modifie plus : seul son statut de règlement évolue, et une correction passe par un avoir. Suppression refusée.');

        $this->assertModelExists($facture);
    }

    /**
     * @return array<string, array{0: Closure(Facture): mixed, 1: string}>
     */
    public static function ecrituresSurLesLignesDUneFactureEmise(): array
    {
        return [
            'modification' => [static fn (Facture $facture): bool => $facture->lignes->sole()->forceFill(['quantite' => 30, 'montant_ttc' => '600.00'])->save(), 'Modification refusée : quantite, montant_ttc.'],
            'suppression' => [static fn (Facture $facture): ?bool => $facture->lignes->sole()->delete(), 'Suppression refusée.'],
            'ajout' => [static fn (Facture $facture): Model => $facture->lignes()->create([
                'produit_id' => $facture->lignes->sole()->produit_id,
                'nature' => NatureLigne::Bien,
                'description' => 'Article ajouté après coup',
                'quantite' => 1,
                'prix_unitaire_ht' => '16.67',
                'prix_unitaire_ttc' => '20.00',
                'taux_tva' => '20.00',
                'montant_ht' => '16.67',
                'montant_tva' => '3.33',
                'montant_ttc' => '20.00',
                'ordre' => 1,
            ]), 'Ajout refusé.'],
        ];
    }

    /**
     * @param  Closure(Facture): mixed  $ecriture
     */
    #[DataProvider('ecrituresSurLesLignesDUneFactureEmise')]
    public function test_les_lignes_d_une_facture_emise_ne_se_modifient_ni_ne_se_suppriment_ni_ne_s_ajoutent(Closure $ecriture, string $refus): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $avant = DB::table('lignes_facture')->get()->all();

        $this->assertThrows(fn () => $ecriture($facture), DonneeFiscaleInalterableException::class, "Les lignes d'une facture ou d'un ticket émis ne se modifient plus : une correction passe par un avoir. {$refus}");

        $this->assertEquals($avant, DB::table('lignes_facture')->get()->all());
    }

    /**
     * @return array<string, array{0: Closure(Avoir): mixed, 1: string}>
     */
    public static function ecrituresSurUnAvoirEmis(): array
    {
        return [
            'modification de l\'avoir' => [static fn (Avoir $avoir): bool => $avoir->forceFill(['motif' => 'Réécrit après coup'])->save(), 'Un avoir émis ne se modifie plus. Modification refusée : motif.'],
            'suppression de l\'avoir' => [static fn (Avoir $avoir): ?bool => $avoir->delete(), 'Un avoir émis ne se modifie plus. Suppression refusée.'],
            'modification d\'une ligne' => [static fn (Avoir $avoir): bool => $avoir->lignes->sole()->forceFill(['montant_ttc' => '1.00'])->save(), 'Les lignes d\'un avoir émis ne se modifient plus. Modification refusée : montant_ttc.'],
            'suppression d\'une ligne' => [static fn (Avoir $avoir): ?bool => $avoir->lignes->sole()->delete(), 'Les lignes d\'un avoir émis ne se modifient plus. Suppression refusée.'],
        ];
    }

    /**
     * @param  Closure(Avoir): mixed  $ecriture
     */
    #[DataProvider('ecrituresSurUnAvoirEmis')]
    public function test_un_avoir_emis_et_ses_lignes_ne_se_modifient_ni_ne_se_suppriment(Closure $ecriture, string $refus): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettre($client);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Article abîmé');
        $avant = [DB::table('avoirs')->get()->all(), DB::table('lignes_avoir')->get()->all()];

        $this->assertThrows(fn () => $ecriture($avoir), DonneeFiscaleInalterableException::class, $refus);

        $this->assertEquals($avant, [DB::table('avoirs')->get()->all(), DB::table('lignes_avoir')->get()->all()]);
    }

    /**
     * Une réécriture en base d'une facture émise, par une requête qui ne
     * passe pas par le modèle.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function reecrituresEnBaseDUneFactureEmise(): array
    {
        return [
            'total' => ['total_ttc', '1.00'],
            'notes' => ['notes', 'Réécrite après coup'],
            'nom du client figé' => ['client_nom', 'Autre nom'],
            'nom figé de l\'auteur' => ['cree_par_nom', 'Quelqu\'un d\'autre'],
            'numéro' => ['numero', 'TK-LYON1-2026-999999'],
            'date d\'émission' => ['date_emission', self::enUtc('2020-01-01 00:00:00')],
        ];
    }

    /**
     * La base refuse elle-même de réécrire une facture émise, quel que soit
     * le code qui écrit (voir GardesDesDocumentsEmis) : la ligne relue est
     * identique.
     */
    #[DataProvider('reecrituresEnBaseDUneFactureEmise')]
    public function test_la_base_refuse_de_reecrire_une_facture_emise(string $colonne, mixed $valeur): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $avant = $this->ligneEnBase('factures', $facture->id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $facture->id)->update([$colonne => $valeur])), QueryException::class, GardesDesDocumentsEmis::refusDeModification('factures'));

        $this->assertSame($avant, $this->ligneEnBase('factures', $facture->id));
    }

    /**
     * Une facture émise, ni payée ni créditée (aucune clé ne la retient),
     * ne se supprime pas, ni ses lignes ; ses lignes ne se réécrivent pas
     * et aucune ne s'y ajoute.
     */
    public function test_la_base_refuse_de_supprimer_une_facture_emise_ou_de_toucher_a_ses_lignes(): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $ligne = $facture->lignes->sole();
        $avant = $this->instantaneDesTables('factures', 'lignes_facture');
        $refusSurUneLigne = GardesDesDocumentsEmis::refusSurUneLigne('lignes_facture');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $facture->id)->delete()), QueryException::class, GardesDesDocumentsEmis::refusDeSuppression('factures'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_facture')->where('id', $ligne->id)->delete()), QueryException::class, $refusSurUneLigne);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_facture')->where('id', $ligne->id)->update(['montant_ttc' => '600.00'])), QueryException::class, $refusSurUneLigne);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_facture')->where('id', $ligne->id)->update(['quantite' => 30])), QueryException::class, $refusSurUneLigne);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_facture')->insert(Arr::except((array) DB::table('lignes_facture')->where('id', $ligne->id)->first(), ['id']))), QueryException::class, $refusSurUneLigne);

        $this->assertSame($avant, $this->instantaneDesTables('factures', 'lignes_facture'));
    }

    /**
     * Un avoir émis dont le crédit n'est imputé sur rien (aucune clé ne le
     * retient) ne se supprime pas et ne se réécrit pas, ni ses lignes.
     */
    public function test_la_base_refuse_de_supprimer_ou_de_reecrire_un_avoir_emis_et_ses_lignes(): void
    {
        $client = Client::factory()->create();
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 2]]);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Article abîmé');
        $this->assertSame(0, DB::table('avoir_allocations')->where('avoir_id', $avoir->id)->count());
        $ligne = $avoir->lignes->sole();
        $avant = $this->instantaneDesTables('avoirs', 'lignes_avoir');
        $refusSurUneLigne = GardesDesDocumentsEmis::refusSurUneLigne('lignes_avoir');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('avoirs')->where('id', $avoir->id)->delete()), QueryException::class, GardesDesDocumentsEmis::refusDeSuppression('avoirs'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('avoirs')->where('id', $avoir->id)->update(['motif' => 'Réécrit après coup'])), QueryException::class, GardesDesDocumentsEmis::refusDeModification('avoirs'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_avoir')->where('id', $ligne->id)->update(['montant_ttc' => '1.00'])), QueryException::class, $refusSurUneLigne);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_avoir')->where('id', $ligne->id)->delete()), QueryException::class, $refusSurUneLigne);

        $this->assertSame($avant, $this->instantaneDesTables('avoirs', 'lignes_avoir'));
    }

    /**
     * Ce qui suit encore un document émis s'écrit toujours en base : son
     * statut de règlement, par une requête en masse comme celle des
     * factures en retard ; un brouillon, sans numéro, se réécrit et se
     * supprime avec ses lignes.
     */
    public function test_la_base_laisse_evoluer_le_statut_d_un_document_emis_et_ecrire_un_brouillon(): void
    {
        $facture = $this->emettre(Client::factory()->professionnel()->create());
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        DB::table('factures')->whereIn('id', [$facture->id])->update(['statut' => StatutFacture::EnRetard->value, 'updated_at' => self::enUtc('2026-10-09 09:00:00')]);
        DB::table('factures')->where('id', $brouillon->id)->update(['notes' => 'Réécrite avant l\'émission']);
        DB::table('lignes_facture')->where('facture_id', $brouillon->id)->update(['quantite' => 2]);
        DB::table('factures')->where('id', $brouillon->id)->delete();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()?->statut);
        $this->assertModelMissing($brouillon);
        $this->assertSame(0, DB::table('lignes_facture')->where('facture_id', $brouillon->id)->count());
    }

    /**
     * Une vente le 09/10 à 10 h, puis la clôture de sa journée le
     * lendemain à 8 h : son ticket Z.
     */
    private function cloturerLaJourneeDu9Octobre(): ClotureCaisse
    {
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 2]]);
        $this->travelTo(self::aParis('2026-10-10 08:00:00'));

        return app(CloturerCaisseAction::class)->handle(self::auteur(), app(TenantContext::class)->magasin(), '2026-10-09');
    }

    /**
     * Une réécriture en base de chaque colonne d'un ticket Z, la valeur se
     * fabriquant au moment du test (un autre magasin).
     *
     * @return array<string, array{0: string, 1: Closure(): mixed}>
     */
    public static function reecrituresDUnTicketZ(): array
    {
        return [
            'total HT' => ['total_ht', static fn (): string => '1.00'],
            'total de TVA' => ['total_tva', static fn (): string => '0.00'],
            'total TTC' => ['total_ttc', static fn (): string => '1.00'],
            'récapitulatif' => ['recapitulatif', static fn (): string => json_encode(RecapitulatifDeCloture::vide(), JSON_THROW_ON_ERROR)],
            'numéro' => ['numero', static fn (): string => 'Z-LYON1-2026-999999'],
            'instant de la clôture' => ['cloturee_a', static fn (): string => self::enUtc('2026-10-11 06:00:00')->format('Y-m-d H:i:s')],
            'journée' => ['date_cloture', static fn (): string => '2026-10-08'],
            'nombre de ventes' => ['nombre_ventes', static fn (): int => 0],
            'nombre d\'avoirs' => ['nombre_avoirs', static fn (): int => 3],
            'dernière séquence des ventes' => ['derniere_sequence_vente', static fn (): int => 99],
            'dernière séquence des encaissements' => ['derniere_sequence_encaissement', static fn (): int => 99],
            'magasin' => ['magasin_id', static fn (): int => Magasin::factory()->create()->id],
            'nom figé de l\'auteur' => ['cree_par_nom', static fn (): string => 'Quelqu\'un d\'autre'],
        ];
    }

    /**
     * C24 : un ticket Z fait ne se réécrit, ne se supprime ni ne se vide
     * par une requête directe : la base le refuse (voir
     * DeclencheursDeProtection::ajoutSeul()), et la ligne relue est
     * identique. Le contrôle différé du ticket Z (voir GardesDesClotures)
     * est joué avant le vidage, comme à la validation de la transaction de
     * la clôture : PostgreSQL ne vide pas une table qui attend encore un
     * contrôle différé, que la transaction du test n'atteint jamais.
     *
     * @param  Closure(): mixed  $valeur
     */
    #[DataProvider('reecrituresDUnTicketZ')]
    public function test_la_base_refuse_de_modifier_ou_de_supprimer_une_cloture_faite(string $colonne, Closure $valeur): void
    {
        $cloture = $this->cloturerLaJourneeDu9Octobre();
        $nouvelleValeur = $valeur();
        $avant = $this->ligneEnBase('clotures_caisse', $cloture->id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clotures_caisse')->where('id', $cloture->id)->update([$colonne => $nouvelleValeur])), QueryException::class, 'clotures_caisse : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clotures_caisse')->where('id', $cloture->id)->delete()), QueryException::class, 'clotures_caisse : table en ajout seul, suppression refusée');
        DB::statement('SET CONSTRAINTS '.GardesDesClotures::DECLENCHEUR.' IMMEDIATE');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clotures_caisse')->truncate()), QueryException::class, 'clotures_caisse : table en ajout seul, vidage refusé');

        $this->assertSame($avant, $this->ligneEnBase('clotures_caisse', $cloture->id));
    }

    /**
     * C25 : un ticket ou un avoir rattaché à son ticket Z n'en sort plus,
     * ni vers aucun, ni vers un autre ; un ticket encore ouvert ne se
     * rattache qu'au dernier ticket Z de son magasin, celui que la clôture
     * vient d'écrire, jamais à un Z antérieur ; et jamais au ticket Z d'un
     * magasin d'une autre société, que sa clé composée refuse aussi.
     */
    public function test_la_base_refuse_qu_un_document_sorte_de_son_ticket_z(): void
    {
        $magasin = app(TenantContext::class)->magasin();
        $client = Client::factory()->create();
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 2]]);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Article abîmé');
        $this->travelTo(self::aParis('2026-10-10 08:00:00'));
        $zDu9 = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-09');
        $this->travelTo(self::aParis('2026-10-11 08:00:00'));
        $zDu10 = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-10');
        $this->travelTo(self::aParis('2026-10-11 10:00:00'));
        $ticketOuvert = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $zDAilleurs = $this->insererUnFauxTicketZ(Magasin::factory()->create(), '2026-10-10', 'Z-AILL-2026-000001', self::aParis('2026-10-11 00:00:30'));
        $this->assertSame([$zDu9->id, $zDu9->id, null], [$ticket->fresh()?->cloture_caisse_id, $avoir->fresh()?->cloture_caisse_id, $ticketOuvert->fresh()?->cloture_caisse_id]);
        $avant = $this->instantaneDesTables('factures', 'avoirs');

        foreach ([['factures', $ticket->id], ['avoirs', $avoir->id]] as [$table, $id]) {
            foreach ([null, $zDu10->id] as $autreTicketZ) {
                $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->where('id', $id)->update(['cloture_caisse_id' => $autreTicketZ])), QueryException::class, "{$table}.cloture_caisse_id : valeur figée, changement refusé");
            }
        }

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $ticketOuvert->id)->update(['cloture_caisse_id' => $zDu9->id])), QueryException::class, 'factures.cloture_caisse_id : valeur refusée sur cette ligne');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $ticketOuvert->id)->update(['cloture_caisse_id' => $zDAilleurs])), QueryException::class, 'factures.cloture_caisse_id : valeur refusée sur cette ligne');
        $this->assertSame($avant, $this->instantaneDesTables('factures', 'avoirs'));

        $this->retirerLeDeclencheur('factures', 'factures_cloture_caisse_id_figee');
        $this->assertRefuseeParLaCleDuMagasin(fn () => DB::table('factures')->where('id', $ticketOuvert->id)->update(['cloture_caisse_id' => $zDAilleurs]), 'factures', 'cloture_caisse_id');
    }

    /**
     * C26 : un ticket Z inséré directement, sans l'événement 50 que la
     * clôture inscrit dans sa transaction, est refusé à la validation de
     * la transaction (déclencheur de contrainte différé) ; le test force
     * ce contrôle (SET CONSTRAINTS … IMMEDIATE), que sa transaction, jamais
     * validée, n'atteindrait pas. Après une vraie clôture, le même contrôle
     * passe.
     */
    public function test_la_base_refuse_un_ticket_z_sans_sa_cloture_signee(): void
    {
        $magasin = app(TenantContext::class)->magasin();
        $verifierMaintenant = fn () => DB::statement('SET CONSTRAINTS clotures_caisse_signee IMMEDIATE');

        $this->assertThrows($this->dansUnPointDeSauvegarde(function () use ($magasin, $verifierMaintenant): void {
            $this->insererUnFauxTicketZ($magasin, '2026-10-09', 'Z-LYON1-2026-000001', self::aParis('2026-10-09 21:00:00'));
            $verifierMaintenant();
        }), QueryException::class, GardesDesClotures::REFUS_SANS_CLOTURE_SIGNEE);

        $this->cloturerLaJourneeDu9Octobre();

        $this->assertTrue($verifierMaintenant());
        $this->assertSame(1, ClotureCaisse::query()->count());
    }

    /**
     * Le statut de règlement d'une facture émise suit encore ses paiements
     * et son échéance : partiellement payée, en retard (par la commande
     * planifiée, en masse), puis payée.
     */
    public function test_le_statut_de_reglement_d_une_facture_emise_evolue_encore(): void
    {
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $client = Client::factory()->professionnel()->create(['delai_paiement_jours' => 0]);
        $facture = $this->emettre($client);
        $payer = fn (string $montant): Paiement => app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => $montant, 'recu_le' => Calendrier::deParis()->aujourdhui()],
            [['facture_id' => $facture->id, 'montant' => $montant]],
        );

        $payer('20.00');
        $partiellementPayee = $facture->fresh()->statut;
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $enRetard = $facture->fresh()->statut;
        $payer('40.00');

        $this->assertSame([StatutFacture::PartiellementPayee, StatutFacture::EnRetard, StatutFacture::Payee], [$partiellementPayee, $enRetard, $facture->fresh()->statut]);
    }

    /**
     * Un brouillon, sans numéro ni existence fiscale, se modifie puis se
     * supprime librement : rien n'entre dans la chaîne des ventes. Seuls
     * le produit qu'il perd en changeant de produit (ligne retirée) puis
     * l'abandon de la vente s'inscrivent au journal des événements (après
     * l'initialisation du registre).
     */
    public function test_un_brouillon_se_modifie_et_se_supprime(): void
    {
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $autreClient = Client::factory()->create();

        $modifie = app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $autreClient, [['produit_id' => $this->produit()->id, 'quantite' => 4]], ['notes' => 'Quatre articles']);
        $modifie->delete();

        $this->assertSame([$autreClient->id, 'Quatre articles', 4], [$modifie->client_id, $modifie->notes, $modifie->lignes->sole()->quantite]);
        $this->assertModelMissing($brouillon);
        $this->assertDatabaseCount('lignes_facture', 0);
        $this->assertSame(
            [['evenement', '260'], ['evenement', '323'], ['evenement', '320']],
            EnregistrementFiscal::query()->orderBy('id')->get()->map(fn (EnregistrementFiscal $enregistrement): array => [$enregistrement->chaine->value, $enregistrement->type])->all(),
        );
    }

    /**
     * Une facture de 60 € réglée par un paiement de 20 € et corrigée par
     * un avoir de 20 € imputé sur elle : un paiement, une imputation de
     * paiement, une imputation d'avoir.
     */
    private function reglerEtCorrigerUneFacture(): void
    {
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client);
        app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => Calendrier::deParis()->aujourdhui()],
            [['facture_id' => $facture->id, 'montant' => '20.00']],
        );
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);
    }

    /**
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string, 3: string}>
     */
    public static function donneesEnAjoutSeul(): array
    {
        return [
            'paiement' => [Paiement::class, 'paiements', 'montant', 'Un paiement enregistré ne se modifie ni ne se supprime.'],
            'imputation d\'un paiement' => [PaiementAllocation::class, 'paiement_allocations', 'montant_alloue', 'Ce qu\'un paiement règle d\'une facture ne se modifie ni ne se supprime.'],
            'imputation d\'un avoir' => [AvoirAllocation::class, 'avoir_allocations', 'montant_alloue', 'Ce qu\'un avoir crédite d\'une facture ne se modifie ni ne se supprime.'],
        ];
    }

    /**
     * @param  class-string<Model>  $modele
     */
    #[DataProvider('donneesEnAjoutSeul')]
    public function test_le_modele_refuse_de_modifier_ou_de_supprimer_un_paiement_ou_une_imputation(string $modele, string $table, string $montant, string $regle): void
    {
        $this->reglerEtCorrigerUneFacture();
        $donnee = $modele::query()->sole();
        $avant = $this->ligneEnBase($table, $donnee->id);

        $this->assertThrows(fn () => $donnee->forceFill([$montant => '1.00'])->save(), DonneeFiscaleInalterableException::class, "{$regle} Modification refusée : {$montant}.");
        $this->assertThrows(fn () => $modele::query()->sole()->delete(), DonneeFiscaleInalterableException::class, "{$regle} Suppression refusée.");

        $this->assertSame($avant, $this->ligneEnBase($table, $donnee->id));
    }

    /**
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    public static function tablesEnAjoutSeul(): array
    {
        return array_map(static fn (array $donnees): array => array_slice($donnees, 0, 3), self::donneesEnAjoutSeul());
    }

    /**
     * @param  class-string<Model>  $modele
     */
    #[DataProvider('tablesEnAjoutSeul')]
    public function test_la_base_refuse_de_modifier_ou_de_supprimer_un_paiement_ou_une_imputation(string $modele, string $table, string $montant): void
    {
        $this->reglerEtCorrigerUneFacture();
        $id = $modele::query()->sole()->id;
        $avant = $this->ligneEnBase($table, $id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->where('id', $id)->update([$montant => '1.00'])), QueryException::class, "{$table} : table en ajout seul, modification refusée");
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->where('id', $id)->delete()), QueryException::class, "{$table} : table en ajout seul, suppression refusée");

        $this->assertSame($avant, $this->ligneEnBase($table, $id));
    }

    /**
     * Aucune donnée ne disparaît avec son magasin ni avec un compte : toute
     * clé vers les magasins et toute clé vers les comptes refuse la
     * suppression (restrict) au lieu de la propager (cascade) ou d'effacer
     * la référence (set null). Un magasin ne se supprime jamais (voir le
     * déclencheur de sa table), aucun code ne supprime un compte, et le nom
     * de qui a fait un geste ne se perd pas. Elle se lit dans le catalogue,
     * pour toutes les tables, présentes et futures : supprimer un magasin
     * qui a émis une facture échoue de toute façon par plusieurs clés à la
     * fois et par les déclencheurs ajout seul, et l'échec ne dirait pas que
     * chacune de ces clés refuse.
     */
    public function test_aucune_cle_ne_propage_ni_n_efface_la_suppression_d_un_magasin_ou_d_un_compte(): void
    {
        $cles = DB::select(<<<'SQL'
            select s.relname as table_source, c.conname as nom, c.confdeltype as suppression, t.relname as cible
              from pg_constraint c
              join pg_class s on s.oid = c.conrelid
              join pg_class t on t.oid = c.confrelid
             where c.contype = 'f'
               and c.connamespace = 'public'::regnamespace
               and t.relname in ('magasins', 'users')
             order by s.relname, c.conname
            SQL);
        $permissives = array_values(array_map(
            static fn (object $cle): string => "{$cle->table_source} → {$cle->cible} ({$cle->nom}, {$cle->suppression})",
            array_filter($cles, static fn (object $cle): bool => $cle->suppression !== 'r'),
        ));

        $this->assertGreaterThan(90, count($cles), 'Le catalogue doit décrire les clés vers les magasins et les comptes.');
        $this->assertSame([], $permissives, 'Ces clés propagent ou effacent la suppression d\'un magasin ou d\'un compte.');
    }

    /**
     * @return array<string, array{0: Closure(Facture): Model}>
     */
    public static function fichesReferencees(): array
    {
        return [
            'magasin' => [static fn (Facture $facture): Model => $facture->magasin],
            'auteur de la facture' => [static fn (Facture $facture): Model => User::query()->findOrFail($facture->cree_par)],
            'produit d\'une ligne' => [static fn (Facture $facture): Model => $facture->load('lignes.produit')->lignes->firstWhere('produit_id', '!=', null)->produit],
            'service d\'une ligne' => [static fn (Facture $facture): Model => $facture->load('lignes.service')->lignes->firstWhere('service_id', '!=', null)->service],
        ];
    }

    /**
     * @param  Closure(Facture): Model  $fiche
     */
    #[DataProvider('fichesReferencees')]
    public function test_une_fiche_que_reference_une_facture_emise_ne_se_supprime_pas_definitivement(Closure $fiche): void
    {
        $magasin = app(TenantContext::class)->magasin();
        // À son prix de référence : un vendeur ne fixe pas le prix d'un
        // service (R2).
        $service = Service::factory()->auPrixDe('30.00', '20')->create();
        $brouillon = app(CreerFactureAction::class)->handle($this->membre($magasin, RoleMagasin::Vendeur), Client::factory()->create(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
            ['service_id' => $service->id, 'quantite' => 1, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20],
        ]);
        // Émise par un responsable : seul cree_par référence l'auteur.
        $facture = app(EmettreFactureAction::class)->handle($this->responsableDeTest($magasin), $brouillon);
        $referencee = $fiche($facture);

        // La base refuse d'elle-même, quel que soit le code qui le tente : la
        // requête passe outre les garde-fous des modèles (un magasin, lui,
        // ne se supprime jamais définitivement, voir le déclencheur de sa
        // table).
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $referencee->newQueryWithoutScopes()->whereKey($referencee->getKey())->delete()), QueryException::class);

        $this->assertModelExists($referencee);
        $this->assertSame($facture->numero, $facture->fresh()->numero);
    }
}
