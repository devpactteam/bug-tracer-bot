<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTicketLog extends Model
{
    protected $table = 'ticket_logs';

    protected $fillable = [
        'ticket_id', 'action', 'description', 'actor', 'from_value', 'to_value',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(IncidentTicket::class, 'ticket_id');
    }
}
