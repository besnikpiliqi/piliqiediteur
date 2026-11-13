<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les écarts que la revérification du répertoire Sirene relève sur une
 * société validée ou l'un de ses magasins (voir ReverificationSirene) :
 * entreprise cessée, catégorie juridique ou code d'activité différents,
 * établissement fermé ou inconnu, adresse ou territoire différents. Rien
 * ne se bloque ; l'écart reste dans l'« À faire » de l'espace de la
 * société jusqu'à ce que le propriétaire, ou un administrateur qui gère ce
 * magasin, dise « C'est vérifié » (décision de l'éditeur du 01/11/2026,
 * Q-AF13, voir VerifierUnEcartSireneAction). Un écart ne s'inscrit qu'une
 * fois par motif, qu'il soit encore à vérifier ou déjà dit vérifié (index
 * unique) : chaque revérification qui relit le même écart ne le double
 * pas et ne le remet pas dans l'« À faire » ; un écart dont le motif lu
 * change (une autre adresse, un autre territoire) est un nouvel écart.
 *
 * magasin_id : le magasin dont l'établissement est en cause, désigné dans
 * la société de l'écart (clé composée) ; vide pour un écart de
 * l'entreprise. negatif : le répertoire dit l'entreprise cessée, ou
 * l'établissement fermé ou inconnu. verifie_par : le compte qui a dit
 * « C'est vérifié ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecarts_sirene', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('magasin_id')->nullable();
            $table->text('motif');
            $table->boolean('negatif');
            $table->timestamp('signale_le');
            $table->timestamp('verifie_le')->nullable();
            $table->foreignId('verifie_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('verifie_par_nom')->nullable();
            $table->timestamps();

            $table->index(['societe_id', 'verifie_le']);

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
        });

        DB::statement('create unique index ecarts_sirene_motif_unique on ecarts_sirene (societe_id, md5(motif))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ecarts_sirene');
    }
};
