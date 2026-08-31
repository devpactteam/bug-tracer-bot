<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTicket extends Model
{
    protected $fillable = [
        'ticket_number', 'session_id', 'title', 'description', 'scope',
        'sample_data', 'category', 'priority', 'assignee_id', 'status',
        'root_cause_category', 'root_cause_description', 'resolution_action',
        'root_cause_author_id', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_data' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeSession::class, 'session_id', 'session_id');
    }
}
