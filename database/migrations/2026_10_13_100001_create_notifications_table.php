<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications en base de Laravel, affichées dans la cloche des panneaux
 * (voir AppPanelProvider et SocietePanelProvider). Chacune retient dans
 * `data` la société qu'elle concerne (`societe_id`) et, pour une
 * notification d'un magasin, ce magasin (`magasin_id`, voir
 * NotificationDuMagasin) : elles s'effacent toutes quand le compte quitte
 * sa société, et celles d'un magasin quand il n'y est plus affecté (voir
 * User::effacerToutesSesNotifications() et
 * User::effacerLesNotificationsDesMagasins()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // json et non text : la cloche de Filament et les effacements
            // filtrent sur ses clés (data->format, data->magasin_id), ce
            // que PostgreSQL ne fait que sur une colonne json.
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
