@extends('panel.layout')

@section('title', 'ورود به پنل')

@section('content')
    <div class="card" style="max-width:440px; margin:60px auto">
        <h1 class="page-title">ورود به پنل</h1>
        <p style="color:var(--muted); margin-bottom:24px">با یوزرنیم تلگرام و رمز عبور خود وارد شوید.</p>

        @if ($errors->any())
            <div class="alert error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('panel.login') }}">
            @csrf
            <div class="field" style="margin-bottom:18px">
                <label for="username">یوزرنیم تلگرام</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       dir="ltr" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190" required autofocus>
            </div>
            <div class="field">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" dir="ltr" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:24px">ورود</button>
        </form>
        <p class="muted" style="margin-top:20px">برای دریافت یا بازیابی رمز عبور با مدیر پنل تماس بگیرید.</p>
    </div>
@endsection
