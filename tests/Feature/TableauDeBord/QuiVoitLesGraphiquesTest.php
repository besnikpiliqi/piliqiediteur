<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\TableauDeBordDeLaSociete;
use App\Filament\App\Widgets\GraphiquesDuMagasin;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\FicheDuClient;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\GraphiquesDuClient;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\ListClientsDuCabinet;
use App\Filament\GrilleDesGraphiques;
use App\Filament\Societe\Pages\TableauDeBord;
use Database\Factories\SocieteFactory;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qui voit les chiffres clés et les graphiques du tableau de bord, et sur
 * quels magasins (décision de l'éditeur du 14/11/2026, lot GRAPH ;
 * CLAUDE.md, « Tableau de bord ») : le propriétaire partout ; un
 * administrateur ou un responsable seulement si le propriétaire lui coche
 * « Tableau de bord : voir les graphiques », dans sa portée ; jamais un
 * vendeur ni un observateur ; le cabinet lié et l'expert-comptable déclaré
 * selon les consultations que le client leur accorde, sur les magasins
 * qu'il leur ouvre ; rien pour un cabinet qui n'est plus vérifié ni après
 * la fin du lien.
 */
class QuiVoitLesGraphiquesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire voit tous les graphiques du catalogue sur tous ses
     * magasins réels, dans l'espace de la société et dans le panneau de
     * chacun.
     */
    public function test_le_proprietaire_voit_tout_sur_tous_ses_magasins_reels(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);

        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);

        $this->assertSame([$lyon->id, $villeurbanne->id], $perimetre->magasins()->sortBy('id')->modelKeys());
        $this->assertSame(Graphique::cases(), $perimetre->graphiquesVisibles());
        $this->assertTrue(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuMagasin($proprietaire, $lyon));
        $this->assertTrue(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuMagasin($proprietaire, $villeurbanne));
    }

    /**
     * Sans la case, un administrateur ne voit aucun graphique, ni dans
     * l'espace de la société, ni dans le panneau de ses magasins ; avec
     * elle, seulement dans les magasins de sa portée.
     */
    public function test_un_administrateur_ne_les_voit_qu_avec_la_case_et_dans_sa_portee(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $sansLaCase = $this->administrateur($lyon);
        $avecLaCase = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR]);
        app(TenantContext::class)->clear();
        $graphiques = app(PerimetreDesGraphiques::class);

        $this->assertFalse($graphiques->voitLesGraphiquesDeLaSociete($sansLaCase, $lyon->societe));
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($sansLaCase, $lyon));
        $this->assertThrows(fn () => $graphiques->deLaSociete($sansLaCase, $lyon->societe), AuthorizationException::class, PerimetreDesGraphiques::REFUS);

        $this->assertTrue($graphiques->voitLesGraphiquesDeLaSociete($avecLaCase, $lyon->societe));
        $this->assertSame([$lyon->id], $graphiques->deLaSociete($avecLaCase, $lyon->societe)->magasins()->modelKeys());
        $this->assertTrue($graphiques->voitLesGraphiquesDuMagasin($avecLaCase, $lyon));
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($avecLaCase, $villeurbanne));
    }

    /**
     * Un responsable ne les a pas d'office, seconde exception à « un
     * responsable a tous les droits dans son magasin » : sans la case, rien ;
     * avec elle, le panneau de son magasin, jamais l'espace de la société
     * ni un autre magasin.
     */
    public function test_un_responsable_ne_les_voit_qu_avec_la_case_dans_son_magasin(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $sansLaCase = $this->membre($lyon, RoleMagasin::Responsable);
        $avecLaCase = $this->membre($lyon, RoleMagasin::Responsable, [PermissionRegistry::GRAPHIQUES_VOIR]);
        app(TenantContext::class)->clear();
        $graphiques = app(PerimetreDesGraphiques::class);

        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($sansLaCase, $lyon));
        $this->assertTrue($graphiques->voitLesGraphiquesDuMagasin($avecLaCase, $lyon));
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($avecLaCase, $villeurbanne));
        $this->assertSame([$lyon->id], $graphiques->duMagasin($avecLaCase, $lyon)->magasins()->modelKeys());
        $this->assertNotContains(Graphique::ChiffreDAffairesParMagasin, $graphiques->duMagasin($avecLaCase, $lyon)->graphiquesVisibles(), 'Le panneau d\'un magasin ne laisse deviner aucun autre magasin.');
        $this->assertThrows(fn () => $graphiques->deLaSociete($avecLaCase, $lyon->societe), AuthorizationException::class);
    }

    /**
     * La case ne se coche jamais à un vendeur ni à un observateur, qui ne
     * voient aucun graphique même avec toutes les autres autorisations.
     */
    public function test_un_vendeur_ou_un_observateur_ne_les_voit_jamais(): void
    {
        [$lyon] = $this->deuxMagasins();

        foreach ([RoleMagasin::Vendeur, RoleMagasin::Observateur] as $role) {
            $this->assertThrows(fn () => $this->membre($lyon, $role, [PermissionRegistry::GRAPHIQUES_VOIR]), LogicException::class, AffectationsDesMembres::MESSAGE_REFUSEES_AUX_VENDEURS);
            $compte = $this->membre($lyon, $role, self::toutesCellesDUnVendeur());
            app(TenantContext::class)->clear();

            $this->assertFalse(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuMagasin($compte, $lyon), $role->value);
        }
    }

    /**
     * Le collaborateur à qui le client est confié ouvre sa page dans
     * l'espace du cabinet, sur ses magasins réels ; un autre collaborateur
     * du même cabinet, à qui il ne l'est pas, est refusé.
     */
    public function test_seul_le_collaborateur_a_qui_le_client_est_confie_voit_ses_graphiques(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $confie = $this->collaborateurDuCabinetLie($lyon);
        $lien = $this->lienActif($lyon->societe);
        $autre = $this->collaborateur($confie->societe()->firstOrFail(), 'Inès Moreau');
        $graphiques = app(PerimetreDesGraphiques::class);

        $this->assertSame([$lyon->id, $villeurbanne->id], $graphiques->duClient($confie, $lien)->magasins()->sortBy('id')->modelKeys());
        $this->assertThrows(fn () => $graphiques->duClient($autre, $lien), AuthorizationException::class, PerimetreDesGraphiques::REFUS);
    }

    /**
     * Un cabinet à qui le client retire les achats n'a aucun graphique
     * qui les compte (ventes et achats, achats par fournisseur, TVA à
     * payer, qui déduit celle des achats), ni le chiffre clé des achats ;
     * il garde ceux des seules ventes.
     */
    public function test_un_cabinet_a_qui_le_client_retire_les_achats_n_a_aucun_graphique_d_achats(): void
    {
        [$lyon] = $this->deuxMagasins();
        $sansLesAchats = array_values(array_diff(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW]));
        $collaborateur = $this->collaborateurDuCabinetLie($lyon, $sansLesAchats);

        $visibles = app(PerimetreDesGraphiques::class)->duClient($collaborateur, $this->lienActif($lyon->societe))->graphiquesVisibles();

        foreach ([Graphique::VentesEtAchatsParMois, Graphique::AchatsParFournisseur, Graphique::TvaAPayerParMois] as $absent) {
            $this->assertNotContains($absent, $visibles, $absent->getLabel());
        }

        $this->assertContains(Graphique::ChiffreDAffairesParMois, $visibles);
    }

    /**
     * L'expert-comptable déclaré par la société, limité par le propriétaire
     * à Lyon, ne voit sur sa page que Lyon, et ouvre les graphiques du seul
     * panneau de Lyon.
     */
    public function test_l_expert_comptable_declare_limite_a_un_magasin_ne_voit_que_lui(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $marc = $this->expertComptable($lyon);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon->societe_id)->where('etat', EtatDuLien::Declare->value)->firstOrFail();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($lyon), $declaration, $declaration->autorisations(), [$lyon->id]);
        app(TenantContext::class)->clear();
        $graphiques = app(PerimetreDesGraphiques::class);

        $this->assertSame([$lyon->id], $graphiques->delExpertComptable($marc->refresh())->magasins()->modelKeys());
        $this->assertTrue($graphiques->voitLesGraphiquesDuMagasin($marc, $lyon));
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($marc, $villeurbanne));
    }

    /**
     * Un cabinet qui n'est plus vérifié (suspendu par l'éditeur) ne voit
     * plus rien de son client ; après la fin du lien non plus, même revenu
     * actif.
     */
    public function test_un_cabinet_non_verifie_ou_dont_le_lien_a_pris_fin_ne_voit_rien(): void
    {
        [$lyon] = $this->deuxMagasins();
        $collaborateur = $this->collaborateurDuCabinetLie($lyon);
        $lien = $this->lienActif($lyon->societe);
        $cabinet = $collaborateur->societe()->firstOrFail();
        $graphiques = app(PerimetreDesGraphiques::class);

        $this->changerLeStatut($cabinet, StatutSociete::Suspendue);
        $this->assertThrows(fn () => $graphiques->duClient($collaborateur->refresh(), $lien), AuthorizationException::class);
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($collaborateur, $lyon));

        $this->changerLeStatut($cabinet, StatutSociete::Active);
        $this->assertCount(2, $graphiques->duClient($collaborateur->refresh(), $lien)->magasins(), 'Revenu actif et vérifié, le cabinet revoit son client.');
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($lyon), $lien);
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => $graphiques->duClient($collaborateur->refresh(), $lien->refresh()), AuthorizationException::class);
        $this->assertFalse($graphiques->voitLesGraphiquesDuMagasin($collaborateur, $lyon));
    }

    /**
     * Par l'adresse directe, dans le panneau d'un magasin, rien ne
     * s'ouvre à un vendeur : son tableau de bord ne monte pas la grille, la
     * page de l'expert-comptable déclaré lui répond 403, et la grille
     * montée de force aussi.
     */
    public function test_l_adresse_directe_ne_donne_aucun_graphique_a_un_vendeur(): void
    {
        [$lyon] = $this->deuxMagasins();
        $vendeur = $this->membre($lyon, RoleMagasin::Vendeur);
        app(TenantContext::class)->clear();

        $this->actingAs($vendeur);
        $this->get(Dashboard::getUrl(panel: 'app', tenant: $lyon))->assertOk()->assertDontSeeLivewire(GraphiquesDuMagasin::class);
        $this->get(TableauDeBordDeLaSociete::getUrl(panel: 'app', tenant: $lyon))->assertForbidden();
        $this->dansLeMagasin($lyon, $vendeur);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'magasin'])->assertForbidden();
    }

    /**
     * Dans l'espace de la société, un administrateur sans la case ne voit
     * pas la grille, et la grille montée de force lui répond 403.
     */
    public function test_l_adresse_directe_ne_donne_aucun_graphique_a_un_administrateur_sans_la_case(): void
    {
        [$lyon] = $this->deuxMagasins();
        $administrateur = $this->administrateur($lyon);
        app(TenantContext::class)->clear();

        $this->actingAs($administrateur);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))->assertOk()->assertDontSeeLivewire(GrilleDesGraphiques::NOM);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'societe'])->assertForbidden();
    }

    /**
     * Dans l'espace du cabinet, la page d'un client répond 404 au
     * collaborateur à qui il n'est pas confié, et la grille montée de force
     * avec l'uuid de son lien 403 ; le collaborateur confié l'ouvre.
     */
    public function test_l_adresse_directe_de_la_page_d_un_client_ne_s_ouvre_qu_au_collaborateur_confie(): void
    {
        [$lyon] = $this->deuxMagasins();
        $confie = $this->collaborateurDuCabinetLie($lyon);
        $lien = $this->lienActif($lyon->societe);
        $cabinet = $confie->societe()->firstOrFail();
        $autre = $this->collaborateur($cabinet, 'Inès Moreau');
        $adresse = ClientDuCabinetResource::getUrl('graphiques', ['record' => $lien], panel: 'cabinet', tenant: $cabinet);

        $this->actingAs($autre);
        $this->get($adresse)->assertNotFound();
        $this->dansLEspaceDuCabinet($cabinet, $autre);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'cabinet', 'lien' => (string) $lien->uuid])->assertForbidden();

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->actingAs($confie);
        $this->get($adresse)->assertOk()->assertSeeLivewire(GrilleDesGraphiques::NOM);
    }

    /**
     * Un client que le cabinet a invité naît validé et lié, sans magasin :
     * ni la liste des clients ni sa fiche n'offrent « Graphiques », et sa
     * page, ouverte par l'adresse directe, dit qu'il n'a encore aucun
     * magasin au lieu de monter la grille ; un client qui a un magasin
     * réel offre « Graphiques » et monte la grille.
     */
    public function test_un_client_sans_magasin_n_offre_pas_de_graphiques_et_sa_page_le_dit(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($this->collaborateurDuCabinetLie($lyon)->societe()->firstOrFail());
        $cabinet = $proprietaireDuCabinet->societe()->firstOrFail();
        $invite = $this->accepterLInvitation($this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test'))->societe()->firstOrFail();
        $sansMagasin = $this->lienActif($invite);
        $avecMagasin = $this->lienActif($lyon->societe);

        $this->assertFalse(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuClient($proprietaireDuCabinet, $sansMagasin));
        $this->assertTrue(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuClient($proprietaireDuCabinet, $avecMagasin));
        $this->dansLEspaceDuCabinet($cabinet, $proprietaireDuCabinet);
        Livewire::test(ListClientsDuCabinet::class)
            ->assertTableActionHidden('graphiques', $sansMagasin->getKey())
            ->assertTableActionVisible('graphiques', $avecMagasin->getKey())
            ->assertTableColumnStateSet('magasins', 0, $sansMagasin->getKey())
            ->assertTableColumnStateSet('magasins', 2, $avecMagasin->getKey());
        Livewire::test(FicheDuClient::class, ['record' => $sansMagasin->getRouteKey()])->assertActionHidden('graphiques');
        Livewire::test(FicheDuClient::class, ['record' => $avecMagasin->getRouteKey()])->assertActionVisible('graphiques');

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->actingAs($proprietaireDuCabinet);
        $this->get(ClientDuCabinetResource::getUrl('graphiques', ['record' => $sansMagasin], panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSee(GraphiquesDuClient::SANS_MAGASIN)
            ->assertDontSeeLivewire(GrilleDesGraphiques::NOM);
        $this->get(ClientDuCabinetResource::getUrl('graphiques', ['record' => $avecMagasin], panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSeeLivewire(GrilleDesGraphiques::NOM);
    }

    /**
     * Les trois pages qui montent la grille.
     *
     * @return array<string, array{0: string}>
     */
    public static function pagesDeLaGrille(): array
    {
        return [
            'espace de la société' => ['societe'],
            'page d\'un client du cabinet' => ['cabinet'],
            'page de l\'expert-comptable déclaré' => ['expert-comptable'],
        ];
    }

    /**
     * La grille se charge en différé sur les trois pages qui la montent
     * (l'espace de la société, la page d'un client du cabinet, celle de
     * l'expert-comptable déclaré) : la page s'ouvre sans ses calculs, ni
     * chiffres clés ni filtres dans sa première réponse, et la grille vient
     * ensuite, quand le navigateur la demande.
     */
    #[DataProvider('pagesDeLaGrille')]
    public function test_la_grille_se_charge_en_differe_sur_les_pages_qui_la_montent(string $page): void
    {
        [$lyon] = $this->deuxMagasins();
        [$compte, $adresse] = match ($page) {
            'societe' => [$this->proprietaireDe($lyon), TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe)],
            'cabinet' => [$collaborateur = $this->collaborateurDuCabinetLie($lyon), ClientDuCabinetResource::getUrl('graphiques', ['record' => $this->lienActif($lyon->societe)], panel: 'cabinet', tenant: $collaborateur->societe()->firstOrFail())],
            default => [$this->expertComptable($lyon), TableauDeBordDeLaSociete::getUrl(panel: 'app', tenant: $lyon)],
        };
        app(TenantContext::class)->clear();

        $this->actingAs($compte);
        $reponse = $this->get($adresse)->assertOk()->assertSeeLivewire(GrilleDesGraphiques::NOM);

        $this->assertStringContainsString('__lazyLoad', (string) $reponse->getContent());
        $this->assertStringNotContainsString('CA HT du mois', (string) $reponse->getContent());
        $this->assertStringNotContainsString('piliqi-graphiques__filtres', (string) $reponse->getContent());
    }

    /**
     * Lyon et Villeurbanne, deux magasins réels de la même société, le
     * 15 octobre 2026 ; aucun contexte n'est actif ensuite.
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function deuxMagasins(): array
    {
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();

        return [$lyon->fresh(), $villeurbanne->fresh()];
    }

    private function lienActif(Societe $client): LienCabinet
    {
        return LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->getKey())->where('etat', EtatDuLien::Actif->value)->firstOrFail();
    }

    private function changerLeStatut(Societe $societe, StatutSociete $statut): void
    {
        app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill(['statut' => $statut])->save());
        app(TenantContext::class)->clear();
    }
}
