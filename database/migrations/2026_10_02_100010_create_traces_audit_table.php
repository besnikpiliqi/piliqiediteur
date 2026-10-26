<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'adresse IP et le navigateur de la requête qui a fait inscrire une
 * entrée au journal d'audit (voir TraceAudit) : rangés à part, jamais
 * dans l'empreinte de l'entrée, et effacés à expire_le (1 an après, voir
 * config/conservation.php et app:purger-traces). La base refuse toute
 * modification, tout vidage et toute suppression avant expiration (voir
 * DeclencheursDeProtection::effacementApresExpiration()). Une entrée
 * inscrite par une commande ou un job, sans visiteur, n'a pas de trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traces_audit', function (Blueprint $table) {
            $table->foreignId('entree_audit_id')->primary()->constrained('journal_audit')->restrictOnDelete();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('agent_utilisateur', 255)->nullable();
            $table->dateTime('expire_le');
            $table->timestamp('created_at');

            $table->index(['societe_id', 'expire_le']);
        });

        DeclencheursDeProtection::effacementApresExpiration('traces_audit');
    }

    public function down(): void
    {
        Schema::dropIfExists('traces_audit');
        DeclencheursDeProtection::retirer('traces_audit');
    }
};
