<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentIntakeSession extends Model
{
    protected $fillable = [
        'session_id', 'telegram_chat_id', 'operator_telegram_id', 'status',
        'ai_analysis_result', 'clarification_question', 'selected_assignee_id',
        'ai_suggested_assignee_id', 'preview_message_id', 'telegram_bot_message_ids',
    ];

    protected function casts(): array
    {
        return [
            'ai_analysis_result' => 'array',
            'telegram_bot_message_ids' => 'array',
        ];
    }

    public function rememberBotMessageId(int|string|null $messageId): void
    {
        if ($messageId === null) {
            return;
        }

        $messageIds = array_values(array_unique([
            ...($this->telegram_bot_message_ids ?? []),
            (string) $messageId,
        ]));
        $this->update(['telegram_bot_message_ids' => $messageIds]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntakeMessage::class, 'session_id', 'session_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SupportUser::class, 'selected_assignee_id');
    }

    public function suggestedAssignee(): BelongsTo
    {
        return $this->belongsTo(SupportUser::class, 'ai_suggested_assignee_id');
    }

    public function aiAnalysisLogs(): HasMany
    {
        return $this->hasMany(AiIncidentAnalysisLog::class, 'session_id', 'session_id');
    }
}
