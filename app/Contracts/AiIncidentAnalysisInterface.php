<?php

namespace App\Contracts;

use App\Models\IncidentIntakeSession;

interface AiIncidentAnalysisInterface
{
    /** @return array{title:string,summary:string,scope:string,category:?string,priority:string,sample_data:array,clarification_needed:bool,clarification_question:?string} */
    public function analyze(IncidentIntakeSession $session, string $phase = 'initial'): array;
}
