<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('statut', 20);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // Un compte n'appartient qu'à un seul magasin : l'index refuse
            // un second rattachement.
            $table->unique('user_id');
            $table->index(['magasin_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasin_user');
    }
};
