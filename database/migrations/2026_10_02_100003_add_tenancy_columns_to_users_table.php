<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
            $table->foreignId('current_magasin_id')->nullable()->after('password')
                ->constrained('magasins')->nullOnDelete();
            $table->softDeletes();
        });
    }

    /**
     * L'index unique d'abord : SQLite refuse de supprimer une colonne qu'un
     * index utilise encore.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_magasin_id');
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
            $table->dropSoftDeletes();
        });
    }
};
