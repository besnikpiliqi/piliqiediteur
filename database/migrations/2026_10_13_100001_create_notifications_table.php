<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications en base de Laravel, affichées dans la cloche du panneau
 * (voir AppPanelProvider::databaseNotifications()). Elles appartiennent à
 * un utilisateur, membre d'un seul magasin : chacune nomme ce magasin et
 * retient son identifiant dans `data` (voir NotificationDuMagasin), pour
 * être effacée quand l'utilisateur est retiré de l'équipe ou suspendu
 * (voir RetirerMembreMagasinAction et MettreAJourMembreMagasinAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // json et non text : la cloche de Filament et
            // User::effacerLesNotificationsDu() filtrent sur ses clés
            // (data->format, data->magasin_id), ce que PostgreSQL ne fait
            // que sur une colonne json.
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
