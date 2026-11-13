<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\FicheDuClient;
use App\Models\User;
use Closure;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Les magasins qu'un client ouvre à son cabinet lié (décision de l'éditeur
 * du 11/11/2026, § 5 ter) : tous ses magasins réels, présents et futurs, par
 * défaut, ou une sélection d'au moins un magasin, réglée avec les
 * autorisations, les mêmes dans chaque magasin choisi. Un magasin non
 * choisi n'est ni listé ni ouvert, et un compte du cabinet le perd dès sa
 * requête suivante ; chaque changement s'inscrit au JET 130 de chaque
 * magasin ajouté ou retiré et aux deux journaux d'audit ; la base refuse
 * une sélection vide, la démonstration et une sélection sur un lien qui
 * ouvre tous les magasins.
 */
class MagasinsOuvertsAuCabinetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un client qui ne choisit que « Lyon 1 » : le cabinet ouvre ce
     * magasin, ni « Lyon 2 » ni un magasin créé ensuite ; revenir à tous les
     * magasins, présents et futurs, les lui rouvre tous.
     */
    public function test_un_magasin_non_choisi_n_est_ni_liste_ni_ouvert_et_tous_les_magasins_couvre_les_futurs(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$lyon1]);

        $this->regler($lien, [$lyon1->id]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();
        $this->assertSame([], collect($this->modificationsDesDroits($villeurbanne))->where('membre.id', $this->proprietaireDuCabinet($cabinet)->id)->all());

        foreach ([$this->proprietaireDuCabinet($cabinet), $collaborateur] as $compte) {
            $compte = $compte->fresh();
            $this->assertTrue($compte->peutOuvrir($lyon1), $compte->name);
            $this->assertFalse($compte->peutOuvrir($lyon2), $compte->name);
            $this->assertFalse($compte->peutOuvrir($villeurbanne), $compte->name);
            $this->assertSame(['Lyon 1'], $compte->magasinsAccessibles()->pluck('nom')->all());
            $this->assertNull($compte->droitsDans($lyon2));
            $this->assertNotNull($compte->droitsDans($lyon1));
        }

        $this->regler($lien, null);
        $this->assertSame(['Lyon 1', 'Lyon 2', 'Villeurbanne'], $collaborateur->fresh()->magasinsAccessibles()->pluck('nom')->all());
        $this->assertTrue($lien->fresh()->tous_les_magasins);
        $this->assertSame([], $lien->fresh()->magasinsChoisis());
    }

    /**
     * Le tableau de bord et la fiche du client, dans l'espace du cabinet, ne
     * comptent ni ne listent un magasin non choisi, ni ses factures à
     * encaisser.
     */
    public function test_le_tableau_de_bord_et_la_fiche_du_client_ignorent_un_magasin_non_choisi(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $this->factureAEncaisser($lyon1, '120.00');
        $this->factureAEncaisser($lyon2, '80.00');
        $this->regler($lien, [$lyon1->id]);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        [$ligne] = app(TableauDeBordDesClients::class)->pour($proprietaire);
        $this->assertSame(1, $ligne['magasins']);
        $this->assertSame([1, 12000], [$ligne['factures']['nombre'], $ligne['factures']['restant_du']]);

        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        $this->assertSame(['Lyon 1'], array_column(Livewire::test(FicheDuClient::class, ['record' => $lien->uuid])->instance()->magasins(), 'nom'));
    }

    /**
     * Retirer « Lyon 2 » de la sélection ferme son accès au compte déjà
     * ouvert dès sa requête suivante ; le JET 130 de « Lyon 2 » inscrit ses
     * droits qui prennent fin, celui de « Lyon 1 » ne change pas ; les deux
     * journaux d'audit retiennent les magasins ouverts et les autorisations.
     */
    public function test_retirer_un_magasin_ferme_son_acces_et_s_inscrit_au_jet_130_et_aux_deux_journaux(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLeMagasin($lyon2, $proprietaire);
        $this->get(route('filament.app.pages.dashboard', ['tenant' => $lyon2]))->assertOk();
        $jet130 = [count($this->modificationsDesDroits($lyon1)), count($this->modificationsDesDroits($lyon2))];
        app(TenantContext::class)->clear();

        $this->regler($lien, [$lyon1->id]);

        $this->get(route('filament.app.pages.dashboard', ['tenant' => $lyon2]))->assertNotFound();
        $this->assertCount($jet130[0], $this->modificationsDesDroits($lyon1));
        $dernier = collect($this->modificationsDesDroits($lyon2))->last();
        $this->assertSame($proprietaire->id, $dernier['membre']['id']);
        $this->assertNotNull($dernier['avant']);
        $this->assertNull($dernier['apres']);
        $this->assertCount($jet130[1] + 1, $this->modificationsDesDroits($lyon2));

        $attendu = [['id' => $lyon1->id, 'nom' => 'Lyon 1']];
        foreach ([$lyon1->societe, $cabinet] as $journal) {
            $entree = collect($this->entreesDAudit($journal, ActionAuditee::AutorisationsDuCabinetModifiees))->last();
            $this->assertSame($attendu, $entree->nouvelles_valeurs['magasins'], (string) $journal->raison_sociale);
            $this->assertSame($lien->fresh()->autorisations(), $entree->nouvelles_valeurs['autorisations']);
        }
    }

    /**
     * Une sélection vide, un magasin d'une autre société ou le magasin de
     * démonstration sont refusés, sans rien changer ; le propriétaire du
     * client qui accepte une demande de rattachement en ajustant choisit
     * aussi ses magasins.
     */
    public function test_une_selection_vide_un_magasin_d_une_autre_societe_ou_la_demonstration_sont_refuses(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        [$lyon1, $demonstration] = $this->societeAvecSaDemonstration();
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre']);

        foreach ([[[], LienCabinet::MESSAGE_SELECTION_VIDE], [[$autre->id], 'Seuls les magasins réels de votre société s\'ouvrent à votre cabinet.'], [[$demonstration->id], 'Seuls les magasins réels de votre société s\'ouvrent à votre cabinet.']] as [$magasins, $message]) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => $this->regler($lien, $magasins), InvalidArgumentException::class, $message);
        }

        $this->assertTrue($lien->fresh()->tous_les_magasins);

        $inscrite = $this->ouvrirUneSociete(['nom' => 'Inscrite']);
        $this->ouvrirUnAutreMagasin($inscrite, ['nom' => 'Inscrite 2']);
        $this->demanderLeRattachement($this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux'), $inscrite->societe, 'dirigeant@inscrite.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $inscrite->societe_id)->sole();
        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDe($inscrite), $demande, null, [$inscrite->id]);
        $this->assertSame([false, [$inscrite->id]], [$demande->fresh()->tous_les_magasins, $demande->fresh()->magasinsChoisis()]);
    }

    /**
     * La base refuse une ligne de sélection du magasin de démonstration, ou
     * sur un lien qui ouvre tous les magasins, et, à la validation de la
     * transaction, un lien actif qui n'ouvre ni tous les magasins ni aucun.
     */
    public function test_la_base_refuse_une_selection_incoherente(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        [$lyon1, $demonstration] = $this->societeAvecSaDemonstration();
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $ligne = fn (int $magasin): array => ['societe_id' => $lyon1->societe_id, 'lien_id' => $lien->id, 'magasin_id' => $magasin];
        $aLaValidation = fn (Closure $ecriture): Closure => $this->dansUnPointDeSauvegarde(function () use ($ecriture): void {
            $ecriture();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins_cabinet')->insert($ligne($lyon1->id))), QueryException::class, GardesDesCabinets::MAGASINS.' : un lien qui ouvre tous les magasins n\'a pas de sélection');
        $this->assertThrows($aLaValidation(fn () => DB::table('liens_cabinet')->where('id', $lien->id)->update(['tous_les_magasins' => false])), QueryException::class, GardesDesCabinets::SELECTION.' : un lien actif ou une déclaration ouvre tous les magasins du client, ou une sélection d\'au moins un magasin');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('liens_cabinet')->where('id', $lien->id)->update(['tous_les_magasins' => false]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins_cabinet')->insert($ligne($demonstration->id))), QueryException::class, GardesDesCabinets::MAGASINS.' : le magasin de démonstration ne s\'ouvre jamais à un cabinet');
        DB::table('magasins_cabinet')->insert($ligne($lyon1->id));
        $this->assertThrows($aLaValidation(fn () => DB::table('magasins_cabinet')->where('lien_id', $lien->id)->delete()), QueryException::class, GardesDesCabinets::SELECTION);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame([$lyon1->id], $lien->fresh()->magasinsChoisis());
    }

    /**
     * La base refuse qu'un compte d'un cabinet lié ou un expert-comptable de
     * la société agisse dans un magasin que le client ne lui ouvre pas
     * (§ 5 ter, point 6) : saisie d'un inventaire d'ouverture, validation,
     * téléchargement des documents par période ; seule la colonne changée
     * se contrôle, si bien que le propriétaire valide encore l'inventaire
     * saisi par le cabinet dans un magasin que le client lui a retiré.
     */
    public function test_la_base_refuse_une_autorisation_du_cabinet_utilisee_dans_un_magasin_non_choisi(): void
    {
        $this->travelTo(self::aParis('2026-03-09 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$lyon1]);
        $this->regler($lien, [$lyon1->id]);
        $proprietaire = $this->proprietaireDe($lyon1);
        $refus = GardesDesCabinets::MAGASINS_OUVERTS.' : un compte d\'un cabinet d\'expertise comptable ou un expert-comptable de la société n\'agit que dans un magasin que le client lui ouvre';
        $inventaire = fn (Magasin $magasin, User $compte): int => DB::table('inventaires_ouverture')->insertGetId(['magasin_id' => $magasin->id, 'cree_par' => $compte->id, 'created_at' => now(), 'updated_at' => now()]);
        $telechargement = fn (User $compte, array $magasins): bool => DB::table('telechargements_documents')->insert([
            'uuid' => (string) Str::uuid(), 'societe_id' => $lyon1->societe_id, 'demande_par' => $compte->id, 'demande_par_nom' => $compte->name,
            'magasins' => json_encode($magasins), 'du' => '2026-03-01', 'au' => '2026-03-09', 'types' => json_encode(['ventes']), 'origine' => 'toutes',
            'piece_manquante_seulement' => false, 'format' => 'zip', 'selection' => json_encode([]), 'statut' => 'en_attente',
            'expire_le' => self::aParis('2026-03-16 10:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ouvre = fn (User $compte, Magasin $magasin): ?bool => DB::selectOne('select compte_ouvre_le_magasin(?, ?) as ouvre', [$compte->id, $magasin->id])->ouvre;

        $dansLyon1 = $inventaire($lyon1, $collaborateur);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $inventaire($lyon2, $collaborateur)), QueryException::class, $refus);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $telechargement($collaborateur, [$lyon1->id, $lyon2->id])), QueryException::class, $refus);
        $this->assertTrue($telechargement($collaborateur, [$lyon1->id]));
        $this->assertSame([true, false, true, true], [$ouvre($collaborateur, $lyon1), $ouvre($collaborateur, $lyon2), $ouvre($this->proprietaireDuCabinet($cabinet), $lyon1), $ouvre($proprietaire, $lyon2)]);

        $this->regler($lien, [$lyon2->id]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('inventaires_ouverture')->where('id', $dansLyon1)->update(['valide_par' => $collaborateur->id])), QueryException::class, $refus);
        DB::table('inventaires_ouverture')->where('id', $dansLyon1)->update(['valide_par' => $proprietaire->id, 'notes' => 'Validé par le propriétaire']);
        $inventaire($lyon2, $collaborateur);

        $this->regler($lien, null);
        $this->assertSame([true, true], [$ouvre($collaborateur, $lyon1), $ouvre($collaborateur, $lyon2)]);

        $paris = $this->ouvrirUneSociete(['nom' => 'Paris 1']);
        $paris2 = $this->ouvrirUnAutreMagasin($paris, ['nom' => 'Paris 2']);
        $marc = $this->declarerSonExpertComptable($paris, SocieteFactory::sirenFictif());
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $paris->societe_id)->sole();
        $this->assertSame([true, true, false], [$ouvre($marc, $paris), $ouvre($marc, $paris2), $ouvre($marc, $lyon1)]);
        $this->regler($declaration, [$paris->id]);
        $this->assertSame([true, false], [$ouvre($marc, $paris), $ouvre($marc, $paris2)]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $inventaire($paris2, $marc)), QueryException::class, $refus);
        $inventaire($paris, $marc);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $inventaire($lyon2, $marc)), QueryException::class, $refus);
    }

    /**
     * La validation d'une facture d'achat n'écrit son auteur que dans le
     * grand livre du stock : la base refuse qu'un expert-comptable de la
     * société ou un compte d'un cabinet lié la valide dans un magasin que
     * le client ne lui ouvre pas, même quand le code oublie de le vérifier
     * (§ 5 ter, point 6) ; rien ne s'écrit, et le propriétaire valide
     * ensuite cet achat.
     */
    public function test_la_base_refuse_la_validation_d_un_achat_dans_un_magasin_non_choisi(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $marc = $this->expertComptable($lyon1, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::FACTURE_ACHAT_VALIDER])->fresh();
        $this->regler(LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon1->societe_id)->sole(), [$lyon1->id]);
        $proprietaire = $this->proprietaireDe($lyon1);
        $refus = GardesDesCabinets::MAGASINS_OUVERTS.' : un compte d\'un cabinet d\'expertise comptable ou un expert-comptable de la société n\'agit que dans un magasin que le client lui ouvre';
        app(TenantContext::class)->set($lyon2);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($proprietaire, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);
        $validerSansControle = fn (User $compte): FactureAchat => app(OperateurCourant::class)->pendant($compte, fn (): FactureAchat => (new ReflectionMethod(ValiderFactureAchatAction::class, 'valider'))->invoke(app(ValiderFactureAchatAction::class), $compte, $factureAchat));

        $this->assertFalse($marc->peutOuvrir($lyon2));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): FactureAchat => $validerSansControle($marc)), QueryException::class, $refus);
        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->fresh()->statut);
        $this->assertSame(0, DB::table('mouvements_stock')->where('cree_par', $marc->id)->count());

        $this->assertSame(StatutFactureAchat::Validee, $validerSansControle($proprietaire)->statut);
        $this->assertSame(1, DB::table('mouvements_stock')->where('magasin_id', $lyon2->id)->where('source_id', $factureAchat->id)->where('cree_par', $proprietaire->id)->count());
        app(TenantContext::class)->clear();
    }

    /**
     * Une fiche de fournisseur nomme qui l'a créée et qui l'a modifiée,
     * archivée ou restaurée en dernier : l'action pose ces colonnes, avec
     * leur nom figé, et la base refuse qu'un expert-comptable de la société
     * en écrive une dans un magasin que le client ne lui ouvre pas, même
     * quand le code oublie de le vérifier (constat 5 du lot EC2) ; rien ne
     * s'écrit, et le propriétaire, lui, y écrit.
     */
    public function test_la_base_refuse_une_fiche_de_fournisseur_ecrite_dans_un_magasin_non_choisi(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $marc = $this->expertComptable($lyon1, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::FOURNISSEUR_CREATE, PermissionRegistry::FOURNISSEUR_UPDATE, PermissionRegistry::FOURNISSEUR_ARCHIVER])->fresh();
        $this->regler(LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon1->societe_id)->sole(), [$lyon1->id]);
        $proprietaire = $this->proprietaireDe($lyon1);
        $refus = GardesDesCabinets::MAGASINS_OUVERTS.' : un compte d\'un cabinet d\'expertise comptable ou un expert-comptable de la société n\'agit que dans un magasin que le client lui ouvre';
        app(TenantContext::class)->set($lyon1);
        $grossiste = app(CreerUneFicheAction::class)->handle($marc, Fournisseur::class, ['nom' => 'Grossiste Lyonnais', 'pays' => 'FR']);
        app(TenantContext::class)->set($lyon2);
        $fiche = app(CreerUneFicheAction::class)->handle($proprietaire, Fournisseur::class, ['nom' => 'Grossiste de Villeurbanne', 'pays' => 'FR']);
        $ecrireSansControle = fn (User $compte): bool => $fiche->fresh()->noterSonAuteur($compte)->fill(['telephone' => '0400000000'])->save();

        $this->assertSame([$marc->id, $marc->id, $marc->nomPourLHistorique(), $marc->nomPourLHistorique()], [$grossiste->cree_par, $grossiste->modifie_par, $grossiste->cree_par_nom, $grossiste->modifie_par_nom]);
        $this->assertStringStartsWith('Marc Petit (expert-comptable déclaré par la société', (string) $grossiste->cree_par_nom);
        $this->assertSame([$proprietaire->id, $proprietaire->id], [$fiche->cree_par, $fiche->modifie_par]);
        $this->assertFalse($marc->peutOuvrir($lyon2));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): bool => $ecrireSansControle($marc)), QueryException::class, $refus);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): bool => DB::table('fournisseurs')->insert(['magasin_id' => $lyon2->id, 'nom' => 'Fiche forgée', 'pays' => 'FR', 'cree_par' => $marc->id, 'modifie_par' => $marc->id, 'created_at' => now(), 'updated_at' => now()])), QueryException::class, $refus);
        $this->assertSame([null, 0], [$fiche->fresh()->telephone, DB::table('fournisseurs')->where('nom', 'Fiche forgée')->count()]);

        $this->assertTrue($ecrireSansControle($proprietaire));
        app(ArchiverUneFicheAction::class)->archiver($proprietaire, $fiche->fresh());
        $this->assertSame(['0400000000', $proprietaire->id], [$fiche->fresh()->telephone, Fournisseur::withTrashed()->whereKey($fiche->id)->value('modifie_par')]);
        app(TenantContext::class)->clear();
    }

    /**
     * Chaque table où un compte agit dans un magasin porte la garde, sur
     * les colonnes qui nomment ce compte.
     */
    public function test_chaque_table_ou_un_compte_agit_dans_un_magasin_porte_la_garde_des_magasins_ouverts(): void
    {
        $declencheurs = collect(DB::select('select c.relname as table_, pg_get_triggerdef(t.oid) as definition from pg_trigger t join pg_class c on c.oid = t.tgrelid where t.tgname = ? and not t.tgisinternal order by c.relname', [GardesDesCabinets::MAGASINS_OUVERTS]))
            ->mapWithKeys(fn (object $declencheur): array => [$declencheur->table_ => $declencheur->definition]);

        $this->assertEqualsCanonicalizing(array_keys(GardesDesCabinets::AUTEURS_DANS_UN_MAGASIN), $declencheurs->keys()->all());

        foreach (GardesDesCabinets::AUTEURS_DANS_UN_MAGASIN as $table => $colonnes) {
            $this->assertStringContainsString('BEFORE INSERT OR UPDATE OF '.implode(', ', $colonnes).' ON public.'.$table, (string) $declencheurs[$table]);
        }
    }

    /**
     * Une facture à un professionnel émise et à encaisser dans ce magasin.
     */
    private function factureAEncaisser(Magasin $magasin, string $montant): Facture
    {
        app(TenantContext::class)->set($magasin);
        $this->completerIdentiteLegale($magasin);
        $vendeur = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => $montant, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->create(['nom' => 'Client professionnel', 'type_client' => TypeClient::Professionnel, 'siren' => '732829320']);
        $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
        app(TenantContext::class)->clear();

        return $facture;
    }

    /**
     * Une société validée, son magasin du siège et son magasin de
     * démonstration, essayé avant la validation, puis fermé par elle.
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function societeAvecSaDemonstration(): array
    {
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe, 'Lyon 1');
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe->fresh());
        $this->saisirLeCodeDuCourrier($societe->fresh());
        app(TenantContext::class)->clear();

        return [$magasin->fresh(), $demonstration];
    }

    /**
     * @param  list<int>|null  $magasins
     */
    private function regler(LienCabinet $lien, ?array $magasins): void
    {
        app(TenantContext::class)->clear();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($lien->client()->firstOrFail()->magasinsReels()->orderBy('id')->firstOrFail()), $lien, $lien->autorisations(), $magasins);
        app(TenantContext::class)->clear();
    }
}
