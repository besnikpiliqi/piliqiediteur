<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Actions\VerifierLeSeuilDuTrimestrielAction;
use App\Domain\Charges\Services\SeuilDuTrimestriel;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le seuil du réel normal trimestriel (lot TVA27, point 3 du cahier ; voir
 * SeuilDuTrimestriel) : une société au trimestriel dont le chiffre
 * d'affaires HT de l'année civile, dans PILIQI, tous ses magasins et
 * ventes nettes des avoirs, dépasse 1 100 000,00 € le lit une fois par
 * année dans l'« À faire » de l'espace de la société (son propriétaire, et
 * l'administrateur qui peut modifier ses réglages fiscaux), jusqu'à « C'est
 * vérifié », inscrit au journal d'audit ; 1 100 000,00 € tout juste
 * n'avertit pas, 1 100 000,01 € avertit ; une société au mensuel jamais.
 * Les ventes sont des factures émises de 1 000 armoires à 1 320,00 € TTC
 * à 20 % (1 100 000,00 € HT) et d'une gomme à 0,01 € TTC (0,01 € HT). Le
 * temps est figé au 5 novembre 2026, à Paris.
 */
class SeuilDuTrimestrielTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
    }

    /**
     * 1 100 000,00 € HT tout juste n'avertit pas ; un centime de plus, dans
     * un autre magasin, avertit pour 2026 ; l'avoir de ce centime ramène le
     * chiffre d'affaires net au seuil, sans avertissement.
     */
    public function test_le_seuil_tout_juste_n_avertit_pas_et_un_centime_de_plus_avertit(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelNormalTrimestriel]);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $societe = $lyon->societe()->firstOrFail();
        $this->vendre($lyon, 1000, '1320.00');

        $this->assertSame(110000000, app(SeuilDuTrimestriel::class)->chiffreDAffaires($societe, 2026));
        $this->assertNull(app(SeuilDuTrimestriel::class)->anneeASignaler($societe->fresh()));

        $gomme = $this->vendre($paris, 1, '0.01');

        $this->assertSame(110000001, app(SeuilDuTrimestriel::class)->chiffreDAffaires($societe, 2026));
        $this->assertSame(2026, app(SeuilDuTrimestriel::class)->anneeASignaler($societe->fresh()));

        app(TenantContext::class)->runAs($paris->fresh(), fn () => app(EnregistrerAvoirAction::class)->handle(self::responsableDeTest($paris), $gomme->client, [['ligne_facture_id' => $gomme->lignes->sole()->id, 'quantite' => 1]], 'Retour'));

        $this->assertSame(110000000, app(SeuilDuTrimestriel::class)->chiffreDAffaires($societe, 2026));
        $this->assertNull(app(SeuilDuTrimestriel::class)->anneeASignaler($societe->fresh()));
    }

    /**
     * Au réel normal mensuel, aucun avertissement, même au-delà du seuil ;
     * ni en franchise en base.
     */
    public function test_au_mensuel_ou_en_franchise_jamais_d_avertissement(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelNormalMensuel]);
        $societe = $lyon->societe()->firstOrFail();
        $this->vendre($lyon, 1000, '1320.00');
        $this->vendre($lyon, 1, '0.01');

        $this->assertNull(app(SeuilDuTrimestriel::class)->anneeASignaler($societe->fresh()));
        $this->assertNull($this->ligne($this->proprietaireDe($lyon), $lyon));
    }

    /**
     * La ligne de l'« À faire », en orange, avec sa phrase, sa précision,
     * « Ouvrir les réglages fiscaux » et la date du 1er janvier, pour le
     * propriétaire et l'administrateur qui gère les paramètres de la
     * société ; jamais pour un autre administrateur.
     */
    public function test_la_ligne_se_montre_au_proprietaire_et_a_l_administrateur_qui_regle_la_fiscalite(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelNormalTrimestriel]);
        $fiscal = $this->administrateur($lyon, autorisationsSociete: [AutorisationsSociete::PARAMETRES_GERER], nom: 'Admin fiscal');
        $sansLaFiscalite = $this->administrateur($lyon, nom: 'Admin des ventes');
        $this->vendre($lyon, 1000, '1320.00');
        $this->vendre($lyon, 1, '0.01');

        $ligne = $this->ligne($this->proprietaireDe($lyon), $lyon);

        $this->assertSame([
            'Votre chiffre d\'affaires de l\'année dépasse 1 100 000 € : la déclaration de TVA devient mensuelle. Vérifiez votre régime de TVA.',
            UrgenceAFaire::Orange,
            'Ouvrir les réglages fiscaux',
            ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $lyon->societe),
            '2026-01-01',
            SeuilDuTrimestriel::PRECISION,
        ], [$ligne?->phrase, $ligne?->urgence, $ligne?->bouton, $ligne?->url, $ligne?->date, $ligne?->precision]);
        $this->assertStringContainsString('PILIQI ne voit ni vos acquisitions taxables ni les ventes faites hors de PILIQI.', SeuilDuTrimestriel::PRECISION);
        $this->assertNotNull($this->ligne($fiscal, $lyon));
        $this->assertNull($this->ligne($sansLaFiscalite, $lyon));
    }

    /**
     * « C'est vérifié », depuis le tableau de bord de la société, inscrit
     * l'année au journal d'audit et retire la ligne pour l'année ; un
     * second geste est refusé ; l'année suivante, un nouveau dépassement
     * revient une fois. Un administrateur qui ne règle pas la fiscalité, ou
     * un compte d'un magasin, est refusé ; sans dépassement, rien à
     * vérifier.
     */
    public function test_c_est_verifie_s_inscrit_au_journal_d_audit_une_fois_par_annee(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => RegimeTva::ReelNormalTrimestriel]);
        $proprietaire = $this->proprietaireDe($lyon);
        $sansLaFiscalite = $this->administrateur($lyon, nom: 'Admin des ventes');
        $vendeur = $this->membre($lyon, RoleMagasin::Vendeur);
        $societe = $lyon->societe()->firstOrFail();

        $this->assertThrows(fn () => $this->verifier($proprietaire, $lyon), LogicException::class, VerifierLeSeuilDuTrimestrielAction::RIEN_A_VERIFIER);

        $this->vendre($lyon, 1000, '1320.00');
        $this->vendre($lyon, 1, '0.01');
        $this->assertThrows(fn () => $this->verifier($sansLaFiscalite, $lyon), AuthorizationException::class, VerifierLeSeuilDuTrimestrielAction::REFUS);
        $this->assertThrows(fn () => $this->verifier($vendeur, $lyon), AuthorizationException::class, VerifierLeSeuilDuTrimestrielAction::REFUS);

        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);
        Livewire::test(TableauDeBord::class)
            ->assertSee(SeuilDuTrimestriel::PHRASE)
            ->callAction('verifierLeSeuil')
            ->assertNotified('Régime de TVA vérifié pour cette année.');

        $entrees = $this->entreesDAudit($societe, ActionAuditee::SeuilDuTrimestrielVerifie);
        $this->assertCount(1, $entrees);
        $valeurs = $entrees[0]->nouvelles_valeurs ?? [];
        ksort($valeurs);
        $this->assertSame(['annee' => 2026, 'chiffre_d_affaires_ht' => 110000001, 'regime_tva' => 'reel_normal_trimestriel'], $valeurs);
        $this->assertNull($this->ligne($proprietaire, $lyon));
        $this->assertThrows(fn () => $this->verifier($proprietaire, $lyon), LogicException::class, VerifierLeSeuilDuTrimestrielAction::DEJA_VERIFIE);

        $this->travelTo(self::aParis('2027-02-10 10:00:00'));
        $this->assertNull($this->ligne($proprietaire, $lyon));
        $this->vendre($lyon, 1000, '1320.00');
        $this->vendre($lyon, 1, '0.01');

        $this->assertSame(2027, app(SeuilDuTrimestriel::class)->anneeASignaler($societe->fresh()));
        $this->assertSame('2027-01-01', $this->ligne($proprietaire, $lyon)?->date);
    }

    /**
     * Une facture émise dans ce magasin, de cette quantité d'un article à
     * ce prix TTC, à 20 %, par son responsable.
     */
    private function vendre(Magasin $magasin, int $quantite, string $prixTtc): Facture
    {
        return app(TenantContext::class)->runAs($magasin->fresh(), function () use ($magasin, $quantite, $prixTtc): Facture {
            $responsable = self::responsableDeTest($magasin);
            $article = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $article->id, 'quantite' => $quantite]]));
        });
    }

    private function verifier(User $compte, Magasin $magasin): void
    {
        app(TenantContext::class)->clear();
        app(VerifierLeSeuilDuTrimestrielAction::class)->handle($compte, $magasin->societe()->firstOrFail());
        app(TenantContext::class)->clear();
    }

    private function ligne(User $compte, Magasin $magasin): ?ChoseAFaire
    {
        app(TenantContext::class)->clear();
        $societe = $magasin->societe()->firstOrFail();
        $this->dansLEspaceDeLaSociete($societe, $compte);

        return collect(app(ChosesAFaireDeLaSociete::class)->delaSociete($compte, $societe))->firstWhere('cle', 'seuil_du_trimestriel');
    }
}
