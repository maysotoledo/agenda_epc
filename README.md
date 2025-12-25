# Agenda Intimação (Laravel 12 + Filament v4)

Este projeto implementa uma **Agenda por EPC** com:

- Seleção de usuário **somente com role `epc`**
- Usuário logado com role **`epc`** não seleciona outro usuário (vê apenas sua agenda)
- **Bloqueios por EPC** (admin cadastra dia + motivo)
- **Fim de semana bloqueado automaticamente**
- Em **dia bloqueado** / **fim de semana**, o modal abre **apenas com mensagem** (com **motivo** do bloqueio)
- O calendário **não aparece no Dashboard**: ele fica dentro da **Resource `Agenda`**, e os widgets só aparecem ao clicar nela

> ✅ Este README contém **passo a passo do zero** e **todos os arquivos completos** para copiar e colar.

---

## Requisitos

- PHP 8.2+
- Composer
- Banco de dados (MySQL/Postgres/etc)

---

## 1) Criar projeto Laravel

```bash
composer create-project laravel/laravel agenda
cd agenda
```

Configure o banco no `.env` e rode:

```bash
php artisan key:generate
php artisan migrate
```

---

## 2) Instalar Filament v4 (Painel)

```bash
composer require filament/filament:"^4.0"
php artisan filament:install --panels
```

Criar usuário para acesso ao painel:

```bash
php artisan make:filament-user
```

Acesse: `http://localhost:8000/admin`

---

## 3) Instalar Spatie Permission (roles)

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider"
php artisan migrate
```

### 3.1 Habilitar HasRoles no User

**Arquivo:** `app/Models/User.php`

```php
use Spatie\\Permission\\Traits\\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    // ...
}
```

### 3.2 Criar roles `admin` e `epc` (Seeder)

```bash
php artisan make:seeder RolesSeeder
```

**Arquivo:** `database/seeders/RolesSeeder.php`

```php
<?php

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use Spatie\\Permission\\Models\\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('epc');
        Role::findOrCreate('super_admin'); // opcional
    }
}
```

Rodar:

```bash
php artisan db:seed --class=RolesSeeder
```

---

## 4) Instalar Filament Shield (permissões no painel)

```bash
composer require bezhansalleh/filament-shield
php artisan vendor:publish --tag="filament-shield-config"
php artisan shield:setup
```

---

## 5) Instalar FullCalendar para Filament (Saade)

```bash
composer require saade/filament-fullcalendar
```

---

## 6) Configurar o painel (AdminPanelProvider)

Objetivo:

- ✅ **não** usar `discoverWidgets()` (para widgets NÃO aparecerem no Dashboard)
- ✅ registrar apenas widgets globais do painel (ex: `AccountWidget`)
- ✅ manter plugins (Shield + FullCalendar)

**Arquivo:** `app/Providers/Filament/AdminPanelProvider.php` (completo)

```php
<?php

namespace App\\Providers\\Filament;

