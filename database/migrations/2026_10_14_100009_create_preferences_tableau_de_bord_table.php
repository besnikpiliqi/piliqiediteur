<?php

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que chaque compte a choisi sur un écran du tableau de bord (décision
 * de l'éditeur du 14/11/2026, lot GRAPH ; voir PreferencesDuTableauDeBord) :
 * les graphiques affichés, leur ordre et leur type, la période, le magasin
 * et la comparaison avec l'année précédente, en JSON vérifié à chaque
 * lecture et à chaque écriture (voir ReglagesDuTableauDeBord). Une ligne
 * par compte et par écran, dans la société du compte : pour un compte d'un
 * cabinet d'expertise comptable, le cabinet, puisque ce réglage est le sien
 * et ne dit rien du client. La clé étrangère composite garde chaque ligne
 * dans la société de son compte. Jamais dans le navigateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferences_tableau_de_bord', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('ecran', 20);
            $table->jsonb('reglages');
            $table->timestamps();

            $table->unique(['societe_id', 'user_id', 'ecran']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });

        $ecrans = implode(', ', array_map(static fn (EcranDuTableauDeBord $ecran): string => "'{$ecran->value}'", EcranDuTableauDeBord::cases()));
        DB::statement("ALTER TABLE preferences_tableau_de_bord ADD CONSTRAINT preferences_tableau_de_bord_ecran_connu CHECK (ecran IN ({$ecrans}))");
        DB::statement("ALTER TABLE preferences_tableau_de_bord ADD CONSTRAINT preferences_tableau_de_bord_reglages_objet CHECK (jsonb_typeof(reglages) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('preferences_tableau_de_bord');
    }
};
