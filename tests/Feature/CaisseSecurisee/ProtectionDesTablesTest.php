<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chaque table que sa migration protège (voir DeclencheursDeProtection)
 * refuse elle-même, même par une requête qui ne passe pas par le modèle,
 * avec le message de sa protection : d'être vidée, même par le
 * TRUNCATE ... CASCADE de Laravel, et de modifier ou d'effacer une de ses
 * lignes, selon sa protection. La migration a donc posé chacun de ses
 * déclencheurs. Ce que fait chaque protection d'une ligne expirée se
 * vérifie sur une table de test (DeclencheursDeProtectionTest) et dans les
 * tests de chaque table.
 */
class ProtectionDesTablesTest extends TestCase
{
    use RefreshDatabase;

    private const EN_AJOUT_SEUL = ['changements_fin_exercice', 'consultations_vies', 'preuves_operation', 'bordereaux_detaxe', 'visas_detaxe', 'recus_paiement', 'paiements', 'paiement_allocations', 'lignes_recu_paiement', 'avoir_allocations', 'enregistrements_fiscaux', 'clotures_caisse', 'clotures_caisse_personnelles', 'clotures_caisse_personnelles_factures', 'clotures_caisse_personnelles_paiements', 'mouvements_stock', 'reponses_des_devis'];

    private const SANS_SUPPRESSION = ['societes', 'magasins', 'sirets_magasins', 'periodes_activite', 'tetes_journal_audit', 'pieces_verification', 'cabinets', 'liens_cabinet'];

    private const EFFACEES_APRES_EXPIRATION = ['journal_audit', 'traces_audit', 'verifications_societe', 'traces_evenements'];

    private const EFFACEES_APRES_CONSERVATION = ['codes_confirmation'];

    /**
     * Chaque table protégée par sa migration, et son refus du vidage.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tablesProtegees(): array
    {
        $sansEffacementPermis = [...self::SANS_SUPPRESSION, ...self::EFFACEES_APRES_EXPIRATION, ...self::EFFACEES_APRES_CONSERVATION];

        return [
            ...array_combine(self::EN_AJOUT_SEUL, array_map(static fn (string $table): array => [$table, "{$table} : table en ajout seul, vidage refusé"], self::EN_AJOUT_SEUL)),
            ...array_combine($sansEffacementPermis, array_map(static fn (string $table): array => [$table, "{$table} : vidage refusé"], $sansEffacementPermis)),
        ];
    }

    #[DataProvider('tablesProtegees')]
    public function test_chaque_table_protegee_refuse_d_etre_videe(string $table, string $refus): void
    {
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->truncate()), QueryException::class, $refus);
    }

    /**
     * Une ligne réelle de chaque table protégée, que sa protection garde
     * (aucune n'a expiré à l'horloge du serveur de base, aucune n'a de fin
     * de conservation) : une table en ajout seul refuse de la modifier et
     * de l'effacer ; une table sans suppression la laisse modifier et
     * refuse de l'effacer ; une table à effacement après expiration refuse
     * de la modifier et de l'effacer avant son expiration ; une table à
     * suppression après conservation la laisse modifier et refuse de
     * l'effacer avant la fin de sa conservation. Une modification qui ne
     * change rien suffit : la base refuse toute modification d'une ligne
     * d'une table qui les refuse. Chaque ligne reste telle qu'elle était.
     */
    public function test_chaque_table_protegee_refuse_selon_sa_protection_de_modifier_ou_d_effacer_une_ligne(): void
    {
        $lignes = $this->uneLigneDeChaqueTableProtegee();
        $avant = array_map(static fn (array $ligne): array => (array) DB::table($ligne[0])->where($ligne[1], $ligne[2])->sole(), $lignes);
        $refus = [];

        foreach ($lignes as [$table, $cle, $valeur]) {
            $refus[$table] = [
                'modification' => $this->refusDe(fn (): int => DB::table($table)->where($cle, $valeur)->update([$cle => DB::raw($cle)])),
                'suppression' => $this->refusDe(fn (): int => DB::table($table)->where($cle, $valeur)->delete()),
            ];
        }

        $this->assertSame([
            ...array_combine(self::EN_AJOUT_SEUL, array_map(static fn (string $table): array => ['modification' => "{$table} : table en ajout seul, modification refusée", 'suppression' => "{$table} : table en ajout seul, suppression refusée"], self::EN_AJOUT_SEUL)),
            ...array_combine(self::SANS_SUPPRESSION, array_map(static fn (string $table): array => ['modification' => null, 'suppression' => "{$table} : suppression définitive refusée"], self::SANS_SUPPRESSION)),
            ...array_combine(self::EFFACEES_APRES_EXPIRATION, array_map(static fn (string $table): array => ['modification' => "{$table} : modification refusée", 'suppression' => "{$table} : suppression refusée avant expiration"], self::EFFACEES_APRES_EXPIRATION)),
            ...array_combine(self::EFFACEES_APRES_CONSERVATION, array_map(static fn (string $table): array => ['modification' => null, 'suppression' => "{$table} : suppression refusée avant la fin de la conservation"], self::EFFACEES_APRES_CONSERVATION)),
        ], $refus);
        $this->assertSame($avant, array_map(static fn (array $ligne): array => (array) DB::table($ligne[0])->where($ligne[1], $ligne[2])->sole(), $lignes));
    }

