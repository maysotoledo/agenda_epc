<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            // Garante: não pode existir 2 eventos ATIVOS (deleted_at = null) no mesmo user_id + starts_at
            // E permite reagendar após cancelar (deleted_at preenchido).
            $table->unique(['user_id', 'starts_at', 'deleted_at'], 'eventos_user_starts_deleted_unique');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropUnique('eventos_user_starts_deleted_unique');
        });
    }
};
