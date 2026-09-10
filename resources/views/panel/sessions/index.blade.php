@extends('panel.layout')

@section('title', 'سشن‌های دریافت گزارش')

@section('content')
    <div class="page-title">🧩 سشن‌های دریافت گزارش</div>

    @if (session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="alert error">{{ session('info') }}</div>
    @endif

    <div class="stats">
        <a class="stat {{ $scope === 'open' ? 'active' : '' }}" href="{{ route('panel.sessions.index') }}">
            <span class="value color-open">{{ number_format($counts['open']) }}</span>
            <span class="label">سشن‌های باز</span>
        </a>
        <a class="stat {{ $scope === 'all' ? 'active' : '' }}" href="{{ route('panel.sessions.index', ['scope' => 'all']) }}">
            <span class="value">{{ number_format($counts['all']) }}</span>
            <span class="label">همهٔ سشن‌ها</span>
        </a>
    </div>

    <div class="toolbar">
        <div class="hint">نمای پیش‌فرض فقط سشن‌های باز را نمایش می‌دهد.</div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>وضعیت</th>
                    <th>چت / اپراتور</th>
                    <th>پیام‌ها</th>
                    <th>آخرین تغییر</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    @php
                        $status = match ($session->status) {
                            'collecting' => ['در حال دریافت', 'open'],
                            'analyzing' => ['در حال تحلیل', 'progress'],
                            'awaiting_clarification' => ['منتظر توضیح', 'resolved'],
                            'awaiting_approval' => ['منتظر تأیید', 'progress'],
                            'completed' => ['تکمیل شده', 'closed'],
                            'cancelled' => ['لغو شده', 'neutral'],
                            default => [$session->status, 'neutral'],
                        };
                        $isActive = in_array($session->status, $activeStatuses, true);
                    @endphp
                    <tr>
                        <td>
                            <div class="mono">{{ $session->session_id }}</div>
                            <div class="muted">{{ \App\Support\PersianDate::format($session->created_at) }}</div>
                        </td>
                        <td><span class="badge {{ $status[1] }}">{{ $status[0] }}</span></td>
                        <td>
                            <div>{{ $session->telegram_chat_id }}</div>
                            <div class="muted">اپراتور: {{ $session->operator_telegram_id }}</div>
                        </td>
                        <td>{{ number_format($session->messages_count) }}</td>
                        <td>{{ \App\Support\PersianDate::format($session->updated_at) }}</td>
                        <td>
                            @if ($isActive)
                                <form method="POST" action="{{ route('panel.sessions.close', $session) }}" onsubmit="return confirm('این سشن لغو شود؟');">
                                    @csrf
                                    <button class="btn btn-danger btn-sm" type="submit">بستن سشن</button>
                                </form>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty"><div class="big">✅</div>سشنی برای نمایش وجود ندارد.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