use BezhanSalleh\\FilamentShield\\FilamentShieldPlugin;
use Filament\\Http\\Middleware\\Authenticate;
use Filament\\Http\\Middleware\\AuthenticateSession;
use Filament\\Http\\Middleware\\DisableBladeIconComponents;
use Filament\\Http\\Middleware\\DispatchServingFilamentEvent;
use Filament\\Pages\\Dashboard;
use Filament\\Panel;
use Filament\\PanelProvider;
use Filament\\Support\\Colors\\Color;
use Filament\\Widgets\\AccountWidget;
use Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse;
use Illuminate\\Cookie\\Middleware\\EncryptCookies;
use Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken;
use Illuminate\\Routing\\Middleware\\SubstituteBindings;
use Illuminate\\Session\\Middleware\\StartSession;
use Illuminate\\View\\Middleware\\ShareErrorsFromSession;
use Saade\\FilamentFullCalendar\\FilamentFullCalendarPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\\\Filament\\\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\\\Filament\\\\Pages')
            ->pages([
                Dashboard::class,
            ])

            // ❌ NÃO descubra widgets automaticamente
            // ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\\\Filament\\\\Widgets')

            // ✅ Widgets globais do painel (Dashboard)
            ->widgets([
                AccountWidget::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
                FilamentFullCalendarPlugin::make()
                    ->selectable()
                    ->editable(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

---

## 7) Registrar widgets no Livewire (manual)

Como removemos `discoverWidgets()`, precisamos registrar os widgets manualmente para o Livewire não dar:
`Unable to find component: [app.filament.widgets...]`.

### 7.1 Criar Provider

```bash
php artisan make:provider LivewireComponentsServiceProvider
```

### 7.2 Provider completo

**Arquivo:** `app/Providers/LivewireComponentsServiceProvider.php`

```php
<?php

namespace App\\Providers;

use App\\Filament\\Widgets\\CalendarWidget;
use App\\Filament\\Widgets\\SelecionarUsuarioAgendaWidget;
use Illuminate\\Support\\ServiceProvider;
use Livewire\\Livewire;

class LivewireComponentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component(
            'app.filament.widgets.selecionar-usuario-agenda-widget',
            SelecionarUsuarioAgendaWidget::class
        );

        Livewire::component(
            'app.filament.widgets.calendar-widget',
            CalendarWidget::class
        );
    }
}
```

### 7.3 Registrar provider no Laravel 12

No Laravel 12 (estrutura 11+), registre em `bootstrap/providers.php`:

```php
return [
    // ...
    App\\Providers\\LivewireComponentsServiceProvider::class,
];
```

---

## 8) Implementar Bloqueios (admin cria bloqueio por EPC)

### 8.1 Criar model + migration

```bash
php artisan make:model Bloqueio -m
```

**Arquivo:** `database/migrations/xxxx_xx_xx_xxxxxx_create_bloqueios_table.php`

```php
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('dia');

            $table->string('motivo')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'dia']);
            $table->index(['user_id', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueios');
    }
};
```

**Arquivo:** `app/Models/Bloqueio.php`

```php
<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class Bloqueio extends Model
{
    protected $table = 'bloqueios';

    protected $fillable = [
        'user_id',
        'dia',
        'motivo',
        'created_by',
    ];

    protected $casts = [
        'dia' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Bloqueio $bloqueio) {
            if (empty($bloqueio->created_by)) {
                $bloqueio->created_by = auth()->id();
            }
        });
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

Rodar migration:

```bash
php artisan migrate
```

---

## 9) Policy para Bloqueio (somente admin)

```bash
php artisan make:policy BloqueioPolicy --model=Bloqueio
```

**Arquivo:** `app/Policies/BloqueioPolicy.php`

```php
<?php

namespace App\\Policies;

use App\\Models\\Bloqueio;
use App\\Models\\User;

class BloqueioPolicy
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Bloqueio $bloqueio): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Bloqueio $bloqueio): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Bloqueio $bloqueio): bool
    {
        return $this->isAdmin($user);
    }
}
```

---

## 10) Resource Filament: Bloqueios

Gerar base:

```bash
php artisan make:filament-resource Bloqueio --simple
```

Criar pastas no padrão do projeto:

```bash
mkdir -p app/Filament/Resources/Bloqueios/BloqueioResource/Pages
```

### 10.1 Resource completo

**Arquivo:** `app/Filament/Resources/Bloqueios/BloqueioResource.php`

```php
<?php

namespace App\\Filament\\Resources\\Bloqueios;

use App\\Filament\\Resources\\Bloqueios\\BloqueioResource\\Pages\\ManageBloqueios;
use App\\Models\\Bloqueio;
use App\\Models\\User;
use BackedEnum;
use Carbon\\Carbon;
use Filament\\Forms\\Components\\DatePicker;
use Filament\\Forms\\Components\\Select;
use Filament\\Forms\\Components\\TextInput;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Components\\Utilities\\Get;
use Filament\\Schemas\\Schema;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Table;
use Illuminate\\Validation\\Rule;
use UnitEnum;

class BloqueioResource extends Resource
{
    protected static ?string $model = Bloqueio::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';
    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?string $navigationLabel = 'Bloqueios';
    protected static ?string $modelLabel = 'Bloqueio';
    protected static ?string $pluralModelLabel = 'Bloqueios';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
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

            DatePicker::make('dia')
                ->label('Dia')
                ->required()
                ->native(false)
                ->helperText('Somente dias úteis. Sábado e domingo já são bloqueados automaticamente.')
                ->rules([
                    fn () => function (string $attribute, $value, \\Closure $fail): void {
                        if (! $value) return;

                        if (Carbon::parse($value)->isWeekend()) {
                            $fail('Fim de semana já é bloqueado automaticamente. Selecione um dia útil.');
                        }
                    },

                    fn (Get $get, ?Bloqueio $record) => Rule::unique('bloqueios', 'dia')
                        ->where('user_id', $get('user_id'))
                        ->ignore($record?->getKey()),
                ]),

