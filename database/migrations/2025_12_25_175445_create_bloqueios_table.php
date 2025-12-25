<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueios', function (Blueprint $table) {
            $table->id();

            // EPC bloqueado
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Dia bloqueado (somente data)
            $table->date('dia');

            // Motivo opcional
            $table->string('motivo')->nullable();

            // Quem criou (admin)
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Evita duplicidade de bloqueio no mesmo dia para o mesmo EPC
            $table->unique(['user_id', 'dia']);
            $table->index(['user_id', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueios');
    }
};
