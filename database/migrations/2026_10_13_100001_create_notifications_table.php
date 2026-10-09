<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications en base de Laravel, affichées dans la cloche du panneau
 * (voir AppPanelProvider::databaseNotifications()). Elles appartiennent à
 * un utilisateur, qui peut travailler pour plusieurs magasins : chacune
 * nomme son magasin et retient son identifiant dans `data` (voir
 * NotificationDuMagasin), pour être effacée quand l'utilisateur quitte ce
 * magasin (voir RetirerMembreMagasinAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
