<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La réponse du client à un devis par son lien secret (voir
 * ReponseDuDevis et RepondreAuDevisEnLigneAction) : une seule par lien
 * (index unique), en ajout seul. L'adresse IP et le navigateur du client
 * n'y sont pas : ils vont dans les traces techniques du journal d'audit et
 * du journal des événements, effacées après un an.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reponses_des_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->unsignedBigInteger('devis_id');
            $table->unsignedBigInteger('lien_du_devis_id')->unique();
            // acceptation ou refus (ReponseAuDevis).
            $table->string('reponse', 20);
            // Le nom que le client a tapé pour accepter, avec « Bon pour
            // accord » coché : vide pour un refus.
            $table->string('signataire', 150)->nullable();
            // Le motif facultatif d'un refus.
            $table->string('motif', 1000)->nullable();
            // La référence d'une acceptation, numérotée dans le magasin
            // comme un document (ACC-LYON1-2026-000001, voir
            // TypeDocument::AcceptationDevis), qu'imprime le cadre « Bon
            // pour accord » du devis accepté : vide pour un refus.
            $table->string('reference', 40)->nullable();
            // L'acceptation d'un particulier : a-t-il demandé que la
            // prestation commence avant la fin de son délai de rétractation
            // de quatorze jours (T86, art. L. 221-25 du code de la
            // consommation) ? Vide pour un professionnel et pour un refus.
            $table->boolean('debut_avant_la_fin_du_delai')->nullable();
            // L'empreinte SHA-256 de la version du devis à laquelle le
            // client a répondu (voir Devis::empreinteDeSaVersion()).
            $table->char('empreinte_du_devis', 64);
            $table->timestamp('created_at');

            $table->unique(['magasin_id', 'id']);
            $table->unique(['magasin_id', 'reference']);
            $table->index(['magasin_id', 'devis_id']);
            $table->foreign(['magasin_id', 'devis_id'])->references(['magasin_id', 'id'])->on('devis')->restrictOnDelete();
            $table->foreign(['magasin_id', 'lien_du_devis_id'])->references(['magasin_id', 'id'])->on('liens_des_devis')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE reponses_des_devis ADD CONSTRAINT reponses_des_devis_reponse CHECK ((reponse = 'acceptation' AND signataire IS NOT NULL AND motif IS NULL AND reference IS NOT NULL) OR (reponse = 'refus' AND signataire IS NULL AND reference IS NULL AND debut_avant_la_fin_du_delai IS NULL))");
        DeclencheursDeProtection::ajoutSeul('reponses_des_devis');
    }

    public function down(): void
    {
        Schema::dropIfExists('reponses_des_devis');
        DeclencheursDeProtection::retirer('reponses_des_devis');
    }
};
