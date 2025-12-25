<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copia titulo -> intimado somente quando intimado estiver nulo
        DB::table('eventos')
            ->whereNull('intimado')
            ->whereNotNull('titulo')
            ->update([
                'intimado' => DB::raw('titulo'),
            ]);
    }

    public function down(): void
    {
        // Reversão best-effort:
        // Se intimado for igual ao titulo, limpa intimado.
        DB::table('eventos')
            ->whereNotNull('intimado')
            ->whereNotNull('titulo')
            ->whereRaw('intimado = titulo')
            ->update([
                'intimado' => null,
            ]);
    }
};
