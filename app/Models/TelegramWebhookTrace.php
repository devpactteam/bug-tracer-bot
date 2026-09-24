<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramWebhookTrace extends Model
{
    protected $fillable = ['correlation_id', 'update_id', 'update_type', 'outcome', 'latest_checkpoint', 'session_id', 'failure_class', 'failure_message', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(TelegramWebhookTraceEvent::class, 'trace_id');
    }
}
