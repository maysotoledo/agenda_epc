<?php

namespace App\Filament\Resources\Bloqueios\BloqueioResource\Pages;

use App\Filament\Resources\Bloqueios\BloqueioResource;
use App\Models\Bloqueio;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Validation\ValidationException;

class ManageBloqueios extends ManageRecords
{
    protected static string $resource = BloqueioResource::class;

    private function countDiasUteis(?string $inicio, ?string $fim): int
    {
        if (! $inicio || ! $fim) return 0;

        $start = Carbon::parse($inicio)->startOfDay();
        $end = Carbon::parse($fim)->startOfDay();

        if ($end->lt($start)) return 0;

        $count = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    protected function getHeaderActions(): array
    {
        return [
            // ✅ mantém o cadastro de um dia (form normal do Resource)
            CreateAction::make()
                ->label('Bloquear dia'),

            // ✅ Bloquear vários dias (férias, etc)
            Action::make('bloquearPeriodo')
                ->label('Bloquear período')
                ->icon('heroicon-o-calendar-days')
                ->form([
                    Select::make('user_id')
                        ->label('EPC')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn () => User::query()
                            ->role('epc')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        ),

                    DatePicker::make('dia_inicio')
                        ->label('Início')
                        ->required()
                        ->native(false)
                        ->live(),

                    DatePicker::make('dia_fim')
                        ->label('Fim')
                        ->required()
                        ->native(false)
                        ->live(),
                        // ->rules([
                        //     fn () => function (string $attribute, $value, \Closure $fail): void {
                        //         if (! $value) return;
                        //         if (Carbon::parse($value)->isWeekend()) {
                        //             $fail('Selecione um dia útil (fim de semana já é bloqueado automaticamente).');
                        //         }
                        //     },
                        // ]),

                    Placeholder::make('preview_bloqueio')
                        ->label('')
                        ->content(function (Get $get): string {
                            $inicio = $get('dia_inicio');
                            $fim = $get('dia_fim');

                            $qtd = $this->countDiasUteis($inicio, $fim);

                            if (! $inicio || ! $fim) {
                                return 'Selecione o início e o fim para ver quantos dias úteis serão bloqueados.';
                            }

                            return "✅ Serão bloqueados aproximadamente {$qtd} dia(s) útil(is) nesse período (sábados e domingos são ignorados).";
                        }),

                    TextInput::make('motivo')
                        ->label('Motivo (opcional)')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $start = Carbon::parse($data['dia_inicio'])->startOfDay();
                    $end = Carbon::parse($data['dia_fim'])->startOfDay();

                    if ($end->lt($start)) {
                        throw ValidationException::withMessages([
                            'dia_fim' => 'A data final deve ser maior ou igual à data inicial.',
                        ]);
                    }

                    DB::transaction(function () use ($data, $start, $end) {
                        $userId = (int) $data['user_id'];
                        $motivo = $data['motivo'] ?? null;

                        $hasCreatedBy = DbSchema::hasColumn('bloqueios', 'created_by');

                        $diasCriados = 0;

                        $cursor = $start->copy();
                        while ($cursor->lte($end)) {
                            if ($cursor->isWeekend()) {
                                $cursor->addDay();
                                continue;
                            }

                            $values = ['motivo' => $motivo];
                            if ($hasCreatedBy) {
                                $values['created_by'] = auth()->id();
                            }

                            Bloqueio::query()->updateOrCreate(
                                [
                                    'user_id' => $userId,
                                    'dia' => $cursor->toDateString(),
                                ],
                                $values
                            );

                            $diasCriados++;
                            $cursor->addDay();
                        }

                        Notification::make()
                            ->title('Período bloqueado')
                            ->body("Bloqueio criado/atualizado para {$diasCriados} dia(s) útil(is).")
                            ->success()
                            ->send();
                    });

                    $this->resetTable();
                }),

            // ✅ Desbloquear (remover) período
            Action::make('desbloquearPeriodo')
                ->label('Desbloquear período')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Desbloquear período?')
                ->modalDescription('Isso removerá os bloqueios dos dias úteis dentro do período selecionado.')
                ->form([
                    Select::make('user_id')
                        ->label('EPC')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn () => User::query()
                            ->role('epc')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        ),

                    DatePicker::make('dia_inicio')
                        ->label('Início')
                        ->required()
                        ->native(false)
                        ->live(),

                    DatePicker::make('dia_fim')
                        ->label('Fim')
                        ->required()
                        ->native(false)
                        ->live(),

                    Placeholder::make('preview_desbloqueio')
                        ->label('')
                        ->content(function (Get $get): string {
                            $inicio = $get('dia_inicio');
                            $fim = $get('dia_fim');

                            $qtd = $this->countDiasUteis($inicio, $fim);

                            if (! $inicio || ! $fim) {
                                return 'Selecione o início e o fim para ver quantos dias úteis serão desbloqueados.';
                            }

                            return "🧹 Serão removidos bloqueios de até {$qtd} dia(s) útil(is) nesse período (sábados e domingos são ignorados).";
                        }),

                    TextInput::make('motivo')
                        ->label('Motivo (opcional)')
                        ->helperText('Se preenchido, remove somente bloqueios com esse motivo (exato).')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $start = Carbon::parse($data['dia_inicio'])->startOfDay();
                    $end = Carbon::parse($data['dia_fim'])->startOfDay();

                    if ($end->lt($start)) {
                        throw ValidationException::withMessages([
                            'dia_fim' => 'A data final deve ser maior ou igual à data inicial.',
                        ]);
                    }

                    DB::transaction(function () use ($data, $start, $end) {
                        $userId = (int) $data['user_id'];
                        $motivo = $data['motivo'] ?? null;

                        $removidos = 0;

                        $cursor = $start->copy();
                        while ($cursor->lte($end)) {
                            if ($cursor->isWeekend()) {
                                $cursor->addDay();
                                continue;
                            }

                            $q = Bloqueio::query()
                                ->where('user_id', $userId)
                                ->whereDate('dia', $cursor->toDateString());

                            if ($motivo !== null && $motivo !== '') {
                                $q->where('motivo', $motivo);
                            }

                            $removidos += $q->delete();

                            $cursor->addDay();
                        }

                        Notification::make()
                            ->title('Período desbloqueado')
                            ->body("Bloqueios removidos: {$removidos}.")
                            ->success()
                            ->send();
                    });

                    $this->resetTable();
                }),
        ];
    }
}
