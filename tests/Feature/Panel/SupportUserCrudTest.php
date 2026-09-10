<?php

namespace Tests\Feature\Panel;

use App\Models\SupportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportUserCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_users(): void
    {
        SupportUser::query()->create([
            'name' => 'کاربر تست',
            'username' => 'test_user',
            'role' => 'backend_developer',
            'categories_covered' => ['backend'],
            'can_be_assignee' => true,
            'is_active' => true,
        ]);

        $this->get('/panel/users')
            ->assertOk()
            ->assertSee('کاربر تست')
            ->assertSee('کاربر جدید');
    }

    public function test_it_creates_a_user(): void
    {
        $response = $this->post('/panel/users', [
            'name' => 'علی جدید',
            'username' => '@AliNewUser',
            'role' => 'frontend_developer',
            'categories' => ['client'],
            'can_be_assignee' => '1',
            'is_active' => '1',
            'is_default_assignee' => '0',
            'auto_assign_on_mention' => '0',
        ]);

        $response->assertRedirect(route('panel.users.index'));

        $this->assertDatabaseHas('support_users', [
            'name' => 'علی جدید',
            'username' => 'alinewuser',
            'role' => 'frontend_developer',
            'can_be_assignee' => 1,
        ]);
        $this->assertSame(['client'], SupportUser::where('username', 'alinewuser')->value('categories_covered'));
    }

    public function test_it_rejects_duplicate_username_case_insensitively(): void
    {
        SupportUser::query()->create([
            'name' => 'اولی',
            'username' => 'SameUser',
            'role' => 'backend_developer',
        ]);

        $this->from('/panel/users/create')->post('/panel/users', [
            'name' => 'دومی',
            'username' => 'sameuser',
            'role' => 'frontend_developer',
        ])->assertSessionHasErrors('username');
    }

    public function test_it_updates_a_user(): void
    {
        $user = SupportUser::query()->create([
            'name' => 'قبلی',
            'username' => 'old_name',
            'role' => 'backend_developer',
            'categories_covered' => ['backend'],
            'can_be_assignee' => true,
        ]);

        $this->put(route('panel.users.update', $user), [
            'name' => 'جدید',
            'username' => 'new_name',
            'role' => 'technical_lead',
            'categories' => ['backend', 'processmaker'],
            'can_be_assignee' => '1',
            'is_active' => '1',
            'is_default_assignee' => '0',
            'auto_assign_on_mention' => '0',
        ])->assertRedirect(route('panel.users.index'));

        $this->assertDatabaseHas('support_users', [
            'id' => $user->id,
            'name' => 'جدید',
            'username' => 'new_name',
            'role' => 'technical_lead',
        ]);
        $this->assertSame(['backend', 'processmaker'], $user->fresh()->categories_covered);
    }

    public function test_a_cover_all_user_stores_null_categories(): void
    {
        $this->post('/panel/users', [
            'name' => 'همه‌کاره',
            'username' => 'all_rounder',
            'role' => 'support',
            'cover_all_categories' => '1',
            'can_be_assignee' => '1',
            'is_active' => '1',
        ])->assertRedirect(route('panel.users.index'));

        $this->assertNull(SupportUser::where('username', 'all_rounder')->value('categories_covered'));
    }

    public function test_it_deletes_a_user_and_detaches_their_tickets(): void
    {
        $user = SupportUser::query()->create([
            'name' => 'حذف‌شدنی',
            'username' => 'to_delete',
            'role' => 'qa',
            'can_be_assignee' => true,
        ]);

        $this->delete(route('panel.users.destroy', $user))
            ->assertRedirect(route('panel.users.index'));

        $this->assertDatabaseMissing('support_users', ['id' => $user->id]);
    }
}
