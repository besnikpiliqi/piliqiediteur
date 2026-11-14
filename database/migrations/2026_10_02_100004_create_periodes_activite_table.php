<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les périodes d'activité d'un magasin (voir PeriodeActivite et
 * PeriodesDActivite) : de la validation de sa société, de sa création
 * dans une société validée ou de sa réouverture, à sa fermeture, dont le
 * motif dit si elle est temporaire, pour cessation ou pour cession. Les
 * tickets Z ne se produisent que pour les jours d'une période (voir
 * CloturerLesJourneesAction), et l'abonnement d'un magasin ne court que
 * sur elles. Une période ne se rouvre pas et ne s'efface pas : seule sa
 * fin se pose, une fois ; une réouverture en ouvre une nouvelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodes_activite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->timestamp('debut');
            $table->timestamp('fin')->nullable();
            // MotifFermeture.
            $table->string('motif_fin', 30)->nullable();
            // null : le système.
            $table->foreignId('ouverte_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('ouverte_par_nom')->nullable();
            $table->foreignId('fermee_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('fermee_par_nom')->nullable();
            $table->timestamps();

            $table->index(['magasin_id', 'debut']);
        });

        // Une seule période ouverte par magasin.
        DB::statement('CREATE UNIQUE INDEX periodes_activite_une_ouverte ON periodes_activite (magasin_id) WHERE fin IS NULL');

        DeclencheursDeProtection::sansSuppression('periodes_activite');
    }

    public function down(): void
    {
        Schema::dropIfExists('periodes_activite');
        DeclencheursDeProtection::retirer('periodes_activite');
    }
};
