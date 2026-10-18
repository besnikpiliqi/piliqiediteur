<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\ChoisirEtablissementPrincipalAction;
use App\Domain\Tenancy\Actions\CorrigerSirenAvantValidationAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\RefuserLaSocieteAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Events\MagasinFerme;
use App\Domain\Tenancy\Events\MagasinOuvert;
use App\Domain\Tenancy\Events\MagasinRouvert;
use App\Domain\Tenancy\Events\SocieteValidee;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le cycle de vie d'un magasin réel (voir FermerMagasinAction et
 * RouvrirMagasinAction) : la société le ferme, temporairement, pour
 * cessation ou pour cession, cède un magasin déjà fermé, et le rouvre,
 * avant comme après sa validation, avec ce que la caisse sécurisée en
 * attend (journées clôturées à la fermeture, aucun ticket Z pendant la
 * fermeture, même registre, mêmes séries et mêmes grands totaux à la
 * réouverture), le contrôle Sirene de la réouverture, la suspension de
 * l'éditeur qui reste, la sortie du stock d'un magasin cédé, qui ne se
 * rouvre jamais, et les événements des abonnements.
 */
class FermerEtRouvrirMagasinTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le mot de passe du propriétaire des sociétés des tests (voir
     * UserFactory).
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    public function test_la_fermeture_exige_l_autorisation_dans_la_portee_et_le_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $sansLAutorisation = $this->administrateur($magasin);
        $horsDeSaPortee = $this->administrateur($magasin, [$autre->id], [AutorisationsSociete::MAGASINS_GERER]);
        $autorise = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);

        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Temporaire, $sansLAutorisation, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Temporaire, $horsDeSaPortee, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Temporaire, $autorise, 'devine-au-hasard'), LogicException::class, MotDePasseRedemande::MESSAGE);
        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);

        $this->fermer($magasin, MotifFermeture::Temporaire, $autorise, self::MOT_DE_PASSE_CHOISI);

        $this->assertSame([StatutMagasin::Ferme, StatutMagasin::Actif], [$magasin->fresh()->statut, $autre->fresh()->statut]);
    }

    /**
     * Les journées en attente, jusqu'au jour même, se clôturent au nom du
     * système ; le changement de statut s'inscrit au journal des
     * événements du magasin (JET 270), avec son motif, au nom de qui le
     * ferme, le magasin et sa période d'activité gardent le motif, et la
     * fermeture s'inscrit au journal d'audit.
     */
    public function test_la_fermeture_cloture_les_journees_en_attente_et_s_inscrit_au_jet_270(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->vendre($magasin);
        $this->travelTo('2026-10-07 15:00:00');

        $this->fermer($magasin, MotifFermeture::Temporaire);

        app(TenantContext::class)->set($magasin);
        $this->assertSame(
            [['2026-10-05', 1, null], ['2026-10-06', 0, null], ['2026-10-07', 0, null]],
            ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->nombre_ventes, $cloture->cree_par])->all(),
        );
        $evenement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->orderByDesc('sequence')->first()->contenuSigne();
        $this->assertSame(
            [['parametre' => 'statut_magasin', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => 'actif', 'nouvelle_valeur' => 'ferme', 'motif' => 'temporaire'], $proprietaire->id],
            [$evenement['donnees']['details'], $evenement['operateur']['id']],
        );
        $this->assertSame(MotifFermeture::Temporaire, $magasin->fresh()->motif_fermeture);
        $periode = PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole();
        $this->assertSame(['2026-10-07 15:00:00', MotifFermeture::Temporaire, $proprietaire->id, $proprietaire->name], [$periode->fin->format('Y-m-d H:i:s'), $periode->motif_fin, (int) $periode->fermee_par, $periode->fermee_par_nom]);
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinFerme)[0];
        $this->assertSame([['statut' => 'actif'], ['motif' => 'temporaire', 'statut' => 'ferme'], $proprietaire->id], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, (int) $entree->auteur_id]);
    }

    public function test_un_magasin_ferme_passe_en_lecture_seule_et_ne_produit_plus_de_ticket_z(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendre($magasin);
        $this->fermer($magasin, MotifFermeture::Cessation);
        $this->travelTo('2026-10-09 01:00:00');

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $ferme = $magasin->fresh();
        $this->assertSame([true, 'Ce magasin est fermé'], [$ferme->estEnLectureSeule(), $ferme->motifDeLectureSeule()]);
        $this->assertThrows(fn () => $this->vendre($magasin), VenteNonAutoriseeException::class, 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.');
        app(TenantContext::class)->set($magasin);
        $this->assertSame(['2026-10-05'], ClotureCaisse::query()->pluck('date_cloture')->map->toDateString()->all());
        $this->assertSame(1, Facture::query()->count());
    }

    public function test_la_reouverture_reprend_le_meme_registre_les_memes_series_et_les_memes_grands_totaux(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->vendre($magasin);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->travelTo('2026-10-08 09:00:00');

        $this->rouvrir($magasin);

        $second = $this->vendre($magasin);
        $this->assertSame(['TK-MABOUT-2026-000001', 'TK-MABOUT-2026-000002'], [$premier->numero, $second->numero]);
        app(TenantContext::class)->set($magasin);
        $ventes = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->orderBy('sequence')->get()->map(fn (EnregistrementFiscal $vente): array => [$vente->sequence, $vente->contenuSigne()['gt']['perpetuel_reel']])->all();
        $this->assertSame([[1, 1200], [2, 2400]], $ventes);
        $evenements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get();
        $this->assertSame(1, $evenements->where('type', '260')->count());
        $this->assertSame(range(1, $evenements->count()), $evenements->pluck('sequence')->all());
        $this->assertSame(
            ['parametre' => 'statut_magasin', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => 'ferme', 'nouvelle_valeur' => 'actif'],
            $evenements->where('type', '270')->last()->contenuSigne()['donnees']['details'],
        );
        $this->assertSame(2, PeriodeActivite::query()->where('magasin_id', $magasin->id)->count());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_aucun_ticket_z_n_est_produit_pour_les_jours_de_fermeture(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendre($magasin);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->travelTo('2026-10-09 09:00:00');
        $this->rouvrir($magasin);
        $this->travelTo('2026-10-11 01:00:00');

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(
            [['2026-10-05', 'Z-MABOUT-2026-000001'], ['2026-10-09', 'Z-MABOUT-2026-000002'], ['2026-10-10', 'Z-MABOUT-2026-000003']],
            ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->numero])->all(),
        );
    }

    public function test_le_premier_ticket_z_apres_la_reouverture_est_celui_du_premier_jour_non_cloture(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendre($magasin);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->travelTo('2026-10-07 09:00:00');
        $this->rouvrir($magasin);
        $this->vendre($magasin);
        app(TenantContext::class)->set($magasin);

        $enAttente = app(CloturerLesJourneesAction::class)->journeesEnAttente(now());

        $this->assertSame(['2026-10-07'], $enAttente->all());
        $this->travelTo('2026-10-07 19:00:00');
        $cloture = app(CloturerLesJourneesAction::class)->parUnePersonne($this->proprietaireDe($magasin), $magasin, now())['clotures']->sole();
        $this->assertSame(['2026-10-07', 'Z-MABOUT-2026-000002', 1], [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->nombre_ventes]);
    }

    /**
     * La journée de la fermeture est déjà clôturée : rouvert le même jour,
     * le magasin ne vend qu'à partir du lendemain, et cette journée n'a
     * qu'un ticket Z.
     */
    public function test_fermer_et_rouvrir_le_meme_jour_ne_produit_qu_un_z_et_reprend_les_ventes_le_lendemain(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendre($magasin);
        $this->travelTo('2026-10-05 11:00:00');
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->travelTo('2026-10-05 12:00:00');

        $this->rouvrir($magasin);

        $this->assertThrows(fn () => $this->vendre($magasin), LogicException::class, 'La journée du 05/10/2026 est clôturée : les ventes reprennent demain.');
        app(TenantContext::class)->set($magasin);
        $this->assertSame([], app(CloturerLesJourneesAction::class)->journeesEnAttente(now())->all());
        $this->travelTo('2026-10-06 09:00:00');
        $this->assertSame('TK-MABOUT-2026-000002', $this->vendre($magasin)->numero);
        $this->assertSame(['2026-10-05'], ClotureCaisse::query()->pluck('date_cloture')->map->toDateString()->all());
    }

    /**
     * Le stock d'un produit suivi sort tout entier au coût moyen, son coût
     * moyen inchangé, le magasin cédé étant la source de chaque sortie ; la
     * valeur hors taxe (10 x 2,50 + 3 x 1,25 = 28,75 €) s'inscrit au
     * journal d'audit et va au propriétaire ; un produit en stock négatif
     * est seulement listé, un produit non suivi ne bouge pas.
     */
    public function test_une_fermeture_pour_cession_sort_le_stock_au_cout_moyen_et_remet_la_valeur_hors_taxe(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $carnet = $this->produitEnStock('CARNET', 'Carnet', 10, '2.50');
        $stylo = $this->produitEnStock('STYLO', 'Stylo', 3, '1.25');
        $gomme = $this->produitEnStock('GOMME', 'Gomme', -2, '0.80');
        $service = Produit::factory()->create(['reference' => 'LIVRAISON', 'suivi_stock' => false, 'cmup_ht' => 5]);

        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        app(TenantContext::class)->set($magasin);
        $this->assertSame(MotifFermeture::Cession, PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole()->motif_fin);
        $this->assertSame(
            [[$carnet->id, 10, '2.5000', 'magasin', $magasin->id, $proprietaire->id], [$stylo->id, 3, '1.2500', 'magasin', $magasin->id, $proprietaire->id]],
            MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->orderBy('produit_id')->get()
                ->map(fn (MouvementStock $sortie): array => [$sortie->produit_id, $sortie->quantite, $sortie->cout_unitaire_ht, $sortie->source_type, (int) $sortie->source_id, (int) $sortie->cree_par])->all(),
        );
        $this->assertSame([[0, '2.5000'], [0, '1.2500'], [-2, '0.8000'], [0, '5.0000']], array_map(fn (Produit $produit): array => [$produit->fresh()->stock_quantite, $produit->fresh()->cmup_ht], [$carnet, $stylo, $gomme, $service]));
        $this->assertSame(
            ['motif' => 'cession', 'produits_en_stock_negatif' => ["GOMME {$gomme->nom} (-2)"], 'produits_sortis' => 2, 'statut' => 'ferme', 'valeur_stock_sorti_ht' => '28.75'],
            $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinFerme)[0]->nouvelles_valeurs,
        );
        Notification::assertSentTo($proprietaire, StockDuMagasinCede::class, fn (StockDuMagasinCede $avis): bool => $avis->valeurHt === '28.75' && $avis->produitsEnStockNegatif === ["GOMME {$gomme->nom} (-2)"]);
        $this->assertStringContainsString('Valeur du stock au coût moyen : 28,75 € HT', (new StockDuMagasinCede($magasin, '28.75', []))->toDatabase($proprietaire)['body']);
        $this->assertTrue($magasin->fresh()->aEteCede());
    }

    public function test_seul_le_proprietaire_cede_un_magasin_en_retapant_son_nom(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());

        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, $administrateur, self::MOT_DE_PASSE_CHOISI, 'Ma Boutique'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma boutique'), LogicException::class, FermerMagasinAction::MESSAGE_CONFIRMATION);
        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);

        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $this->assertTrue($magasin->fresh()->aEteCede());
    }

    public function test_un_magasin_cede_ne_se_rouvre_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, RouvrirMagasinAction::MESSAGE_CEDE);

        $this->assertSame([StatutMagasin::Ferme, 1], [$magasin->fresh()->statut, PeriodeActivite::query()->where('magasin_id', $magasin->id)->count()]);
    }

    public function test_la_reouverture_d_un_siret_ferme_a_l_insee_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->repertoire()->fermer((string) $magasin->siret);

        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, ControleSirene::MOTIF_ETABLISSEMENT_FERME);

        $this->assertSame([StatutMagasin::Ferme, 1], [$magasin->fresh()->statut, PeriodeActivite::query()->where('magasin_id', $magasin->id)->count()]);
    }

    /**
     * L'INSEE ne répond pas : le magasin rouvre « établissement à
     * vérifier » et ne vend pas ; la reprise horaire du contrôle le rend
     * vendable dès que le répertoire confirme son établissement.
     */
    public function test_la_reouverture_attend_la_confirmation_sirene_si_l_insee_ne_repond_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->repertoire()->rendreInjoignable();

        $this->rouvrir($magasin);

        $rouvert = $magasin->fresh();
        $this->assertSame([StatutMagasin::Actif, null, false], [$rouvert->statut, $rouvert->etablissement_verifie_le, $rouvert->peutVendre()]);
        $this->assertThrows(fn () => $this->vendre($magasin), VenteNonAutoriseeException::class);
        $this->repertoire()->rendreInjoignable(false);
        app(TenantContext::class)->clear();
        $this->artisan('app:reverifier-sirene')->assertSuccessful();
        $this->assertTrue($magasin->fresh()->peutVendre());
        $this->assertSame('TK-MABOUT-2026-000001', $this->vendre($magasin)->numero);
    }

    public function test_une_reouverture_ne_leve_jamais_la_suspension_de_l_editeur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->fermer($magasin, MotifFermeture::Temporaire);

        $this->rouvrir($magasin);

        $rouvert = $magasin->fresh();
        $this->assertSame(StatutMagasin::Actif, $rouvert->statut);
        $this->assertNotNull($rouvert->suspendu_le);
        $this->assertSame([true, 'Ce magasin est suspendu'], [$rouvert->estEnLectureSeule(), $rouvert->motifDeLectureSeule()]);
        $this->assertThrows(fn () => $this->vendre($magasin), VenteNonAutoriseeException::class);
    }

    public function test_un_magasin_suspendu_peut_etre_ferme_et_reste_suspendu(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $suspendu = now()->subDay()->startOfSecond();
        $magasin->forceFill(['suspendu_le' => $suspendu])->save();

        $this->fermer($magasin, MotifFermeture::Cessation);

        $ferme = $magasin->fresh();
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Cessation, $suspendu->toDateTimeString()], [$ferme->statut, $ferme->motif_fermeture, $ferme->suspendu_le->toDateTimeString()]);
    }

    /**
     * L'abonnement d'un magasin suit ses périodes d'activité (étape 12) :
     * la validation émet SocieteValidee et MagasinOuvert pour chaque
     * magasin actif, la fermeture MagasinFerme et la réouverture
     * MagasinRouvert, chacun avec sa période ; un magasin fermé avant la
     * validation n'émet rien à sa fermeture, et MagasinOuvert à sa
     * réouverture, sa première période.
     */
    public function test_les_evenements_de_fermeture_et_de_reouverture_sont_emis_pour_les_abonnements(): void
    {
        Event::fake([SocieteValidee::class, MagasinOuvert::class, MagasinFerme::class, MagasinRouvert::class]);
        $societe = $this->inscrireUneSociete();
        $siege = $this->creerLeMagasinDuSiege($societe);
        $boutique = $this->creerLeMagasinDuSiege($societe, 'Boutique', substr(FauxRepertoireSirene::siretDeLaBoutique($societe->siren), 9));
        $proprietaire = $societe->proprietaire;
        $this->fermer($boutique, MotifFermeture::Temporaire, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        Event::assertNotDispatched(MagasinFerme::class);

        $this->verifierLIdentiteDuProprietaire($societe);
        $this->fermer($siege, MotifFermeture::Temporaire, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->rouvrir($siege, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->rouvrir($boutique, $proprietaire, self::MOT_DE_PASSE_CHOISI);

        Event::assertDispatchedTimes(SocieteValidee::class, 1);
        Event::assertDispatched(SocieteValidee::class, fn (SocieteValidee $evenement): bool => $evenement->societe->is($societe));
        [$premiere, $seconde] = PeriodeActivite::query()->where('magasin_id', $siege->id)->orderBy('id')->get()->all();
        $periodeDeLaBoutique = PeriodeActivite::query()->where('magasin_id', $boutique->id)->sole();
        $this->assertSame(
            [[$siege->id, $premiere->id], [$boutique->id, $periodeDeLaBoutique->id]],
            Event::dispatched(MagasinOuvert::class)->map(fn (array $appel): array => [$appel[0]->magasin->id, $appel[0]->periode->id])->all(),
        );
        $this->assertSame([[$siege->id, $premiere->id, MotifFermeture::Temporaire]], Event::dispatched(MagasinFerme::class)->map(fn (array $appel): array => [$appel[0]->magasin->id, $appel[0]->periode->id, $appel[0]->periode->motif_fin])->all());
        $this->assertSame([[$siege->id, $seconde->id]], Event::dispatched(MagasinRouvert::class)->map(fn (array $appel): array => [$appel[0]->magasin->id, $appel[0]->periode->id])->all());
    }

    /**
     * Le responsable n'agit jamais sur le cycle de vie de son magasin,
     * l'expert-comptable non plus : ni fermeture, ni cession, ni
     * réouverture, ni changement de SIRET, même avec leur bon mot de passe.
     */
    public function test_ni_un_responsable_ni_l_expert_comptable_ne_ferment_ne_rouvrent_ni_ne_cedent_un_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ferme = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique fermée']);
        $this->fermer($ferme, MotifFermeture::Temporaire);
        $responsable = $this->responsable($magasin, $ferme);
        $expert = $this->expertComptable($magasin);

        foreach ([$responsable, $expert] as $compte) {
            $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Temporaire, $compte, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
            $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, $compte, self::MOT_DE_PASSE_CHOISI, 'Ma Boutique'), AuthorizationException::class);
            $this->assertThrows(fn () => $this->rouvrir($ferme, $compte, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerSiretAction::class)->handle($compte, $magasin->fresh(), '00099', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
        }

        $this->assertSame([StatutMagasin::Actif, StatutMagasin::Ferme], [$magasin->fresh()->statut, $ferme->fresh()->statut]);
    }

    /**
     * Les caisses personnelles restées ouvertes se ferment d'office
     * (« fermée automatiquement ») ; l'équipe garde ses affectations, et
     * reste gérable dans le magasin fermé.
     */
    public function test_la_fermeture_ferme_d_office_les_caisses_personnelles_et_garde_l_equipe(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        $ticket = $this->vendre($magasin);
        auth()->logout();
        $affectations = Affectation::query()->where('magasin_id', $magasin->id)->count();
        $this->travelTo('2026-10-05 18:00:00');

        $this->fermer($magasin, MotifFermeture::Temporaire);

        app(TenantContext::class)->set($magasin);
        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $vendeur->id)->sole();
        $this->assertSame([ModeFermetureCaisse::Automatique, [$ticket->id]], [$releve->mode_fermeture, $releve->factures->modelKeys()]);
        $this->assertSame($affectations, Affectation::query()->where('magasin_id', $magasin->id)->count());
        $this->assertTrue($vendeur->fresh()->peutOuvrir($magasin->fresh()));
    }

    /**
     * Le vendeur encaisse un ticket une seconde après l'appel de la
     * fermeture, pendant qu'elle contrôle son auteur, juste avant qu'elle
     * verrouille le registre : sa vente entre dans le ticket Z et dans le
     * relevé de sa caisse fermée d'office, avec son encaissement, et aucune
     * caisse ne reste ouverte.
     */
    public function test_une_vente_faite_avant_le_verrou_du_registre_entre_dans_le_releve_ferme_d_office(): void
    {
        $this->travelTo('2026-10-05 18:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $ticket = null;
        $this->avantLeVerrouDuRegistre(function () use ($magasin, $vendeur, &$ticket): void {
            $this->travel(1)->seconds();
            $this->actingAs($vendeur);
            $ticket = $this->vendre($magasin);
            auth()->logout();
        });

        $this->fermer($magasin, MotifFermeture::Temporaire);

        app(TenantContext::class)->set($magasin);
        $this->assertInstanceOf(Facture::class, $ticket);
        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $vendeur->id)->sole();
        $this->assertSame([ModeFermetureCaisse::Automatique, [$ticket->id], 1], [$releve->mode_fermeture, $releve->factures->modelKeys(), $releve->paiements->count()]);
        $this->assertSame([1, '12.00'], [ClotureCaisse::query()->sole()->nombre_ventes, ClotureCaisse::query()->sole()->total_ttc]);
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now())->all());
    }

    /**
     * Le magasin cédé perd l'indication d'établissement principal, retrait
     * noté avec sa fermeture au journal d'audit, et ne la reprend plus.
     */
    public function test_un_magasin_cede_perd_l_indication_d_etablissement_principal(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        app(ChoisirEtablissementPrincipalAction::class)->handle($proprietaire, $magasin->fresh(), true);

        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $cede = $magasin->fresh();
        $this->assertSame([true, false], [$cede->aEteCede(), $cede->est_etablissement_principal]);
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinFerme)[0];
        $this->assertSame([true, false], [$entree->anciennes_valeurs['est_etablissement_principal'], $entree->nouvelles_valeurs['est_etablissement_principal']]);
        $this->assertThrows(fn () => app(ChoisirEtablissementPrincipalAction::class)->handle($proprietaire, $magasin->fresh(), true), LogicException::class, ChoisirEtablissementPrincipalAction::MESSAGE_CEDE);
        $this->assertFalse($magasin->fresh()->est_etablissement_principal);
    }

    /**
     * Un établissement déjà confirmé, dont la société a corrigé l'adresse,
     * rouvre sans réponse de l'INSEE : la reprise du contrôle le confirme
     * et garde l'adresse que la société a enregistrée. Un établissement créé
     * pendant la panne, jamais confirmé, reprend celle du répertoire.
     */
    public function test_une_reouverture_sans_reponse_de_l_insee_garde_l_adresse_enregistree_par_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        app(ModifierEtablissementAction::class)->handle($proprietaire, $magasin->fresh(), ['adresse' => '12 rue Corrigée par la société', 'complement_adresse' => 'Bâtiment B']);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->repertoire()->rendreInjoignable();
        $this->rouvrir($magasin);
        $creePendantLaPanne = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique créée pendant la panne']);
        $this->repertoire()->rendreInjoignable(false);
        $this->assertSame([null, null], [$magasin->fresh()->etablissement_verifie_le, $creePendantLaPanne->fresh()->etablissement_verifie_le]);
        app(TenantContext::class)->clear();

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $rouvert = $magasin->fresh();
        $nouveau = $creePendantLaPanne->fresh();
        $this->assertSame([true, '12 rue Corrigée par la société', 'Bâtiment B', '75001'], [$rouvert->peutVendre(), $rouvert->adresse, $rouvert->complement_adresse, $rouvert->code_postal]);
        $this->assertSame([true, '1 RUE DE LA REPUBLIQUE', null, '75001'], [$nouveau->peutVendre(), $nouveau->adresse, $nouveau->complement_adresse, $nouveau->code_postal]);
    }

    /**
     * Avant la validation de la société, un magasin n'a aucune activité à
     * céder, ouvert ou fermé : il se ferme pour cessation. Fermé, il se
     * rouvre après le contrôle de son établissement au répertoire Sirene
     * (réponse M2 de l'éditeur), sans période d'activité ni SIRET réservé,
     * et ne vend pas avant la validation ; sa réouverture s'inscrit au
     * journal des événements et au journal d'audit.
     */
    public function test_avant_la_validation_un_magasin_ferme_se_rouvre_sans_periode_d_activite_et_ne_se_cede_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique'), LogicException::class, FermerMagasinAction::MESSAGE_CESSION_AVANT_VALIDATION);
        $this->fermer($magasin, MotifFermeture::Cessation);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique'), LogicException::class, FermerMagasinAction::MESSAGE_CESSION_AVANT_VALIDATION);

        $this->rouvrir($magasin);

        $rouvert = $magasin->fresh();
        $this->assertSame([StatutMagasin::Actif, null, false], [$rouvert->statut, $rouvert->motif_fermeture, $rouvert->peutVendre()]);
        $this->assertSame([0, 0], [PeriodeActivite::query()->where('magasin_id', $magasin->id)->count(), SiretMagasin::query()->where('magasin_id', $magasin->id)->count()]);
        app(TenantContext::class)->set($magasin);
        $evenement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->orderByDesc('sequence')->first()->contenuSigne();
        $this->assertSame(
            [['parametre' => 'statut_magasin', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => 'ferme', 'nouvelle_valeur' => 'actif'], $proprietaire->id],
            [$evenement['donnees']['details'], $evenement['operateur']['id']],
        );
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinRouvert)[0];
        $this->assertSame([['statut' => 'ferme'], ['etablissement_verifie' => true, 'statut' => 'actif'], $proprietaire->id], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, (int) $entree->auteur_id]);
    }

    /**
     * Avant la validation aussi, la réouverture d'un établissement que
     * l'INSEE a fermé est refusée, et le magasin reste fermé.
     */
    public function test_avant_la_validation_la_reouverture_d_un_siret_ferme_a_l_insee_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->repertoire()->fermer((string) $magasin->siret);

        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, ControleSirene::MOTIF_ETABLISSEMENT_FERME);

        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Temporaire], [$magasin->fresh()->statut, $magasin->fresh()->motif_fermeture]);
    }

    /**
     * Le magasin rouvert avant la validation compte parmi les magasins
     * actifs de la société : la validation le met en activité avec les
     * autres (SIRET réservé, première période, MagasinOuvert), et il vend.
     */
    public function test_un_magasin_rouvert_avant_la_validation_entre_en_activite_a_la_validation(): void
    {
        Event::fake([MagasinOuvert::class]);
        $societe = $this->inscrireUneSociete();
        $siege = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire;
        $this->fermer($siege, MotifFermeture::Temporaire, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->rouvrir($siege, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->assertSame(0, PeriodeActivite::query()->where('magasin_id', $siege->id)->count());

        $this->verifierLIdentiteDuProprietaire($societe);

        $periode = PeriodeActivite::query()->where('magasin_id', $siege->id)->sole();
        $this->assertSame([(string) $siege->siret], SiretMagasin::query()->where('magasin_id', $siege->id)->whereNull('retire_le')->pluck('siret')->all());
        Event::assertDispatched(MagasinOuvert::class, fn (MagasinOuvert $evenement): bool => $evenement->magasin->is($siege) && $evenement->periode->is($periode));
        $this->assertSame('TK-MABOUT-2026-000001', $this->vendre($siege)->numero);
    }

    /**
     * @return array<string, array{0: Closure(Magasin, User): string}>
     */
    public static function correctionsDuSiretPendantLeControle(): array
    {
        return [
            'NIC corrigé' => [static function (Magasin $magasin, User $proprietaire): string {
                $siret = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'9001');
                app(ModifierEtablissementAction::class)->handle($proprietaire, $magasin->fresh(), ['nic' => substr($siret, 9)]);

                return $siret;
            }],
            'SIREN corrigé' => [static function (Magasin $magasin, User $proprietaire): string {
                $siren = SocieteFactory::sirenFictif();
                $siret = IdentifiantsInsee::avecSaCle($siren.'9001');
                app(CorrigerSirenAvantValidationAction::class)->handle($proprietaire, $magasin->societe->fresh(), $siren, [$magasin->id => substr($siret, 9)]);

                return $siret;
            }],
        ];
    }

    /**
     * Avant la validation, le SIRET d'un magasin fermé change encore : son
     * NIC, ou le SIREN de la société, se corrige pendant que la réouverture
     * attend le répertoire Sirene, l'INSEE ne répondant plus à la
     * correction, qui laisse l'établissement à vérifier. Le contrôle de la
     * réouverture, concluant pour l'ancien SIRET, ne vaut pas pour le
     * nouveau : elle est refusée sans rien écrire, le magasin reste fermé
     * et à vérifier, et aucun contrôle concluant ne s'inscrit sous le
     * nouveau SIRET. Recommencée, elle contrôle le nouveau SIRET, fermé au
     * répertoire, et elle est refusée.
     *
     * @param  Closure(Magasin, User): string  $corriger  la correction, qui rend le nouveau SIRET
     */
    #[DataProvider('correctionsDuSiretPendantLeControle')]
    public function test_une_reouverture_dont_le_siret_change_pendant_le_controle_sirene_est_refusee(Closure $corriger): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $proprietaire = $this->proprietaireDe($magasin);
        $ancienSiret = (string) $magasin->siret;
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $nouveauSiret = null;
        $this->pendantLaConsultationDe($ancienSiret, function () use ($magasin, $proprietaire, $corriger, &$nouveauSiret): void {
            $this->repertoire()->rendreInjoignable();
            $nouveauSiret = $corriger($magasin, $proprietaire);
            $this->repertoire()->rendreInjoignable(false);
        });

        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, RouvrirMagasinAction::MESSAGE_CONTROLE_PERIME);

        $refuse = $magasin->fresh();
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Temporaire, $nouveauSiret, null], [$refuse->statut, $refuse->motif_fermeture, $refuse->siret, $refuse->etablissement_verifie_le]);
        $this->assertSame([['concluante', $ancienSiret], ['indisponible', $nouveauSiret]], $this->controlesDeLEtablissement($magasin));
        $this->assertSame(['ferme'], $this->changementsDeStatut($magasin));
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinRouvert));
        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, ControleSirene::MOTIF_ETABLISSEMENT_FERME);
    }

    /**
     * @return array<string, array{0: bool, 1: Closure(Societe): void}>
     */
    public static function decisionsDeLEditeurPendantLeControle(): array
    {
        return [
            'société refusée avant sa validation' => [true, static function (Societe $societe): void {
                app(RefuserLaSocieteAction::class)->handle($societe, new InterventionDeLEditeur('Pièces falsifiées, signalement du vrai dirigeant.', 'DOSSIER-2026-0042', ['alice']));
            }],
            'société validée suspendue' => [false, static function (Societe $societe): void {
                $societe->fresh()->forceFill(['statut' => StatutSociete::Suspendue])->save();
            }],
        ];
    }

    /**
     * L'éditeur refuse la société, ou la suspend, pendant que la
     * réouverture attend le répertoire Sirene : la société passe en lecture
     * seule, où aucun magasin ne se rouvre. La réouverture, qui relit la
     * société sous son verrou, est refusée comme par la policy, sans rien
     * écrire : le magasin reste fermé, sans JET 270 de réouverture, sans
     * contrôle Sirene ni entrée d'audit.
     *
     * @param  Closure(Societe): void  $decider  la décision de l'éditeur
     */
    #[DataProvider('decisionsDeLEditeurPendantLeControle')]
    public function test_une_reouverture_pendant_laquelle_la_societe_passe_en_lecture_seule_est_refusee(bool $aVerifier, Closure $decider): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $aVerifier]);
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $controles = $this->controlesDeLEtablissement($magasin);
        $this->pendantLaConsultationDe((string) $magasin->siret, fn () => $decider($magasin->societe));

        $this->assertThrows(fn () => $this->rouvrir($magasin), AuthorizationException::class, LecteurDesDroits::REFUS_DANS_LA_SOCIETE);

        $this->assertTrue($magasin->societe->fresh()->estEnLectureSeule());
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Temporaire], [$magasin->fresh()->statut, $magasin->fresh()->motif_fermeture]);
        $this->assertSame($controles, $this->controlesDeLEtablissement($magasin));
        $this->assertSame(['ferme'], $this->changementsDeStatut($magasin));
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinRouvert));
    }

    /**
     * Un magasin fermé temporairement, dont l'INSEE a fermé l'établissement
     * après la fermeture, se cède sans être rouvert ni recontrôlé au
     * répertoire Sirene (réponse M1 de l'éditeur) : son motif de fermeture
     * devient la cession, au journal des événements (JET 270) comme au
     * journal d'audit ; sa période d'activité close reste telle qu'elle est ;
     * son stock sort au coût moyen, le magasin étant la source de chaque
     * sortie, et sa valeur hors taxe (10 x 2,50 = 25,00 €) va au
     * propriétaire ; aucune journée n'est clôturée de nouveau. Cédé, il ne se
     * rouvre plus.
     */
    public function test_un_magasin_ferme_temporairement_se_cede_sans_etre_rouvert_ni_recontrole_au_repertoire_sirene(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $carnet = $this->produitEnStock('CARNET', 'Carnet', 10, '2.50');
        $this->produitEnStock('GOMME', 'Gomme', -2, '0.80');
        $this->vendre($magasin);
        $this->travelTo('2026-10-05 18:00:00');
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->repertoire()->fermer((string) $magasin->siret);
        $this->travelTo('2026-10-09 11:00:00');

        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $cede = $magasin->fresh();
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Cession, true], [$cede->statut, $cede->motif_fermeture, $cede->aEteCede()]);
        $periode = PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole();
        $this->assertSame(['2026-10-05 18:00:00', MotifFermeture::Temporaire], [$periode->fin->format('Y-m-d H:i:s'), $periode->motif_fin]);
        app(TenantContext::class)->set($magasin);
        $this->assertSame(
            [[$carnet->id, 10, '2.5000', 'magasin', $magasin->id, $proprietaire->id]],
            MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->get()
                ->map(fn (MouvementStock $sortie): array => [$sortie->produit_id, $sortie->quantite, $sortie->cout_unitaire_ht, $sortie->source_type, (int) $sortie->source_id, (int) $sortie->cree_par])->all(),
        );
        $evenement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->orderByDesc('sequence')->first()->contenuSigne();
        $this->assertSame(
            [['parametre' => 'motif_fermeture', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => 'temporaire', 'nouvelle_valeur' => 'cession'], $proprietaire->id],
            [$evenement['donnees']['details'], $evenement['operateur']['id']],
        );
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinCede)[0];
        $this->assertSame(
            [['motif' => 'temporaire'], ['motif' => 'cession', 'produits_en_stock_negatif' => ['GOMME Gomme (-2)'], 'produits_sortis' => 1, 'valeur_stock_sorti_ht' => '25.00'], $proprietaire->id],
            [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, (int) $entree->auteur_id],
        );
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinFerme));
        Notification::assertSentTo($proprietaire, StockDuMagasinCede::class, fn (StockDuMagasinCede $avis): bool => $avis->valeurHt === '25.00' && $avis->produitsEnStockNegatif === ['GOMME Gomme (-2)']);
        $this->assertSame(['2026-10-05'], ClotureCaisse::query()->pluck('date_cloture')->map->toDateString()->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
        $this->assertThrows(fn () => $this->rouvrir($magasin), LogicException::class, RouvrirMagasinAction::MESSAGE_CEDE);
    }

    /**
     * Un magasin fermé pour cessation ne se cède que par le propriétaire,
     * qui retape son nom et redonne son mot de passe ; jamais par un
     * administrateur, même avec toutes les autorisations.
     */
    public function test_seul_le_proprietaire_cede_un_magasin_ferme_en_retapant_son_nom(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $this->fermer($magasin, MotifFermeture::Cessation);

        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, $administrateur, self::MOT_DE_PASSE_CHOISI, 'Ma Boutique'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma boutique'), LogicException::class, FermerMagasinAction::MESSAGE_CONFIRMATION);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cession, motDePasse: 'devine-au-hasard', confirmation: 'Ma Boutique'), LogicException::class, MotDePasseRedemande::MESSAGE);
        $this->assertSame(MotifFermeture::Cessation, $magasin->fresh()->motif_fermeture);

        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $this->assertSame(MotifFermeture::Cession, $magasin->fresh()->motif_fermeture);
    }

    /**
     * Un magasin fermé ne se referme que pour être cédé ; cédé, il ne se
     * ferme ni ne se cède plus, et son stock ne sort qu'une fois.
     */
    public function test_un_magasin_cede_ne_se_ferme_ni_ne_se_cede_plus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->produitEnStock('CARNET', 'Carnet', 4, '2.50');
        $this->fermer($magasin, MotifFermeture::Temporaire);
        $this->assertThrows(fn () => $this->fermer($magasin, MotifFermeture::Cessation), LogicException::class, FermerMagasinAction::MESSAGE_DEJA_FERME);
        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        foreach ([MotifFermeture::Temporaire, MotifFermeture::Cessation, MotifFermeture::Cession] as $motif) {
            $this->assertThrows(fn () => $this->fermer($magasin, $motif, confirmation: 'Ma Boutique'), LogicException::class, FermerMagasinAction::MESSAGE_DEJA_CEDE);
        }

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->count());
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinCede));
    }

    /**
     * Un magasin fermé avant la validation n'a aucune période d'activité :
     * une fois la société validée, il se cède quand même, son stock (5
     * articles reçus pendant la configuration) sortant au coût moyen, sans
     * qu'aucune période ne s'ouvre.
     */
    public function test_un_magasin_ferme_avant_la_validation_se_cede_une_fois_la_societe_validee(): void
    {
        $societe = $this->inscrireUneSociete();
        $siege = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire;
        app(TenantContext::class)->set($siege);
        $carnet = $this->produitEnStock('CARNET', 'Carnet', 5, '2.00');
        $this->fermer($siege, MotifFermeture::Cessation, $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->verifierLIdentiteDuProprietaire($societe);
        $this->assertTrue($societe->fresh()->estValidee());

        $this->fermer($siege, MotifFermeture::Cession, $proprietaire, self::MOT_DE_PASSE_CHOISI, 'Ma Boutique');

        $this->assertSame([MotifFermeture::Cession, 0], [$siege->fresh()->motif_fermeture, PeriodeActivite::query()->where('magasin_id', $siege->id)->count()]);
        app(TenantContext::class)->set($siege);
        $this->assertSame(0, $carnet->fresh()->stock_quantite);
        $evenement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->orderByDesc('sequence')->first()->contenuSigne();
        $this->assertSame(['cessation', 'cession'], [$evenement['donnees']['details']['ancienne_valeur'], $evenement['donnees']['details']['nouvelle_valeur']]);
        $this->assertSame('10.00', $this->entreesDAudit($societe, ActionAuditee::MagasinCede)[0]->nouvelles_valeurs['valeur_stock_sorti_ht']);
    }

    /**
     * Le motif de fermeture n'existe que pour un magasin fermé, et une
     * cession est définitive, même pour une écriture qui ne passe par
     * aucune action : un magasin cédé ne redevient ni ouvert ni fermé pour
     * un autre motif.
     */
    public function test_un_magasin_cede_le_reste_meme_par_une_ecriture_directe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ouvert = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $this->fermer($magasin, MotifFermeture::Cession, confirmation: 'Ma Boutique');

        $this->assertThrows(fn () => $ouvert->fresh()->forceFill(['motif_fermeture' => MotifFermeture::Temporaire])->save(), LogicException::class, 'Un magasin ouvert n\'a pas de motif de fermeture : sa réouverture l\'efface.');
        $this->assertThrows(fn () => $magasin->fresh()->forceFill(['statut' => StatutMagasin::Actif, 'motif_fermeture' => null])->save(), LogicException::class, 'Un magasin cédé ne se rouvre jamais et reste cédé.');
        $this->assertThrows(fn () => $magasin->fresh()->forceFill(['motif_fermeture' => MotifFermeture::Temporaire])->save(), LogicException::class, 'Un magasin cédé ne se rouvre jamais et reste cédé.');

        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Cession, null], [$magasin->fresh()->statut, $magasin->fresh()->motif_fermeture, $ouvert->fresh()->motif_fermeture]);
    }

    /**
     * La société ferme le magasin, par son propriétaire à défaut d'un autre
     * auteur, qui redonne son mot de passe.
     */
    private function fermer(Magasin $magasin, MotifFermeture $motif, ?User $auteur = null, string $motDePasse = self::MOT_DE_PASSE_DU_PROPRIETAIRE, ?string $confirmation = null): void
    {
        app(FermerMagasinAction::class)->handle($auteur ?? $this->proprietaireDe($magasin), $magasin->fresh(), $motif, $motDePasse, $confirmation);
    }

    private function rouvrir(Magasin $magasin, ?User $auteur = null, string $motDePasse = self::MOT_DE_PASSE_DU_PROPRIETAIRE): void
    {
        app(RouvrirMagasinAction::class)->handle($auteur ?? $this->proprietaireDe($magasin), $magasin->fresh(), $motDePasse);
    }

    /**
     * Un ticket de 12,00 € TTC au comptoir du magasin, payé en espèces, par
     * la personne connectée ou le propriétaire.
     */
    private function vendre(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin->fresh());

        return self::vendreAuComptant(
            self::auteur(),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }

    /**
     * Un produit suivi en stock du magasin actif, à ce coût moyen, amené à
     * cette quantité par un ajustement d'inventaire.
     */
    private function produitEnStock(string $reference, string $nom, int $quantite, string $coutMoyen): Produit
    {
        $produit = Produit::factory()->create(['reference' => $reference, 'nom' => $nom, 'cmup_ht' => $coutMoyen, 'suivi_stock' => true, 'seuil_alerte' => 0]);
        app(StockLedgerService::class)->ajuster($produit, $quantite, 'Inventaire d\'ouverture');

        return $produit;
    }

    /**
     * Fait $avant juste avant que la prochaine opération ne verrouille le
     * registre fiscal (voir RegistreFiscal::sousVerrou()), une seule fois :
     * ce qui s'y fait passe avant elle, comme une vente qui tenait le
     * registre pendant qu'elle contrôlait son auteur.
     */
    private function avantLeVerrouDuRegistre(Closure $avant): void
    {
        $this->app->instance(RegistreFiscal::class, new class(app(SignataireFiscal::class), $avant) extends RegistreFiscal
        {
            public function __construct(SignataireFiscal $signataire, private ?Closure $avant)
            {
                parent::__construct($signataire);
            }

            public function sousVerrou(Magasin $magasin, Closure $operation): mixed
            {
                $avant = $this->avant;
                $this->avant = null;

                if ($avant !== null) {
                    $avant();
                }

                return parent::sousVerrou($magasin, $operation);
            }
        });
    }

    private function repertoire(): FauxRepertoireSirene
    {
        return app(RepertoireSirene::class);
    }

    /**
     * Les statuts que le journal des événements du magasin a reçus (JET
     * 270 statut_magasin), dans l'ordre.
     *
     * @return list<string>
     */
    private function changementsDeStatut(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->filter(fn (array $details): bool => $details['parametre'] === 'statut_magasin')
            ->map(fn (array $details): string => $details['nouvelle_valeur'])
            ->values()
            ->all());
    }
}