    /**
     * Le message du refus de la base (la ligne du message de PostgreSQL),
     * null si l'écriture passe, dans un point de sauvegarde.
     *
     * @param  Closure(): int  $ecriture
     */
    private function refusDe(Closure $ecriture): ?string
    {
        try {
            $this->dansUnPointDeSauvegarde($ecriture)();
        } catch (QueryException $refus) {
            return preg_match('/ERROR:\s+(.+)/', $refus->getMessage(), $message) === 1 ? $message[1] : $refus->getMessage();
        }

        return null;
    }

    /**
     * Une société validée et son magasin (sa période d'activité, son SIRET
     * réservé, son journal d'audit et ses vérifications), un cabinet lié
     * (sa vérification, son code de courrier et son lien), une facture
     * réglée en partie après son émission puis corrigée par un avoir (le
     * paiement, son reçu et leurs lignes, les imputations, le registre
     * fiscal), un achat validé (son mouvement du grand livre du stock), et,
     * écrites directement, les lignes qu'aucune action ne fait
     * naître sans procédure de l'éditeur, sans fournisseur externe ou sans
     * requête d'un navigateur : une pièce de vérification déposée, un
     * changement légal de fin d'exercice, une consultation VIES, une preuve
     * d'exportation, un bordereau de détaxe et les traces techniques, qui
     * expirent 365 jours plus tard à l'horloge du serveur de base. Pour
     * chaque table, une de ses lignes : la table, sa colonne clé et sa
     * valeur.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    private function uneLigneDeChaqueTableProtegee(): array
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $societe = (int) $magasin->societe_id;
        $cabinet = $this->ouvrirUnCabinet();
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->set($magasin);
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $recu = Produit::factory()->create(['suivi_stock' => true]);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $recu->id, 'quantite' => 2, 'prix_unitaire_ht' => 2]], ['date_facture' => '2026-10-08']));
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 3]]));
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => Calendrier::deParis()->aujourdhui()], [['facture_id' => $facture->id, 'montant' => '20.00']]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);
        $releve = app(FermerSaCaisseAction::class)->handle(self::auteur(), self::auteur());
        $ticketZ = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());
        $premiere = static fn (string $table, string $colonne, int $valeur): int => (int) DB::table($table)->where($colonne, $valeur)->min('id');
        $entreeAudit = (int) DB::table('journal_audit')->where('societe_id', $societe)->whereNull('expire_le')->min('id');
        $enregistrement = $premiere('enregistrements_fiscaux', 'magasin_id', $magasin->id);
        $dans365Jours = $this->maintenantSelonLaBase()->addHours(24 * 365);
        DB::table('traces_audit')->insert(['entree_audit_id' => $entreeAudit, 'societe_id' => $societe, 'ip' => '192.0.2.10', 'agent_utilisateur' => 'Navigateur de test', 'expire_le' => $dans365Jours, 'created_at' => now()]);
        DB::table('traces_evenements')->insert(['enregistrement_fiscal_id' => $enregistrement, 'magasin_id' => $magasin->id, 'ip' => '192.0.2.10', 'agent_utilisateur' => 'Navigateur de test', 'expire_le' => $dans365Jours, 'created_at' => now()]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
        $envoi = DB::table('envois_documents')->insertGetId(['magasin_id' => $magasin->id, 'document_type' => 'devis', 'document_id' => $devis->id, 'destinataire' => 'client@example.test', 'objet' => 'Devis', 'nature' => 'envoi', 'statut' => 'envoye', 'created_at' => now(), 'updated_at' => now()]);
        $lienDuDevis = DB::table('liens_des_devis')->insertGetId(['magasin_id' => $magasin->id, 'devis_id' => $devis->id, 'envoi_document_id' => $envoi, 'jeton_hache' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);

        return [
            'changements_fin_exercice' => ['changements_fin_exercice', 'id', DB::table('changements_fin_exercice')->insertGetId(['societe_id' => $societe, 'ancien_mois' => 12, 'nouveau_mois' => 6, 'debut_exercice_en_cours' => '2026-01-01', 'fin_exercice_en_cours_avant' => '2026-12-31', 'fin_exercice_en_cours_apres' => '2026-12-31', 'decision_du' => '2026-10-01', 'organe_de_la_decision' => 'assemblee_generale_extraordinaire', 'piece_reference' => 'PV de l\'assemblée du 01/10/2026', 'justification' => 'Changement légal de la date de clôture', 'operateur' => json_encode(['operateurs' => ['alice']]), 'created_at' => now()])],
            'consultations_vies' => ['consultations_vies', 'id', DB::table('consultations_vies')->insertGetId(['magasin_id' => $magasin->id, 'client_id' => $client->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'consultee_le' => now(), 'reponse' => '{}'])],
            'preuves_operation' => ['preuves_operation', 'id', DB::table('preuves_operation')->insertGetId(['magasin_id' => $magasin->id, 'facture_id' => $facture->id, 'type' => TypePreuve::Mrn->value, 'reference' => '26FR00000000000001', 'created_at' => now()])],
            'bordereaux_detaxe' => ['bordereaux_detaxe', 'id', $bordereau = DB::table('bordereaux_detaxe')->insertGetId(['magasin_id' => $magasin->id, 'facture_id' => $facture->id, 'numero' => 'PABLO-000001', 'created_at' => now()])],
            'visas_detaxe' => ['visas_detaxe', 'id', DB::table('visas_detaxe')->insertGetId(['magasin_id' => $magasin->id, 'bordereau_detaxe_id' => $bordereau, 'date_visa' => '2026-10-10', 'created_at' => now()])],
            'recus_paiement' => ['recus_paiement', 'id', $premiere('recus_paiement', 'magasin_id', $magasin->id)],
            'paiements' => ['paiements', 'id', $premiere('paiements', 'magasin_id', $magasin->id)],
            'paiement_allocations' => ['paiement_allocations', 'id', $premiere('paiement_allocations', 'magasin_id', $magasin->id)],
            'lignes_recu_paiement' => ['lignes_recu_paiement', 'id', $premiere('lignes_recu_paiement', 'magasin_id', $magasin->id)],
            'avoir_allocations' => ['avoir_allocations', 'id', $premiere('avoir_allocations', 'magasin_id', $magasin->id)],
            'enregistrements_fiscaux' => ['enregistrements_fiscaux', 'id', $enregistrement],
            'clotures_caisse' => ['clotures_caisse', 'id', (int) $ticketZ->id],
            'clotures_caisse_personnelles' => ['clotures_caisse_personnelles', 'id', (int) $releve->id],
            'clotures_caisse_personnelles_factures' => ['clotures_caisse_personnelles_factures', 'facture_id', (int) $facture->id],
            'clotures_caisse_personnelles_paiements' => ['clotures_caisse_personnelles_paiements', 'paiement_id', $premiere('paiements', 'magasin_id', $magasin->id)],
            'mouvements_stock' => ['mouvements_stock', 'id', $premiere('mouvements_stock', 'magasin_id', $magasin->id)],
            'reponses_des_devis' => ['reponses_des_devis', 'id', DB::table('reponses_des_devis')->insertGetId(['magasin_id' => $magasin->id, 'devis_id' => $devis->id, 'lien_du_devis_id' => $lienDuDevis, 'reponse' => 'acceptation', 'signataire' => 'Claire Martin', 'empreinte_du_devis' => str_repeat('d', 64), 'created_at' => now()])],
            'societes' => ['societes', 'id', $societe],
            'magasins' => ['magasins', 'id', (int) $magasin->id],
            'sirets_magasins' => ['sirets_magasins', 'id', $premiere('sirets_magasins', 'magasin_id', $magasin->id)],
            'periodes_activite' => ['periodes_activite', 'id', $premiere('periodes_activite', 'magasin_id', $magasin->id)],
            'tetes_journal_audit' => ['tetes_journal_audit', 'id', $premiere('tetes_journal_audit', 'societe_id', $societe)],
            'pieces_verification' => ['pieces_verification', 'id', DB::table('pieces_verification')->insertGetId(['societe_id' => $societe, 'type' => TypePiece::Identite->value, 'nom' => 'identite.pdf', 'type_mime' => 'application/pdf', 'taille' => 1, 'empreinte' => str_repeat('a', 64), 'analysee_le' => now(), 'deposee_par' => $proprietaire->id, 'deposee_par_nom' => $proprietaire->name, 'deposee_le' => now(), 'created_at' => now(), 'updated_at' => now()])],
            'cabinets' => ['cabinets', 'id', $premiere('cabinets', 'societe_id', $cabinet->id)],
            'liens_cabinet' => ['liens_cabinet', 'id', (int) $lien->id],
            'journal_audit' => ['journal_audit', 'id', $entreeAudit],
            'traces_audit' => ['traces_audit', 'entree_audit_id', $entreeAudit],
            'verifications_societe' => ['verifications_societe', 'id', $premiere('verifications_societe', 'societe_id', $societe)],
            'traces_evenements' => ['traces_evenements', 'enregistrement_fiscal_id', $enregistrement],
            'codes_confirmation' => ['codes_confirmation', 'id', $premiere('codes_confirmation', 'societe_id', $cabinet->id)],
        ];
    }
}
