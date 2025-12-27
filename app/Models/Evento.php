<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evento extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'starts_at',
        'ends_at',
        'titulo',
        'intimado',
        'numero_procedimento',

        // status EPC
        'status',
        'status_motivo',
        'status_at',
        'status_updated_by',

        // auditoria
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'status_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_updated_by');
    }

    // Auditoria
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
