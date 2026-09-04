<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiIncidentAnalysisLog extends Model
{
    protected $fillable = [
        'session_id',
        'phase',
        'provider',
        'model',
        'operator_telegram_id',
        'request_payload',
        'response_payload',
        'normalized_response',
        'status',
        'error_class',
        'error_message',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'normalized_response' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeSession::class, 'session_id', 'session_id');
    }
}
