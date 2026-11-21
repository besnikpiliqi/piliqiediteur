<?php

use App\Domain\Demonstration\Bases\RolesDeLaDemonstration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les droits du rôle des pages sur le registre (lot DEMO-COPIE, C.1,
 * choix 33) : demo_app lit tout le registre et n'écrit que les témoins,
 * les nonces des messages reçus, les travaux et le cache, et n'inscrit une
 * demande de copie que par la fonction inscrire_une_copie() ; seul
 * demo_admin, propriétaire du registre, qui lance ces migrations, écrit
 * les modèles, change et supprime les copies et les liens, et écrit les
 * archives.
 */
return new class extends Migration
{
    public function up(): void
    {
        RolesDeLaDemonstration::accorderLesDroitsDuRegistre(DB::connection());
    }

    public function down(): void
    {
        RolesDeLaDemonstration::retirerLesDroitsDuRegistre(DB::connection());
    }
};
