@extends('panel.layout')

@section('title', $ticket->ticket_number.' – جزئیات')

@section('content')
    @if (session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @elseif (session('info'))
        <div class="alert success">{{ session('info') }}</div>
    @endif

    <a class="back btn btn-ghost" href="{{ route('panel.index') }}">→ بازگشت به لیست</a>

    <div class="page-title">
        🆔 {{ $ticket->ticket_number }}
        <span class="badge {{ match ($ticket->status) { 'open' => 'open', 'in_progress' => 'progress', 'resolved' => 'resolved', 'closed' => 'closed', default => 'neutral' } }}">
            {{ $ticket->statusLabel() }}
        </span>
    </div>

    <div class="grid-detail">
        <div>
            <div class="card">
                <h2>🔍 مشخصات تیکت</h2>
                <dl class="kv">
                    <dt>عنوان</dt><dd>{{ $ticket->title }}</dd>
                    <dt>شماره</dt><dd>{{ $ticket->ticket_number }}</dd>
                    <dt>وضعیت</dt><dd>{{ $ticket->statusLabel() }}</dd>
                    <dt>دسته</dt><dd>{{ $ticket->categoryLabel() }}</dd>
                    <dt>اولویت</dt><dd>{{ $ticket->priorityLabel() }}</dd>
                    <dt>دامنه</dt><dd>{{ $ticket->scopeLabel() }}</dd>
                    <dt>مسئول</dt><dd>{{ $ticket->assignee ? $ticket->assignee->name : 'بدون مسئول' }}</dd>
                    <dt>باز شده در</dt><dd>{{ \App\Support\PersianDate::format($ticket->created_at) }}</dd>
                    @if ($ticket->resolved_at)
                        <dt>بسته شده در</dt><dd>{{ \App\Support\PersianDate::format($ticket->resolved_at) }}</dd>
                    @endif
                    @if ($ticket->rootCauseAuthor)
                        <dt>رفع‌کننده</dt><dd>{{ $ticket->rootCauseAuthor->name }}</dd>
                    @endif
                </dl>

                <h2 style="margin-top:20px">📝 شرح مشکل</h2>
                <p style="white-space:pre-wrap; font-size:.9rem">{{ $ticket->description }}</p>

                @if ($ticket->status !== 'closed')
                    <form method="POST" action="{{ route('panel.tickets.close', $ticket) }}" style="margin-top:20px"
                          onsubmit="return confirm('آیا از بستن تیکت {{ $ticket->ticket_number }} مطمئن هستید؟');">
                        @csrf
                        <button class="btn btn-danger" type="submit">✅ بستن تیکت</button>
                    </form>
                @endif

                @if (is_array($ticket->sample_data) && $ticket->sample_data !== [])
                    <h2 style="margin-top:20px">🧾 داده‌های نمونه (دیباگ)</h2>
                    <div class="pre-block">{{ json_encode($ticket->sample_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</div>
                @endif
            </div>

            @if ($ticket->bug_owner_note !== null || $ticket->bug_cause_note !== null || $ticket->bug_action_note !== null)
                <div class="card">
                    <h2>💡 پرسش‌های رفع (RCA)</h2>
                    <div class="notes">
                        @foreach ([
                            ['1️⃣ مسئول باگ چه کسی بود؟', $ticket->bug_owner_note],
                            ['2️⃣ دلیل باگ چه بوده؟', $ticket->bug_cause_note],
                            ['3️⃣ اقدامات انجام‌شده جهت رفع چه بوده است؟', $ticket->bug_action_note],
                        ] as [$question, $answer])
                            <div class="note">
                                <div class="q">{{ $question }}</div>
                                @if ($answer !== null)
                                    <div class="a">{{ $answer }}</div>
                                @else
                                    <div class="no">پاسخی ثبت نشده است.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="card">
            <h2>🛤️ مسیر تیکت <small>(سوابق فعالیت)</small></h2>

            @php
                $logMeta = fn (string $action): array => match ($action) {
                    'created' => ['🆕', 'ساخت تیکت', 'created'],
                    'status_changed' => ['🟡', 'تغییر وضعیت', 'status'],
                    'resolution_started' => ['📝', 'شروع پرسش‌نامه رفع', 'resolution'],
                    'note_answer' => ['💬', 'پاسخ به پرسش', 'answer'],
                    'assigned_to' => ['🔄', 'انتقال به مسئول دیگر', 'assigned'],
                    'closed' => ['✅', 'بسته شدن تیکت', 'closed'],
                    default => ['📄', (string) $action, ''],
                };
                $statusTag = fn ($v) => match ($v) {
                    'open' => 'باز', 'in_progress' => 'در حال بررسی',
                    'resolved' => 'حل شده', 'closed' => 'بسته شده',
                    default => $v,
                };
                $logs = $ticket->ticketLogs()->orderBy('created_at')->get();
            @endphp

            @if ($logs->isEmpty())
                <p style="color:var(--muted); font-size:.88rem">هنوز رویدادی برای این تیکت ثبت نشده است.</p>
            @else
                <div class="timeline">
                    @foreach ($logs as $log)
                        @php
                            [$icon, $heading, $class] = $logMeta($log->action);
                        @endphp
                        <div class="entry {{ $class }}">
                            <div class="icon">{{ $icon }}</div>
                            <div class="head">
                                <span class="title">{{ $heading }}</span>
                                <span class="time">{{ \App\Support\PersianDate::format($log->created_at) }}</span>
                            </div>
                            @if ($log->action === 'note_answer')
                                @php
                                    [$question, $answer] = [strtok((string) $log->description, "\n") ?: '', (string) $log->description];
                                    $nl = strpos((string) $log->description, "\n");
                                    if ($nl !== false) { $question = substr((string) $log->description, 0, $nl); $answer = substr((string) $log->description, $nl + 1); }
                                @endphp
                                <div class="desc"><b>{{ $question }}</b><br>{{ $answer }}</div>
                            @elseif ($log->description)
                                <div class="desc">{{ $log->description }}</div>
                            @endif
                            <div class="chg">
                                @if ($log->action === 'status_changed' && $log->from_value !== null && $log->to_value !== null)
                                    <span class="chip">{{ $statusTag($log->from_value) }}</span>
                                    <span class="arrow">←</span>
                                    <span class="chip">{{ $statusTag($log->to_value) }}</span>
                                @elseif ($log->action === 'assigned_to')
                                    <span class="chip">{{ $log->from_value }}</span>
                                    <span class="arrow">←</span>
                                    <span class="chip">{{ $log->to_value }}</span>
                                @endif
                                @if ($log->actor)
                                    <span class="chip actor">👤 {{ $log->actor }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
