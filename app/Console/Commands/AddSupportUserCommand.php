<?php

namespace App\Console\Commands;

use App\Models\SupportUser;
use Illuminate\Console\Command;

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

        $user->username = ltrim((string) $this->ask('یوزرنیم تلگرام (بدون @)', ''), '@');
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
