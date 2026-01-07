<?php

namespace App\Services;

use App\Models\Evento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventoService
{
    public function criar(array $data): Evento
    {
        try {
            return DB::transaction(function () use ($data) {
                $userId = auth()->id();

                $data['created_by'] = $userId;
                $data['updated_by'] = $userId;

                return Evento::create($data);
            });
        } catch (QueryException $e) {
            $this->throwIfDuplicateSlot($e);
            throw $e;
        }
    }

    public function editar(Evento $evento, array $data): Evento
    {
        try {
            return DB::transaction(function () use ($evento, $data) {
                $userId = auth()->id();

                $data['updated_by'] = $userId;

                $evento->fill($data);
                $evento->save();

                return $evento->refresh();
            });
        } catch (QueryException $e) {
            $this->throwIfDuplicateSlot($e);
            throw $e;
        }
    }

    /**
     * Cancelamento (soft delete) com auditoria.
     */
    public function cancelar(Evento $evento): void
    {
        DB::transaction(function () use ($evento) {
            $userId = auth()->id();

            $evento->forceFill([
                'updated_by' => $userId,
                'deleted_by' => $userId,
            ])->save();

            $evento->delete(); // SoftDeletes => deleted_at
        });
    }

    /**
     * Restaurar evento cancelado.
     * Se já existir outro evento ativo no mesmo horário, o UNIQUE vai barrar.
     */
    public function restaurar(int $eventoId): Evento
    {
        try {
            return DB::transaction(function () use ($eventoId) {
                $userId = auth()->id();

                $evento = Evento::withTrashed()->findOrFail($eventoId);
                $evento->restore();

                $evento->forceFill([
                    'deleted_by' => null,
                    'updated_by' => $userId,
                ])->save();

                return $evento->refresh();
            });
        } catch (QueryException $e) {
            $this->throwIfDuplicateSlot($e);
            throw $e;
        }
    }

    private function throwIfDuplicateSlot(QueryException $e): void
    {
        // MySQL/MariaDB: erro de UNIQUE = 1062 (SQLSTATE 23000)
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if ($sqlState === '23000' && $driverCode === 1062) {
            throw ValidationException::withMessages([
                'hora_inicio' => '❌ Este horário já foi agendado. Atualize o calendário e selecione outro.',
            ]);
        }
    }
}
