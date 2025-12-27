<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferias', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('inicio');
            $table->date('fim');

            // Ano de referência para aplicar limites por ano
            $table->unsignedSmallInteger('ano');

            $table->timestamps();

            $table->index(['user_id', 'ano']);
            $table->index(['inicio', 'fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferias');
    }
};
