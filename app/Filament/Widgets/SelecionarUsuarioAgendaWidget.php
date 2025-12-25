<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

class SelecionarUsuarioAgendaWidget extends Widget implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected string $view = 'filament.widgets.selecionar-usuario-agenda-widget';

    // ✅ Primeiro no dashboard
    protected static ?int $sort = 1;

    public ?int $agendaUserId = null;

    public bool $hasEpcUsers = false;

    public bool $hasSingleEpcUser = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        // EPC não escolhe outro usuário
        return (bool) $user && ! $user->hasRole('epc');
    }

    public function mount(): void
    {
        $epcCount = User::query()->role('epc')->count();

        $this->hasEpcUsers = $epcCount > 0;
        $this->hasSingleEpcUser = $epcCount === 1;

        // Se não há EPC, limpa tudo
        if (! $this->hasEpcUsers) {
            session()->forget('agenda_user_id');

            $this->agendaUserId = null;

            $this->form->fill([
                'agendaUserId' => null,
            ]);

            return;
        }

        $sessionUserId = session('agenda_user_id');

        // valida sessão: só aceita se for EPC
        $validSessionUserId = User::query()
            ->role('epc')
            ->whereKey($sessionUserId)
            ->value('id');

        if ($validSessionUserId) {
            $this->agendaUserId = (int) $validSessionUserId;
        } else {
            // ✅ auto-seleciona o primeiro EPC (por nome)
            $firstEpcId = (int) User::query()
                ->role('epc')
                ->orderBy('name')
                ->value('id');

            $this->agendaUserId = $firstEpcId ?: null;

            if ($this->agendaUserId) {
                session(['agenda_user_id' => $this->agendaUserId]);

                // ✅ Atualiza o calendário na hora (se ele estiver montado)
                $this->dispatch('agendaUserSelected', userId: $this->agendaUserId);
            } else {
                session()->forget('agenda_user_id');
            }
        }

        $this->form->fill([
            'agendaUserId' => $this->agendaUserId,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Placeholder::make('no_epc_users')
                ->label('')
                ->content('Nenhum usuário com a role "epc" foi encontrado. Crie/atribua essa role a algum usuário para selecionar uma agenda.')
                ->visible(fn (): bool => ! $this->hasEpcUsers),

            Forms\Components\Placeholder::make('single_epc_info')
                ->label('')
                ->content('Existe apenas 1 usuário EPC. A agenda foi selecionada automaticamente.')
                ->visible(fn (): bool => $this->hasSingleEpcUser),

            Forms\Components\Select::make('agendaUserId')
                ->label('Selecionar usuário (EPC)')
                ->placeholder($this->hasSingleEpcUser ? null : 'Selecione um usuário EPC...')
                ->options(fn () => User::query()
                    ->role('epc')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()
                )
                ->searchable()
                ->preload()
                ->live()

                // ✅ Filament v4: impede voltar para "nulo"
                ->selectablePlaceholder(false)

                ->visible(fn (): bool => $this->hasEpcUsers)
                ->disabled(fn (): bool => $this->hasSingleEpcUser)
                ->required(fn (): bool => $this->hasEpcUsers && ! $this->hasSingleEpcUser)
                ->afterStateUpdated(function (?int $state) {
                    $previous = $this->agendaUserId;

                    if (! $state) {
                        // Proteção: não permitir "voltar para null"
                        if ($previous) {
                            $this->form->fill(['agendaUserId' => $previous]);
                        }

                        return;
                    }

                    // Garante que o selecionado é EPC
                    $isEpc = User::query()->role('epc')->whereKey($state)->exists();

                    if (! $isEpc) {
                        if ($previous) {
                            $this->form->fill(['agendaUserId' => $previous]);
                        }

                        return;
                    }

                    $this->agendaUserId = $state;
                    session(['agenda_user_id' => $state]);

                    // ✅ Atualiza o calendário imediatamente
                    $this->dispatch('agendaUserSelected', userId: $state);
                }),
        ]);
    }
}
