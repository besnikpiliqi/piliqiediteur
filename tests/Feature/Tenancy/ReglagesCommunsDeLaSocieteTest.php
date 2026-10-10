<?php

namespace Tests\Feature\Tenancy;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\ReglagesCommunsDifferentsException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les réglages communs aux magasins d'une même société (J2, K5) : le
 * magasin principal du SIREN les fixe seul, un autre magasin du SIREN
 * n'enregistre que ses valeurs. Le refus d'émettre d'un magasin qui n'est
 * plus aligné se vérifie dans EmettreFactureActionTest, la commande de
 * l'éditeur qui désigne un autre principal dans
 * DesignerMagasinPrincipalTest.
 */
class ReglagesCommunsDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le message dit chaque réglage qui diffère avec la valeur du
     * principal, sans rien dire d'autre du magasin principal (ni son nom,
     * ni son adresse e-mail) ni répéter la valeur refusée (« Franchise »).
     */
    public function test_un_magasin_qui_prend_le_siren_d_une_societe_doit_avoir_tous_les_reglages_de_son_principal(): void
    {
        $this->magasinPrincipal();
        $magasin = Magasin::factory()->sansIdentiteLegale()->create(['regime_tva' => RegimeTva::FranchiseEnBase]);

        $refus = $this->refusDe(fn () => $magasin->update(['siren' => '732829320', 'siret' => '73282932000082']));

        $this->assertSame('Ces réglages sont communs à tous les magasins de votre société (SIREN 732829320) et fixés par son magasin principal ; à prendre : régime de TVA « Réel normal (déclaration mensuelle) », n° de TVA intracommunautaire « FR44732829320 », raison sociale « Dupont SARL », forme juridique « SARL », capital social « 10 000,00 € », immatriculation « RCS Lyon ».', $refus->getMessage());
        $this->assertStringNotContainsString('Bellecour', $refus->getMessage());
        $this->assertStringNotContainsString('bellecour@dupont.fr', $refus->getMessage());
        $this->assertStringNotContainsString('Franchise', $refus->getMessage());
        $this->assertSame([null, null], [$magasin->fresh()->siren, $magasin->fresh()->siret]);
    }

    public function test_un_magasin_qui_a_les_reglages_du_principal_prend_le_siren_de_la_societe(): void
    {
        $principal = $this->magasinPrincipal();
        $magasin = Magasin::factory()->sansIdentiteLegale()->create($principal->only(array_keys(ReglagesCommunsDeLaSociete::CHAMPS)));

        $magasin->update(['siren' => '732829320', 'siret' => '73282932000082']);

        $this->assertSame('732829320', $magasin->fresh()->siren);
        $this->assertSame([], app(ReglagesCommunsDeLaSociete::class)->ecarts($magasin->fresh()));
    }

    /**
     * Le principal change seul un réglage commun, même quand d'autres
     * magasins portent son SIREN ; un autre magasin, lui, ne prend pas
     * une autre valeur que la sienne, et rien n'est inscrit à son journal
     * des événements.
     */
    public function test_seul_le_magasin_principal_change_un_reglage_commun(): void
    {
        $principal = $this->magasinPrincipal();
        $autre = Magasin::factory()->deLaSocieteDe($principal)->create();

        $refus = $this->refusDe(fn () => $this->changerSonRegime($autre, RegimeTva::ReelNormalTrimestriel));
        $this->changerSonRegime($principal, RegimeTva::ReelNormalTrimestriel);

        $this->assertSame('Ces réglages sont communs à tous les magasins de votre société (SIREN 732829320) et fixés par son magasin principal ; à prendre : régime de TVA « Réel normal (déclaration mensuelle) ».', $refus->getMessage());
        $this->assertSame(RegimeTva::ReelNormalMensuel, $autre->fresh()->regime_tva);
        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $principal->fresh()->regime_tva);
        $this->assertSame(0, EnregistrementFiscal::query()->withoutGlobalScopes()->where('magasin_id', $autre->id)->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->count());
    }

    /**
     * Laissé derrière par son principal, un magasin enregistre encore ses
     * autres paramètres, et prend la nouvelle valeur du principal.
     */
    public function test_un_magasin_que_le_principal_a_laisse_derriere_lui_enregistre_ses_autres_parametres_et_s_aligne(): void
    {
        $principal = $this->magasinPrincipal();
        $autre = Magasin::factory()->deLaSocieteDe($principal)->create();
        $this->changerSonRegime($principal, RegimeTva::ReelNormalTrimestriel);

        $autre->update(['delai_paiement_jours' => 45]);
        $this->changerSonRegime($autre, RegimeTva::ReelNormalTrimestriel);

        $this->assertSame([45, RegimeTva::ReelNormalTrimestriel], [$autre->fresh()->delai_paiement_jours, $autre->fresh()->regime_tva]);
        $this->assertSame([], app(ReglagesCommunsDeLaSociete::class)->ecarts($autre->fresh()));
    }

    /**
     * Le principal est le magasin qui porte le SIREN depuis le plus
     * longtemps, pas le premier inscrit : un magasin plus ancien qui prend
     * ensuite le SIREN d'une société ne fixe pas ses réglages à la place
     * de son principal.
     */
    public function test_le_principal_est_le_magasin_qui_porte_le_siren_depuis_le_plus_longtemps(): void
    {
        $inscritLePremier = Magasin::factory()->sansIdentiteLegale()->create(['regime_tva' => RegimeTva::ReelNormalMensuel]);
        $this->travel(1)->day();
        $principal = $this->magasinPrincipal();
        $this->travel(1)->day();
        $inscritLePremier->update([...$principal->only(array_keys(ReglagesCommunsDeLaSociete::CHAMPS)), 'siren' => '732829320', 'siret' => '73282932000082']);

        $refus = $this->refusDe(fn () => $this->changerSonRegime($inscritLePremier, RegimeTva::FranchiseEnBase));
        $this->changerSonRegime($principal, RegimeTva::ReelNormalTrimestriel);

        $this->assertSame('Ces réglages sont communs à tous les magasins de votre société (SIREN 732829320) et fixés par son magasin principal ; à prendre : régime de TVA « Réel normal (déclaration mensuelle) ».', $refus->getMessage());
        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $principal->fresh()->regime_tva);
    }

    /**
     * Un magasin supprimé ne compte plus : le magasin qui portait le SIREN
     * après lui devient le principal.
     */
    public function test_le_magasin_suivant_devient_principal_quand_le_principal_est_supprime(): void
    {
        $principal = $this->magasinPrincipal();
        $this->travel(1)->day();
        $suivant = Magasin::factory()->deLaSocieteDe($principal)->create();

        $principal->delete();
        $this->changerSonRegime($suivant, RegimeTva::ReelNormalTrimestriel);

        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $suivant->fresh()->regime_tva);
    }

    /**
     * Fermé, le principal reste le magasin le plus ancien du SIREN : il
     * fixe toujours les réglages communs, jusqu'à ce que l'éditeur en
     * désigne un autre (voir DesignerMagasinPrincipalTest).
     */
    public function test_un_principal_ferme_reste_le_principal(): void
    {
        $principal = $this->magasinPrincipal();
        $autre = Magasin::factory()->deLaSocieteDe($principal)->create();
        $principal->forceFill(['statut' => StatutMagasin::Ferme])->save();

        $refus = $this->refusDe(fn () => $this->changerSonRegime($autre, RegimeTva::ReelNormalTrimestriel));

        $this->assertSame('Ces réglages sont communs à tous les magasins de votre société (SIREN 732829320) et fixés par son magasin principal ; à prendre : régime de TVA « Réel normal (déclaration mensuelle) ».', $refus->getMessage());
        $this->assertSame(RegimeTva::ReelNormalMensuel, $autre->fresh()->regime_tva);
    }

    /**
     * Un magasin d'une autre société, même s'il a d'autres réglages, ne
     * compte pas : le magasin seul de son SIREN en est le principal.
     */
    public function test_un_magasin_d_un_autre_siren_ne_compte_pas(): void
    {
        $this->magasinPrincipal();
        $seulDeSaSociete = Magasin::factory()->create(['siren' => '552100554', 'siret' => '55210055400013', 'regime_tva' => RegimeTva::ReelNormalMensuel]);

        $this->changerSonRegime($seulDeSaSociete, RegimeTva::FranchiseEnBase);

        $this->assertSame(RegimeTva::FranchiseEnBase, $seulDeSaSociete->fresh()->regime_tva);
    }

    /**
     * Les textes se comparent sans la casse ni les espaces doublés, le n°
     * de TVA sans ses séparateurs, le capital au centime.
     */
    public function test_les_valeurs_se_comparent_sans_la_casse_les_espaces_ni_les_separateurs(): void
    {
        $principal = $this->magasinPrincipal(['raison_sociale' => 'SARL  Dupont']);

        $autre = Magasin::factory()->create([
            ...$principal->only(array_keys(ReglagesCommunsDeLaSociete::CHAMPS)),
            'raison_sociale' => ' sarl dupont ',
            'forme_juridique' => 'sarl',
            'immatriculation' => 'rcs  lyon',
            'numero_tva' => 'fr 44 732 829 320',
            'capital_social' => '10000',
            'siren' => '732829320',
            'siret' => '73282932000082',
        ]);

        $this->assertSame([], app(ReglagesCommunsDeLaSociete::class)->ecarts($autre));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function reglagesDifferents(): array
    {
        return [
            'capital de 20 000 € contre 10 000 €' => [[], ['capital_social' => 20000], 'capital social « 10 000,00 € »'],
            'option pour les débits activée' => [[], ['option_debits_tva' => true], 'option pour les débits « désactivée »'],
            'capital que le principal n\'a pas' => [['capital_social' => null], ['capital_social' => 5000], 'capital social vide'],
        ];
    }

    /**
     * @param  array<string, mixed>  $reglagesDuPrincipal
     * @param  array<string, mixed>  $reglage
     */
    #[DataProvider('reglagesDifferents')]
    public function test_un_reglage_different_du_principal_est_refuse(array $reglagesDuPrincipal, array $reglage, string $valeurAPrendre): void
    {
        $principal = $this->magasinPrincipal($reglagesDuPrincipal);
        $autre = Magasin::factory()->deLaSocieteDe($principal)->create();

        $refus = $this->refusDe(fn () => app(TenantContext::class)->runAs($autre, fn () => $autre->update($reglage)));

        $this->assertSame("Ces réglages sont communs à tous les magasins de votre société (SIREN 732829320) et fixés par son magasin principal ; à prendre : {$valeurAPrendre}.", $refus->getMessage());
    }

    /**
     * Seule lecture entre magasins de ces réglages (J2) : filtrée par
     * SIREN, elle ne lit que les réglages communs et la désignation du
     * principal, jamais le nom ni les coordonnées d'un autre magasin.
     */
    public function test_la_lecture_des_autres_magasins_se_limite_aux_reglages_communs_de_leur_siren(): void
    {
        $principal = $this->magasinPrincipal();
        $autre = Magasin::factory()->deLaSocieteDe($principal)->create();
        DB::enableQueryLog();

        app(ReglagesCommunsDeLaSociete::class)->ecarts($autre);

        $requete = collect(DB::getQueryLog())->sole();
        preg_match('/^select (.+) from \W?magasins\W? where (.+)$/', $requete['query'], $parties);
        $this->assertSame(
            ['id', 'designe_principal', 'regime_tva', 'option_debits_tva', 'numero_tva', 'raison_sociale', 'forme_juridique', 'capital_social', 'immatriculation'],
            array_map(fn (string $colonne): string => trim($colonne, ' "`'), explode(',', $parties[1])),
        );
        $this->assertStringStartsWith('"siren" = ?', $parties[2]);
        $this->assertSame(['732829320'], $requete['bindings']);
    }

    /**
     * Le magasin principal de la société, SIREN 732829320, réel normal
     * mensuel, sans option pour les débits.
     *
     * @param  array<string, mixed>  $reglages
     */
    private function magasinPrincipal(array $reglages = []): Magasin
    {
        return Magasin::factory()->create([
            'nom' => 'Bellecour',
            'email' => 'bellecour@dupont.fr',
            'siren' => '732829320',
            'siret' => '73282932000074',
            'raison_sociale' => 'Dupont SARL',
            'forme_juridique' => 'SARL',
            'capital_social' => 10000,
            'immatriculation' => 'RCS Lyon',
            'numero_tva' => 'FR44732829320',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
            'option_debits_tva' => false,
            ...$reglages,
        ]);
    }

    /**
     * Le régime de TVA se change dans son propre magasin, dont le journal
     * des événements note le changement (voir
     * JournaliseSesParametresDeConformite).
     */
    private function changerSonRegime(Magasin $magasin, RegimeTva $regime): void
    {
        app(TenantContext::class)->runAs($magasin, fn () => $magasin->update(['regime_tva' => $regime]));
    }

    private function refusDe(callable $operation): ReglagesCommunsDifferentsException
    {
        try {
            $operation();
        } catch (ReglagesCommunsDifferentsException $refus) {
            return $refus;
        }

        $this->fail('Le réglage commun aurait dû être refusé.');
    }
}
