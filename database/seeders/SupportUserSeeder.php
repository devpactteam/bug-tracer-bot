<?php

namespace Database\Seeders;

use App\Models\SupportUser;
use Illuminate\Database\Seeder;

class SupportUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'username' => 'sifb71',
                'name' => 'سید ایمان فیض‌بخش',
                'role' => 'ceo',
                'categories_covered' => null,
                'can_be_assignee' => false,
                'auto_assign_on_mention' => false,
            ],
            [
                'username' => 'ayubi_developer',
                'name' => 'سید حسن ایوبی',
                'role' => 'project_manager',
                'categories_covered' => ['system_analysis'],
                'can_be_assignee' => true,
                'auto_assign_on_mention' => true,
            ],
            [
                'username' => 'Kcartelll',
                'name' => 'امیرحسین طحان‌پور',
                'role' => 'frontend_developer',
                'categories_covered' => ['client'],
                'can_be_assignee' => true,
                'auto_assign_on_mention' => false,
            ],
            [
                'username' => 'AliRahjoo',
                'name' => 'علی راهجو',
                'role' => 'frontend_developer',
                'categories_covered' => ['client'],
                'can_be_assignee' => true,
                'auto_assign_on_mention' => false,
            ],
        ];

        foreach ($users as $user) {
            SupportUser::query()->updateOrCreate(
                ['username' => $user['username']],
                $user,
            );
        }
    }
}
