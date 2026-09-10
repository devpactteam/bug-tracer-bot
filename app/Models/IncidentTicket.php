<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentTicket extends Model
{
    protected $fillable = [
        'ticket_number', 'session_id', 'title', 'description', 'scope',
        'sample_data', 'category', 'priority', 'assignee_id', 'status',
        'root_cause_category', 'root_cause_description', 'resolution_action',
        'resolution_note', 'resolution_pending', 'assignee_message_id',
        'bug_owner_note', 'bug_cause_note', 'bug_action_note', 'resolution_step',
        'root_cause_author_id', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_data' => 'array',
            'resolution_pending' => 'boolean',
            'assignee_message_id' => 'integer',
            'resolution_step' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeSession::class, 'session_id', 'session_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SupportUser::class, 'assignee_id');
    }

    public function rootCauseAuthor(): BelongsTo
    {
        return $this->belongsTo(SupportUser::class, 'root_cause_author_id');
    }

    public function ticketLogs(): HasMany
    {
        return $this->hasMany(IncidentTicketLog::class, 'ticket_id');
    }

    public function categoryLabel(): string
    {
        if (! is_string($this->category) || $this->category === '') {
            return 'نامشخص';
        }
        $key = strtolower(trim($this->category));
        $categories = (array) config('incident.categories');
        if (isset($categories[$key]['label'])) {
            return (string) $categories[$key]['label'];
        }
        $aliases = [
            'payments' => 'payment',
            'general' => 'other',
            'client-side' => 'client',
            'frontend' => 'client',
            'back-end' => 'backend',
            'server' => 'backend',
        ];
        $mapped = $aliases[$key] ?? $key;

        return isset($categories[$mapped]['label'])
            ? (string) $categories[$mapped]['label']
            : (string) $this->category;
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            'system_wide' => 'سراسری (همه کاربران)',
            'user_specific' => 'تک کاربر / حساب خاص',
            'client', 'backend' => $this->scope === 'client' ? 'کلاینت' : 'بک‌اند',
            default => 'نامشخص',
        };
    }

    public function priorityLabel(): string
    {
        return match ($this->priority) {
            'low' => 'کم',
            'normal' => 'عادی',
            'high' => 'بالا',
            'critical' => 'بحرانی',
            default => 'عادی',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'باز',
            'in_progress' => 'در حال بررسی',
            'resolved' => 'حل شده',
            'closed' => 'بسته شده',
            default => (string) $this->status,
        };
    }

    /**
     * Relative paths (public disk) of the photos attached to this ticket via
     * its intake session messages.
     */
    public function photoPaths(): array
    {
        return IntakeMessage::query()
            ->where('session_id', $this->session_id)
            ->whereNotNull('media_local_path')
            ->pluck('media_local_path')
            ->all();
    }

    /**
     * Append an entry to the ticket's audit trail. Used on creation, status
     * changes, reassignments and resolution-note steps.
     */
    public function log(
        string $action,
        ?string $description = null,
        ?string $actor = null,
        ?string $from = null,
        ?string $to = null,
    ): IncidentTicketLog {
        return $this->ticketLogs()->create([
            'action' => $action,
            'description' => $description,
            'actor' => $actor,
            'from_value' => $from,
            'to_value' => $to,
        ]);
    }
}
