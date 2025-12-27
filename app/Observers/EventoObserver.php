<?php

namespace App\Observers;

use App\Models\Evento;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;

class EventoObserver
{
    private function shouldNotifyEpc(Evento $evento): bool
    {
        if (! $evento->user_id) return false;

        // Se o próprio EPC mexer na própria agenda (caso raro), não notifica ele mesmo
        if (auth()->id() && auth()->id() === (int) $evento->user_id) {
            return false;
        }

        return true;
    }

    private function notifyEpc(Evento $evento, string $acao): void
    {
        if (! $this->shouldNotifyEpc($evento)) return;

        /** @var User|null $epc */
        $epc = User::query()->find($evento->user_id);
        if (! $epc) return;

        $quem = auth()->user()?->name ?? 'Sistema';

        $dataHora = $evento->starts_at
            ? Carbon::parse($evento->starts_at)->format('d/m/Y H:i')
            : '—';

        $intimado = $evento->intimado ?: '—';
        $proc = $evento->numero_procedimento ?: '—';

        Notification::make()
            ->title("Agendamento {$acao}")
            ->body("Por: {$quem}\nData/Hora: {$dataHora}\nIntimado: {$intimado}\nProcedimento: {$proc}")
            ->sendToDatabase($epc);
    }

    private function notifyAdminsStatus(Evento $evento): void
    {
        // Só quando muda status
        if (! $evento->wasChanged(['status', 'status_motivo', 'status_at', 'status_updated_by'])) {
            return;
        }

        // Quem marcou
        $ator = $evento->statusUpdatedBy ?: auth()->user();

        // Só dispara se foi EPC marcando
        if (! $ator || ! $ator->hasRole('epc')) {
            return;
        }

        $dataHora = $evento->starts_at
            ? Carbon::parse($evento->starts_at)->format('d/m/Y H:i')
            : '—';

        $intimado = $evento->intimado ?: '—';
        $proc = $evento->numero_procedimento ?: '—';

        $statusLabel = match ($evento->status) {
            'cumprida' => 'Cumprida ✅',
            'nao_cumprida' => 'Não cumprida ❌',
            default => 'Pendente ⏳',
        };

        $motivo = $evento->status === 'nao_cumprida'
            ? ($evento->status_motivo ?: 'Sem motivo informado.')
            : '—';

        $admins = User::query()->role('admin')->get();

        foreach ($admins as $admin) {
            Notification::make()
                ->title("EPC atualizou status: {$statusLabel}")
                ->body("EPC: {$ator->name}\nData/Hora: {$dataHora}\nIntimado: {$intimado}\nProcedimento: {$proc}\nMotivo: {$motivo}")
                ->sendToDatabase($admin);
        }
    }

    public function created(Evento $evento): void
    {
        $this->notifyEpc($evento, 'criado');
    }

    public function updated(Evento $evento): void
    {
        // 1) atualizações gerais (admin mexendo no agendamento)
        $this->notifyEpc($evento, 'atualizado');

        // 2) EPC marcando status -> notifica admins
        $this->notifyAdminsStatus($evento);
    }

    public function deleted(Evento $evento): void
    {
        $this->notifyEpc($evento, 'cancelado');
    }
}
