<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        // Se criar via Filament logado, preenche created_by automaticamente
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
