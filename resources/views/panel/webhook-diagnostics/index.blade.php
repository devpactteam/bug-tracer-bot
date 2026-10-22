@extends('panel.layout')

@section('title', 'عیب‌یابی وب‌هوک تلگرام')

@section('content')
    <h1 class="page-title">عیب‌یابی وب‌هوک تلگرام</h1>
    <p class="muted" style="margin-bottom:18px">فقط فرادادهٔ امن ثبت می‌شود؛ متن پیام و اطلاعات محرمانه نمایش داده نمی‌شود.</p>
    <form method="GET" action="{{ route('panel.telegram-webhooks.index') }}" style="display:flex;gap:8px;align-items:end;margin-bottom:18px;flex-wrap:wrap">
        <label for="username-filter">نام کاربری تلگرام</label>
        <input id="username-filter" name="username" type="search" value="{{ $username }}" placeholder="@username" autocomplete="off">
        <button class="btn btn-primary" type="submit">فیلتر</button>
        @if ($username !== '')
            <a class="btn btn-ghost" href="{{ route('panel.telegram-webhooks.index') }}">پاک کردن</a>
        @endif
    </form>
    @if ($traces->isEmpty())
        <div class="empty" role="status"><div class="big">⌁</div><p>{{ $username !== '' ? 'رخدادی برای این نام کاربری پیدا نشد.' : 'هنوز رخدادی ثبت نشده است.' }}</p></div>
    @else
        <div class="table-wrap"><table class="table"><thead><tr><th>زمان</th><th>شناسهٔ پیگیری</th><th>به‌روزرسانی</th><th>فرستنده تلگرام</th><th>وضعیت</th><th>آخرین مرحله</th><th>عملیات</th></tr></thead>
        <tbody>@foreach ($traces as $trace)<tr>
            <td>{{ $trace->created_at->format('Y-m-d H:i:s') }}</td><td class="mono">{{ $trace->correlation_id }}</td><td>{{ $trace->update_id ?? '—' }}</td>
            <td class="mono">{{ $trace->sender_username ? '@'.$trace->sender_username : ($trace->sender_telegram_id ?? '—') }}</td>
            <td><span class="badge {{ $trace->outcome === 'failed' ? 'priority-critical' : 'closed' }}">{{ $trace->outcome }}</span></td><td class="mono">{{ $trace->latest_checkpoint }}</td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('panel.telegram-webhooks.show', $trace) }}">جزئیات</a></td>
        </tr>@endforeach</tbody></table></div>
        <div style="margin-top:16px">{{ $traces->links() }}</div>
    @endif
@endsection
