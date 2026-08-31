<?php

namespace App\Services;

use App\Models\IncidentTicket;
use Illuminate\Support\Facades\DB;

class IncidentRcaService
{
    public function record(
        IncidentTicket $ticket,
        string $category,
        string $description,
        string $resolutionAction,
        int $authorId,
    ): IncidentTicket {
        return DB::transaction(function () use ($ticket, $category, $description, $resolutionAction, $authorId): IncidentTicket {
            $ticket->update([
                'root_cause_category' => $category,
                'root_cause_description' => $description,
                'resolution_action' => $resolutionAction,
                'root_cause_author_id' => $authorId,
                'resolved_at' => now(),
                'status' => 'resolved',
            ]);
            return $ticket->refresh();
        });
    }
}
