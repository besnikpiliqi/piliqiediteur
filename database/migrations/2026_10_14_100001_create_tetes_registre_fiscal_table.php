<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ligne de verrou du registre fiscal de chaque magasin (voir
 * RegistreFiscal::verrouiller()) : toutes ses écritures fiscales se
 * sérialisent sur elle, et elle tient l'état des chaînes du magasin, qui
 * ne se lit que sur elle, verrouillée. Aucune clé étrangère ne pointe vers
 * cette table : son verrou exclusif ne croise jamais les verrous partagés
 * que posent, sur la ligne du magasin, les insertions dans les tables qui
 * le référencent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tetes_registre_fiscal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->unique()->constrained('magasins')->restrictOnDelete();
            // En UTC (voir HorodatageUtc) : l'instant du dernier verrou du
            // registre du magasin, sous lequel l'horloge ne recule jamais.
            $table->dateTime('dernier_horodatage')->nullable();
            // La tête de chaque chaîne du magasin : la séquence et la
            // signature de son dernier enregistrement, par valeur de
            // ChaineFiscale. Ces trois colonnes restent nulles tant que le
            // magasin n'a aucun enregistrement.
            $table->json('chaines')->nullable();
            // La version du module fiscal et la clé de signature du dernier
            // enregistrement du magasin.
            $table->string('version_module', 10)->nullable();
            $table->char('cle_id', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tetes_registre_fiscal');
    }
};
