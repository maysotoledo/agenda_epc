<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ferias extends Model
{
    protected $table = 'ferias';

    protected $fillable = [
        'user_id',
        'inicio',
        'fim',
        'ano',
    ];

    protected $casts = [
        'inicio' => 'date',
        'fim' => 'date',
        'ano' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // dias inclusivos (ex: 10 -> 10)
    public function getDiasAttribute(): int
    {
        if (! $this->inicio || ! $this->fim) {
            return 0;
        }

        return $this->inicio->diffInDays($this->fim) + 1;
    }
}