            TextInput::make('motivo')
                ->label('Motivo (opcional)')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('epc.name')
                    ->label('EPC')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('dia')
                    ->label('Dia')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(60),

                TextColumn::make('criadoPor.name')
                    ->label('Criado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('dia', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBloqueios::route('/'),
        ];
    }
}
```

### 10.2 Page completa

**Arquivo:** `app/Filament/Resources/Bloqueios/BloqueioResource/Pages/ManageBloqueios.php`

```php
<?php

namespace App\\Filament\\Resources\\Bloqueios\\BloqueioResource\\Pages;

use App\\Filament\\Resources\\Bloqueios\\BloqueioResource;
use Filament\\Actions\\CreateAction;
use Filament\\Actions\\DeleteAction;
use Filament\\Actions\\EditAction;
use Filament\\Resources\\Pages\\ManageRecords;

class ManageBloqueios extends ManageRecords
{
    protected static string $resource = BloqueioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getRecordActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
```

---

## 11) Resource Filament: Agenda (calendário fora do dashboard)

Gerar base:

```bash
php artisan make:filament-resource Agenda
```

Criar pastas:

```bash
mkdir -p app/Filament/Resources/Agendas/Pages
mkdir -p resources/views/filament/resources/agendas/pages
```

### 11.1 Resource completo

**Arquivo:** `app/Filament/Resources/Agendas/AgendaResource.php`

```php
<?php

namespace App\\Filament\\Resources\\Agendas;

use App\\Filament\\Resources\\Agendas\\Pages\\AgendaCalendar;
use App\\Models\\Evento;
use BackedEnum;
use Filament\\Resources\\Resource;
use UnitEnum;

class AgendaResource extends Resource
{
    protected static ?string $model = Evento::class;

    protected static ?string $navigationLabel = 'Agenda';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    public static function getPages(): array
    {
        return [
            'index' => AgendaCalendar::route('/'),
        ];
    }
}
```

### 11.2 Page completa (Filament v4: `$view` NÃO é static)

**Arquivo:** `app/Filament/Resources/Agendas/Pages/AgendaCalendar.php`

```php
<?php

namespace App\\Filament\\Resources\\Agendas\\Pages;

use App\\Filament\\Resources\\Agendas\\AgendaResource;
use App\\Filament\\Widgets\\CalendarWidget;
use App\\Filament\\Widgets\\SelecionarUsuarioAgendaWidget;
use Filament\\Resources\\Pages\\Page;

class AgendaCalendar extends Page
{
    protected static string $resource = AgendaResource::class;

    protected string $view = 'filament.resources.agendas.pages.agenda-calendar';

    protected function getHeaderWidgets(): array
    {
        return [
            SelecionarUsuarioAgendaWidget::class,
            CalendarWidget::class,
        ];
    }
}
```

### 11.3 Blade da página

**Arquivo:** `resources/views/filament/resources/agendas/pages/agenda-calendar.blade.php`

```blade
<x-filament-panels::page>
    {{-- Widgets renderizados automaticamente --}}
</x-filament-panels::page>
```

---

## 12) Widget Selecionar EPC (View)

**Arquivo:** `resources/views/filament/widgets/selecionar-usuario-agenda-widget.blade.php`

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>
</x-filament-widgets::widget>
```

---

## 13) CalendarWidget (arquivo completo)

> ⚠️ **Cole aqui o conteúdo completo do seu arquivo atual**:
`app/Filament/Widgets/CalendarWidget.php`

Ele deve conter:
- bloqueio fim de semana
- bloqueio admin com motivo (modal só mensagem)
- background vermelho para bloqueio
- seleção por sessão / evento `agendaUserSelected`

---

## 14) Limpar cache

```bash
php artisan optimize:clear
```

---

## Como usar

### Admin
- Menu **Agenda**: seleciona EPC e agenda
- Menu **Bloqueios**: cria bloqueios com motivo

### EPC
- Menu **Agenda**: vê apenas sua agenda (sem seletor)

---

## Troubleshooting

### Widgets aparecem no Dashboard
- Garanta que NÃO existe `->discoverWidgets(...)` no `AdminPanelProvider`
- Garanta que `->widgets([...])` do painel não adiciona `CalendarWidget`/`SelecionarUsuarioAgendaWidget`

### Livewire “Unable to find component”
- Garanta que `LivewireComponentsServiceProvider` está registrado em `bootstrap/providers.php`
- Garanta que os aliases batem com os do provider

