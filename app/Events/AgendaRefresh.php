<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgendaRefresh implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,          // EPC dono da agenda
        public ?int $eventoId = null,
        public ?string $acao = null, // criado | atualizado | cancelado | status atualizado
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("agenda.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'agenda.refresh';
    }
}
