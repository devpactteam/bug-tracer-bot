@extends('panel.layout')

@section('title', 'گزارش تیکت‌ها')

@section('content')
    <div class="page-title">📊 گزارش تیکت‌ها بر اساس مسئول</div>

    @if (session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    <div class="stats">
        <div class="stat">
            <div class="value color-open">{{ number_format($totals['open']) }}</div>
            <div class="label">باز</div>
        </div>
        <div class="stat">
            <div class="value color-progress">{{ number_format($totals['in_progress']) }}</div>
            <div class="label">در حال بررسی</div>
        </div>
        <div class="stat">
            <div class="value color-closed">{{ number_format($totals['closed']) }}</div>
            <div class="label">بسته شده</div>
        </div>
    </div>

    @if ($rows->isEmpty())
        <div class="empty"><div class="big">📭</div>هنوز کاربری تعریف نشده است.</div>
    @else
        <div class="report-grid">
            @foreach ($rows as $row)
                @php
                    $user = $row['user'];
                    $initial = mb_substr($user->name, 0, 1);
                    $avatarUrl = $user->avatarUrl();
                @endphp
                <div class="user-box">
<div class="avatar-wrap">
                    @if ($avatarUrl)
                        <img class="avatar" src="{{ $avatarUrl }}" alt="{{ $user->name }}">
                    @else
                        <div class="avatar placeholder" style="background:{{ ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0369a1','#db2777','#059669'][($user->id - 1) % 8] }}">{{ $initial }}</div>
                    @endif
                </div>

                    <div class="name">{{ $user->name }}</div>
                    <div class="role">{{ $roles[$user->role] ?? $user->role }}</div>

                    <div class="counts">
                        <div class="count-item">
                            <div class="n open">{{ number_format($row['open']) }}</div>
                            <div class="l">باز</div>
                        </div>
                        <div class="count-item">
                            <div class="n progress">{{ number_format($row['in_progress']) }}</div>
                            <div class="l">در حال بررسی</div>
                        </div>
                        <div class="count-item">
                            <div class="n closed">{{ number_format($row['closed']) }}</div>
                            <div class="l">بسته</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection