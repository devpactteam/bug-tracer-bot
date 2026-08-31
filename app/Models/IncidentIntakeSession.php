<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentIntakeSession extends Model
{
    protected $fillable = [
        'session_id', 'telegram_chat_id', 'operator_telegram_id', 'status',
        'ai_analysis_result', 'clarification_question', 'selected_assignee_id',
    ];

    protected function casts(): array
    {
        return ['ai_analysis_result' => 'array'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntakeMessage::class, 'session_id', 'session_id');
    }
}
