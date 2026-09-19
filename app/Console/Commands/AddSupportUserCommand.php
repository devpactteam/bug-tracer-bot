<?php

namespace App\Console\Commands;

use App\Models\SupportUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class AddSupportUserCommand extends Command
{
    protected $signature = 'support-user:add';

    protected $description = 'Add or update a support user (assignee) with its covered categories.';

    public function handle(): int
    {
        $user = new SupportUser;

        $user->name = (string) $this->ask('نام و نام خانوادگی', '');
        if ($user->name === '') {
            $this->error('نام نمی‌تواند خالی باشد.');

            return self::FAILURE;
        }

        $user->username = mb_strtolower(ltrim(trim((string) $this->ask('یوزرنیم تلگرام (بدون @)', '')), '@'));
        if ($user->username === '' || mb_strlen($user->username) > 190 || SupportUser::query()->whereRaw('LOWER(username) = ?', [$user->username])->exists()) {
            $this->error('یوزرنیم باید غیرخالی، حداکثر ۱۹۰ کاراکتر و یکتا باشد.');

            return self::FAILURE;
        }

        $password = $this->secret('رمز عبور (حداقل ۸ کاراکتر)');
        $confirmation = $this->secret('تکرار رمز عبور');
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'string', 'min:8', 'max:72', 'confirmed']]);
        if ($validator->fails() || strlen((string) $password) > 72 || str_contains((string) $password, "\0")) {
            $this->error('رمز عبور باید حداقل ۸ کاراکتر و حداکثر ۷۲ بایت باشد و با تکرار آن مطابقت داشته باشد.');

            return self::FAILURE;
        }
        $user->password = $password;

        $user->role = (string) $this->choice('نقش', [
            'frontend_developer' => 'دولوپر فرانت‌اند',
            'backend_developer' => 'دولوپر بک‌اند',
            'project_manager' => 'مدیر پروژه',
            'scrum_master' => 'اسکرام مستر',
            'ceo' => 'مدیر عامل',
            'support' => 'پشتیبانی',
        ], 'support');

        $categories = config('incident.categories', []);
        $selected = $this->choice(
            'دسته‌بندی مشکلاتی که پوشش می‌دهد (چند گزینه، با کاما جدا کنید)',
            [...array_keys($categories), 'all'],
            'all',
            null,
            true
        );
        if (! is_array($selected) || $selected === [] || in_array('all', $selected, true)) {
            $user->categories_covered = null;
        } else {
            $user->categories_covered = $selected;
        }

        $user->can_be_assignee = $this->confirm('می‌تواند مسئول تیکت باشد؟', true);
        $user->auto_assign_on_mention = $this->confirm('اگر نامش در گزارش ذکر شد، تیکت به او اختصاص داده شود؟', false);
        $user->is_active = $this->confirm('فعال باشد؟', true);

        $user->save();

        $this->info("کاربر «{$user->name}» با موفقیت ثبت شد.");
        $this->line('دسته‌ها: '.$user->coveredCategoryLabelText());

        return self::SUCCESS;
    }
}
