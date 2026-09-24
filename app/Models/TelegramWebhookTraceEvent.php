<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramWebhookTraceEvent extends Model
{
    protected $fillable = ['trace_id', 'checkpoint', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(TelegramWebhookTrace::class, 'trace_id');
    }
}
