<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeMessage extends Model
{
    protected $fillable = [
        'session_id', 'telegram_message_id', 'content', 'media_type',
        'media_file_id', 'forward_origin_metadata',
    ];

    protected function casts(): array
    {
        return ['forward_origin_metadata' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeSession::class, 'session_id', 'session_id');
    }
}
