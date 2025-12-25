<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            if (Schema::hasColumn('eventos', 'titulo')) {
                $table->dropColumn('titulo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            if (! Schema::hasColumn('eventos', 'titulo')) {
                // recria a coluna (sem dados históricos)
                $table->string('titulo')->nullable()->after('ends_at');
            }
        });
    }
};
