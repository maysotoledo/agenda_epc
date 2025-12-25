<?php

namespace App\Observers;

use App\Models\Evento;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;

class EventoObserver
{
    private function shouldNotify(Evento $evento): bool
    {
        if (! $evento->user_id) {
            return false;
        }

        // Opcional: se o próprio EPC mexer na própria agenda, não notifica ele mesmo
        if (auth()->id() && auth()->id() === (int) $evento->user_id) {
            return false;
        }

        return true;
    }

    private function notifyEpc(Evento $evento, string $acao): void
    {
        if (! $this->shouldNotify($evento)) {
            return;
        }

        /** @var User|null $epc */
        $epc = User::query()->find($evento->user_id);
        if (! $epc) {
            return;
        }

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

    public function created(Evento $evento): void
    {
        $this->notifyEpc($evento, 'criado');
    }

    public function updated(Evento $evento): void
    {
        $this->notifyEpc($evento, 'atualizado');
    }

    public function deleted(Evento $evento): void
    {
        $this->notifyEpc($evento, 'cancelado');
    }
}
