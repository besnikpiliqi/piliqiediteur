<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\CategorieAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal d'audit des sociétés (voir EntreeAudit et JournalAudit), en
 * ajout seul : chaque entrée suit la précédente de sa chaîne, celle d'un
 * magasin (magasin_id) ou de la société (magasin_id nul), dans sa
 * catégorie, et en signe l'empreinte par HMAC avec la clé dédiée du
 * journal (cle_id). Une entrée ne se modifie jamais et ne s'efface qu'une
 * fois sa durée de conservation passée (expire_le), ou, pour une entrée de
 * la vérification de la société, sans expire_le, une fois passée la fin
 * de la conservation que la fin de la relation fixe à sa société
 * (societes.verification_conservee_jusqu_au), par le début de sa chaîne
 * (voir app:purger-journal-audit) ; la base refuse tout le reste (voir
 * DeclencheursDeProtection::effacementApresExpirationOuFinDeLaRelation()).
 * L'adresse IP et le navigateur sont rangés à part (traces_audit).
 *
 * expire_le et created_at sont des instants en UTC (cast Instant) :
 * l'empreinte les signe, et les déclencheurs comparent expire_le à
 * l'heure UTC du serveur de base de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('magasin_id')->nullable();
            // CategorieAudit.
            $table->string('categorie', 30);
            $table->unsignedBigInteger('sequence');
            // membre, editeur, systeme ou autre_societe (le geste d'une
            // personne d'une autre société sur une demande de lien avec un
            // cabinet, inscrit sans rien d'elle, voir JournalAudit).
            $table->string('origine', 20);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('auteur_libelle', 255)->nullable();
            // ActionAuditee.
            $table->string('action', 60);
            $table->string('cible_type', 60)->nullable();
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->json('anciennes_valeurs')->nullable();
            $table->json('nouvelles_valeurs')->nullable();
            // Celles d'une procédure de l'éditeur, au journal de la société
            // qu'elle vise ; nulles au journal de l'autre partie d'un lien
            // avec un cabinet, qui n'en garde que l'origine (voir
            // JournalAudit).
            $table->text('justification')->nullable();
            $table->json('operateur')->nullable();
            $table->string('cle_id', 40);
            $table->char('empreinte', 64);
            $table->dateTime('expire_le')->nullable();
            $table->dateTime('created_at');

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
            $table->index(['societe_id', 'created_at']);
            // Les entrées d'une action dans le journal d'une société : la
            // dernière décision d'examen de son SIREN se relit à chaque
            // fiche d'une facture à un professionnel (voir
            // RegularisationDUneFacture::estDesignee()), sans parcourir tout
            // son journal.
            $table->index(['societe_id', 'action']);
            $table->index(['cible_type', 'cible_id']);
        });

        DB::statement('CREATE UNIQUE INDEX journal_audit_sequence_magasin ON journal_audit (magasin_id, categorie, sequence) WHERE magasin_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX journal_audit_sequence_societe ON journal_audit (societe_id, categorie, sequence) WHERE magasin_id IS NULL');

        DeclencheursDeProtection::effacementApresExpirationOuFinDeLaRelation('journal_audit', CategorieAudit::Verification->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_audit');
        DeclencheursDeProtection::retirer('journal_audit');
    }
};
