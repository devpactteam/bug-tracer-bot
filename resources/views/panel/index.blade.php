@extends('panel.layout')

@section('title', 'لیست تیکت‌ها')

@section('content')
    <div class="page-title">📋 لیست تیکت‌ها</div>

    <div class="stats">
        @php
            $statFilters = [
                '' => ['همه تیکت‌ها', $counts['all'], ''],
                'open' => ['باز', $counts['open'], 'color-open'],
                'in_progress' => ['در حال بررسی', $counts['in_progress'], 'color-progress'],
                'resolved' => ['حل شده', $counts['resolved'], 'color-resolved'],
                'closed' => ['بسته شده', $counts['closed'], 'color-closed'],
            ];
        @endphp
        @foreach ($statFilters as $value => [$label, $count, $color])
            <a class="stat {{ $status === $value ? 'active' : '' }}" href="{{ route('panel.index', $value === '' ? [] : ['status' => $value]) }}">
                <span class="value {{ $color }}">{{ number_format($count) }}</span>
                <span class="label">{{ $label }}</span>
            </a>
        @endforeach
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('panel.index') }}">
            @if ($status !== '')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" placeholder="جستجوی شماره تیکت یا عنوان…">
            <button type="submit">جستجو</button>
        </form>
    </div>

    @php
        $priorityClass = fn (string $p) => match ($p) { 'low' => 'priority-low', 'normal' => 'priority-normal', 'high' => 'priority-high', 'critical' => 'priority-critical', default => 'priority-normal' };
        $statusClass = fn (string $s) => match ($s) { 'open' => 'open', 'in_progress' => 'progress', 'resolved' => 'resolved', 'closed' => 'closed', default => 'neutral' };
    @endphp

    <div class="ticket-list">
        @forelse ($tickets as $ticket)
            <div class="ticket-card">
                <div class="info">
                    <div class="num">🆔 {{ $ticket->ticket_number }}</div>
                    <div class="title">{{ $ticket->title }}</div>
                    <div class="meta">
                        <span class="badge {{ $statusClass($ticket->status) }}">{{ $ticket->statusLabel() }}</span>
                        <span class="badge {{ $priorityClass((string) $ticket->priority) }}">🚦 {{ $ticket->priorityLabel() }}</span>
                        <span class="badge neutral">📁 {{ $ticket->categoryLabel() }}</span>
                        @if ($ticket->assignee)
                            <span>👤 {{ $ticket->assignee->name }}</span>
                        @else
                            <span>👤 بدون مسئول</span>
                        @endif
                        <span>🗓 {{ \App\Support\PersianDate::format($ticket->created_at) }}</span>
                        @if ($ticket->ticketLogs()->exists())
                            <span>{{ $ticket->ticketLogs()->count() }} رویداد</span>
                        @endif
                    </div>
                </div>
                <a class="btn btn-primary" href="{{ route('panel.tickets.show', $ticket) }}">
                    جزئیات و مسیر تیکت ←
                </a>
            </div>
        @empty
            <div class="empty">
                <div class="big">🗒️</div>
                <p>تیکتی یافت نشد.</p>
            </div>
        @endforelse
    </div>
@endsection
