<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tête de chaque chaîne du journal d'audit (voir TeteJournalAudit) : la
 * séquence et l'empreinte de sa dernière entrée, verrouillées en dernier
 * par chaque inscription (voir JournalAudit), et le point de reprise de
 * la chaîne une fois son début effacé à expiration. La vérification
 * compare la dernière entrée à la tête : un effacement des dernières
 * entrées se voit. Une tête ne s'efface pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tetes_journal_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('magasin_id')->nullable();
            $table->string('categorie', 30);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->char('empreinte', 64)->nullable();
            $table->unsignedBigInteger('reprise_sequence')->nullable();
            $table->char('reprise_empreinte', 64)->nullable();
            $table->timestamps();

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX tetes_journal_audit_magasin ON tetes_journal_audit (magasin_id, categorie) WHERE magasin_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tetes_journal_audit_societe ON tetes_journal_audit (societe_id, categorie) WHERE magasin_id IS NULL');

        DeclencheursDeProtection::sansSuppression('tetes_journal_audit');
    }

    public function down(): void
    {
        Schema::dropIfExists('tetes_journal_audit');
        DeclencheursDeProtection::retirer('tetes_journal_audit');
    }
};
