@extends('panel.layout')

@section('title', 'جزئیات وب‌هوک')

@section('content')
    <a class="btn btn-ghost btn-sm back" href="{{ route('panel.telegram-webhooks.index') }}">بازگشت به رخدادها</a>
    <h1 class="page-title">جزئیات وب‌هوک</h1>
    <section class="card" aria-labelledby="trace-summary"><h2 id="trace-summary">خلاصه</h2><dl class="kv">
        <dt>شناسهٔ پیگیری</dt><dd class="mono">{{ $trace->correlation_id }}</dd><dt>به‌روزرسانی</dt><dd>{{ $trace->update_id ?? '—' }}</dd><dt>وضعیت</dt><dd>{{ $trace->outcome }}</dd><dt>خطا</dt><dd>{{ $trace->failure_message ?? '—' }}</dd>
    </dl></section>
    <section class="card" aria-labelledby="trace-events"><h2 id="trace-events">روند پردازش</h2><div class="timeline">
        @forelse ($trace->events as $event)<article class="entry status"><span class="icon" aria-hidden="true">•</span><div class="head"><strong class="title mono">{{ $event->checkpoint }}</strong><time class="time">{{ $event->created_at->format('Y-m-d H:i:s') }}</time></div>
            @if ($event->metadata)<pre class="pre-block">{{ json_encode($event->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif</article>
        @empty<p class="muted">مرحله‌ای ثبت نشده است.</p>@endforelse
    </div></section>
@endsection
