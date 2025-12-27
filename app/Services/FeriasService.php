<?php

namespace App\Services;

use App\Models\Ferias;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeriasService
{
    private function isAdmin(): bool
    {
        $u = Auth::user();
        return (bool) $u && ($u->hasRole('admin') || $u->hasRole('super_admin'));
    }

    public function criar(array $data): Ferias
    {
        return DB::transaction(function () use ($data) {
            // usuário comum cria somente para si
            if (! $this->isAdmin()) {
                $data['user_id'] = Auth::id();
            }

            [$inicio, $fim] = $this->normalizarInicioFim($data);

            $userId = (int) $data['user_id'];
            $ano = (int) $inicio->year;
            $diasNovo = $inicio->diffInDays($fim) + 1;

            $this->validarLimites($userId, $ano, $inicio, $fim, $diasNovo, null);

            return Ferias::create([
                'user_id' => $userId,
                'inicio' => $inicio->toDateString(),
                'fim' => $fim->toDateString(),
                'ano' => $ano,
            ]);
        });
    }

    public function editar(Ferias $ferias, array $data): Ferias
    {
        return DB::transaction(function () use ($ferias, $data) {
            // usuário comum só edita o próprio registro
            if (! $this->isAdmin()) {
                if ((int) $ferias->user_id !== (int) Auth::id()) {
                    throw ValidationException::withMessages([
                        'inicio' => 'Você só pode editar suas próprias férias.',
                    ]);
                }

                // e não pode trocar o usuário do registro
                $data['user_id'] = Auth::id();
            }

            [$inicio, $fim] = $this->normalizarInicioFim($data);

            $userId = (int) $data['user_id'];
            $ano = (int) $inicio->year;
            $diasNovo = $inicio->diffInDays($fim) + 1;

            $this->validarLimites($userId, $ano, $inicio, $fim, $diasNovo, $ferias->id);

            $ferias->update([
                'user_id' => $userId,
                'inicio' => $inicio->toDateString(),
                'fim' => $fim->toDateString(),
                'ano' => $ano,
            ]);

            return $ferias->refresh();
        });
    }

    private function normalizarInicioFim(array $data): array
    {
        if (empty($data['inicio'])) {
            throw ValidationException::withMessages([
                'inicio' => 'Informe a data inicial.',
            ]);
        }

        $inicio = Carbon::parse($data['inicio'])->startOfDay();

        if (! empty($data['quantidade_dias'])) {
            $qtd = (int) $data['quantidade_dias'];

            if ($qtd < 1) {
                throw ValidationException::withMessages([
                    'quantidade_dias' => 'A quantidade deve ser no mínimo 1.',
                ]);
            }

            $fim = $inicio->copy()->addDays($qtd - 1);
        } else {
            if (empty($data['fim'])) {
                throw ValidationException::withMessages([
                    'fim' => 'Informe a data final ou a quantidade de dias.',
                ]);
            }

            $fim = Carbon::parse($data['fim'])->startOfDay();
        }

        if ($fim->lt($inicio)) {
            throw ValidationException::withMessages([
                'quantidade_dias' => 'A quantidade de dias gerou uma data final inválida.',
            ]);
        }

        if ($inicio->year !== $fim->year) {
            throw ValidationException::withMessages([
                'quantidade_dias' => 'As férias não podem cruzar o ano. Crie outro período no próximo ano.',
            ]);
        }

        return [$inicio, $fim];
    }

    private function validarLimites(
        int $userId,
        int $ano,
        Carbon $inicio,
        Carbon $fim,
        int $diasNovoPeriodo,
        ?int $ignoreId
    ): void {
        // 1) Até 3 períodos por ano
        $qtdPeriodos = Ferias::query()
            ->where('user_id', $userId)
            ->where('ano', $ano)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->count();

        if ($qtdPeriodos >= 3) {
            throw ValidationException::withMessages([
                'inicio' => 'Limite atingido: você só pode dividir as férias em até 3 períodos por ano.',
            ]);
        }

        // 2) Total até 30 dias por ano
        $existentes = Ferias::query()
            ->where('user_id', $userId)
            ->where('ano', $ano)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();

        $diasJaUsados = $existentes->sum(fn (Ferias $f) => $f->dias);

        if (($diasJaUsados + $diasNovoPeriodo) > 30) {
            $restante = max(0, 30 - $diasJaUsados);

            throw ValidationException::withMessages([
                'quantidade_dias' => "Limite anual excedido: você já usou {$diasJaUsados} dia(s) neste ano. Restam {$restante} dia(s).",
            ]);
        }

        // 3) Não pode sobrepor períodos do mesmo usuário
        $temSobreposicaoUsuario = Ferias::query()
            ->where('user_id', $userId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereDate('inicio', '<=', $fim->toDateString())
            ->whereDate('fim', '>=', $inicio->toDateString())
            ->exists();

        if ($temSobreposicaoUsuario) {
            throw ValidationException::withMessages([
                'inicio' => 'Este período sobrepõe outro período de férias já cadastrado para este usuário.',
            ]);
        }

        // 4) mesma role não pode chocar nenhum dia (ipc, epc, ipc_plantao, epc_plantao)
        $user = User::query()->with('roles')->find($userId);

        if (! $user) {
            return;
        }

        $rolesRelevantes = $user->roles
            ->pluck('name')
            ->intersect(['ipc', 'epc', 'ipc_plantao', 'epc_plantao'])
            ->values();

        foreach ($rolesRelevantes as $roleName) {
            $idsMesmaRole = User::query()
                ->role($roleName)
                ->whereKeyNot($userId)
                ->pluck('id');

            if ($idsMesmaRole->isEmpty()) {
                continue;
            }

            $temChoque = Ferias::query()
                ->whereIn('user_id', $idsMesmaRole)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->whereDate('inicio', '<=', $fim->toDateString())
                ->whereDate('fim', '>=', $inicio->toDateString())
                ->exists();

            if ($temChoque) {
                throw ValidationException::withMessages([
                    'inicio' => 'Não é permitido agendar férias no mesmo dia para usuários do mesmo cargo (EPC/IPC).',
                ]);
            }
        }
    }
}
