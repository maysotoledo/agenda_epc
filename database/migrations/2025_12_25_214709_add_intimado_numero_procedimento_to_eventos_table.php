<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            // mantém titulo por compatibilidade, mas passaremos a usar intimado
            $table->string('intimado')->nullable()->after('titulo');
            $table->string('numero_procedimento')->nullable()->after('intimado');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn(['numero_procedimento', 'intimado']);
        });
    }
};
