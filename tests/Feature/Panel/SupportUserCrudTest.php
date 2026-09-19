<?php

namespace Tests\Feature\Panel;

use App\Models\SupportUser;
use Illuminate\Support\Facades\Hash;

class SupportUserCrudTest extends PanelTestCase
{
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
            ->assertSee('کاربر جدید')
            ->assertSee('@test_user')
            ->assertDontSee('{{ $user->username }}');
    }

    public function test_it_creates_a_user(): void
    {
        $response = $this->post('/panel/users', [
            'name' => 'علی جدید',
            'username' => '@AliNewUser',
            'password' => 'new-user-password',
            'password_confirmation' => 'new-user-password',
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
        $user = SupportUser::where('username', 'alinewuser')->firstOrFail();
        $this->assertTrue(Hash::check('new-user-password', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
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
            'password' => 'original-password',
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
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_password_validation_and_replacement(): void
    {
        $payload = ['name' => 'کاربر جدید', 'username' => 'password_user', 'role' => 'support', 'is_active' => '1'];

        foreach ([null, 'short', str_repeat('a', 73), str_repeat('ر', 40), ['invalid-array'], "password\0invalid"] as $password) {
            $this->post('/panel/users', $payload + ['password' => $password, 'password_confirmation' => $password])
                ->assertSessionHasErrors('password');
        }
        $this->post('/panel/users', $payload + ['password' => 'valid-password', 'password_confirmation' => 'different-password'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseMissing('support_users', ['username' => 'password_user']);

        $user = SupportUser::query()->create($payload + ['password' => 'original-password']);
        $hash = $user->password;
        $this->put(route('panel.users.update', $user), $payload + ['password' => '', 'password_confirmation' => ''])
            ->assertRedirect(route('panel.users.index'));
        $this->assertSame($hash, $user->fresh()->password);

        $this->put(route('panel.users.update', $user), $payload + ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])
            ->assertRedirect(route('panel.users.index'));
        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('original-password', $user->fresh()->password));

        $this->get(route('panel.users.edit', $user))
            ->assertOk()
            ->assertDontSee($user->fresh()->password)
            ->assertDontSee('replacement-password');
    }

    public function test_legacy_user_requires_an_initial_password_when_edited(): void
    {
        $user = SupportUser::query()->create(['name' => 'قدیمی', 'username' => 'legacy_user']);
        $payload = ['name' => 'قدیمی', 'username' => 'legacy_user', 'role' => 'support', 'is_active' => '1'];
        $this->put(route('panel.users.update', $user), $payload)->assertSessionHasErrors('password');
        $this->put(route('panel.users.update', $user), $payload + ['password' => 'initial-password', 'password_confirmation' => 'initial-password'])
            ->assertRedirect(route('panel.users.index'));
        $this->assertTrue(Hash::check('initial-password', $user->fresh()->password));
    }

    public function test_a_cover_all_user_stores_null_categories(): void
    {
        $this->post('/panel/users', [
            'name' => 'همه‌کاره',
            'username' => 'all_rounder',
            'password' => 'new-user-password',
            'password_confirmation' => 'new-user-password',
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
