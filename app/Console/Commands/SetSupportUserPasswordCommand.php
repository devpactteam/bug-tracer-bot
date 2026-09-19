<?php

namespace App\Console\Commands;

use App\Models\SupportUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SetSupportUserPasswordCommand extends Command
{
    protected $signature = 'support-user:password {username : Telegram username}';

    protected $description = 'Set or reset the panel password for an existing support user.';

    public function handle(): int
    {
        $username = mb_strtolower(ltrim(trim((string) $this->argument('username')), '@'));
        $user = SupportUser::query()->whereRaw('LOWER(username) = ?', [$username])->first();

        if (! $user) {
            $this->error('کاربر یافت نشد.');

            return self::FAILURE;
        }

        $password = $this->secret('رمز عبور جدید (حداقل ۸ کاراکتر)');
        $confirmation = $this->secret('تکرار رمز عبور');
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'string', 'min:8', 'max:72', 'confirmed']]);

        if ($validator->fails() || strlen((string) $password) > 72 || str_contains((string) $password, "\0")) {
            $this->error('رمز عبور باید حداقل ۸ کاراکتر و حداکثر ۷۲ بایت باشد و با تکرار آن مطابقت داشته باشد.');

            return self::FAILURE;
        }

        $user->update(['password' => $password]);
        $this->info('رمز عبور «'.$user->name.'» تنظیم شد.');

        return self::SUCCESS;
    }
}
