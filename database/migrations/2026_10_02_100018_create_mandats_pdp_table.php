<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandats_pdp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->unique()->constrained('magasins')->cascadeOnDelete();
            $table->string('prestataire_pdp', 60);
            $table->string('mandat_externe_id')->nullable();
            $table->string('statut', 20)->default('pending'); // pending, active, revoked, rejected
            $table->string('perimetre', 20)->default('both'); // send, receive, both
            $table->foreignId('accorde_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consentement_ip', 45)->nullable();
            $table->string('consentement_user_agent', 255)->nullable();
            $table->timestamp('consentement_a')->nullable();
            $table->timestamp('active_a')->nullable();
            $table->timestamp('revoque_a')->nullable();
            $table->json('reponse_brute')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandats_pdp');
    }
};
