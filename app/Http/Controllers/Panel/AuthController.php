<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('panel.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['bail', 'required', 'string', 'max:72', function ($attribute, $value, $fail): void {
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('رمز عبور نامعتبر است.');
                }
            }],
        ], [
            'username.required' => 'یوزرنیم را وارد کنید.',
            'password.required' => 'رمز عبور را وارد کنید.',
        ]);

        $username = mb_strtolower(ltrim(trim($data['username']), '@'));
        $key = 'panel-login:'.hash('sha256', $username.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'تعداد تلاش‌ها بیش از حد مجاز است. '.RateLimiter::availableIn($key).' ثانیه دیگر دوباره تلاش کنید.',
            ]);
        }

        if (! Auth::guard('web')->attempt([
            fn ($query) => $query->whereRaw('LOWER(username) = ?', [$username]),
            'password' => $data['password'],
            'is_active' => true,
        ])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'username' => 'یوزرنیم یا رمز عبور نادرست است.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('panel.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
