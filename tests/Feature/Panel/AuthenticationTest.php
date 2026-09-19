<?php

namespace Tests\Feature\Panel;

use App\Models\SupportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): SupportUser
    {
        return SupportUser::query()->create(array_merge([
            'name' => 'کاربر پنل',
            'username' => 'PanelUser',
            'password' => 'correct-password',
            'is_active' => true,
        ], $attributes));
    }

    public function test_all_panel_routes_require_login(): void
    {
        foreach ([
            ['GET', '/panel'],
            ['GET', '/panel/report'],
            ['GET', '/panel/sessions'],
            ['POST', '/panel/sessions/1/close'],
            ['GET', '/panel/tickets/1'],
            ['POST', '/panel/tickets/1/close'],
            ['GET', '/panel/users'],
            ['GET', '/panel/users/create'],
            ['POST', '/panel/users'],
            ['GET', '/panel/users/1/edit'],
            ['PUT', '/panel/users/1'],
            ['DELETE', '/panel/users/1'],
            ['POST', '/panel/users/1/avatar'],
            ['POST', '/panel/logout'],
        ] as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }

        $this->assertDatabaseCount('support_users', 0);
        $this->getJson('/panel/users')->assertUnauthorized();
        $this->get('/panel/login')->assertOk()->assertSee('ورود به پنل')->assertDontSee('لیست تیکت‌ها');
    }

    public function test_login_accepts_normalized_username_and_returns_to_intended_page(): void
    {
        $user = $this->makeUser();
        $this->get('/panel/report')->assertRedirect(route('login'));
        $sessionId = session()->getId();

        $this->post('/panel/login', [
            'username' => ' @PANELUSER ',
            'password' => 'correct-password',
        ])->assertRedirect(route('panel.report'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->get('/panel/report')->assertOk()->assertSee('خروج');
        $this->get('/panel/login')->assertRedirect(route('panel.index'));
    }

    public function test_wrong_unknown_inactive_and_uninitialized_accounts_cannot_login(): void
    {
        $this->makeUser();
        $this->makeUser(['username' => 'inactive', 'is_active' => false]);
        $this->makeUser(['username' => 'no_password', 'password' => null]);

        foreach ([
            ['PanelUser', 'wrong-password'],
            ['unknown', 'correct-password'],
            ['inactive', 'correct-password'],
            ['no_password', 'correct-password'],
        ] as [$username, $password]) {
            $this->from('/panel/login')->post('/panel/login', compact('username', 'password'))
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['username' => 'یوزرنیم یا رمز عبور نادرست است.'])
                ->assertSessionMissing('_old_input.password');
            $this->assertGuest('web');
        }
    }

    public function test_login_is_throttled_even_when_username_case_and_prefix_change(): void
    {
        $this->makeUser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/panel/login', ['username' => 'PanelUser', 'password' => 'wrong-password'])
                ->assertSessionHasErrors('username');
        }

        $this->post('/panel/login', ['username' => '@PANELUSER', 'password' => 'correct-password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest('web');

        $this->travel(61)->seconds();
        $this->post('/panel/login', ['username' => 'paneluser', 'password' => 'correct-password'])
            ->assertRedirect(route('panel.index'));
        $this->assertAuthenticated('web');
        $this->assertSame(0, RateLimiter::attempts('panel-login:'.hash('sha256', 'paneluser|127.0.0.1')));
    }

    public function test_login_rejects_passwords_over_the_bcrypt_byte_limit(): void
    {
        $password = str_repeat('ر', 36);
        $this->makeUser(['password' => $password]);
        $this->post('/panel/login', ['username' => 'paneluser', 'password' => $password.'extra'])
            ->assertSessionHasErrors('password');
        $this->assertGuest('web');
    }

    public function test_logout_invalidates_session_and_blocks_further_access(): void
    {
        $this->actingAs($this->makeUser(), 'web');
        $this->withSession(['private_marker' => 'value']);
        $oldToken = session()->token();
        $this->post('/panel/logout')->assertRedirect(route('login'))->assertSessionMissing('private_marker');
        $this->assertGuest('web');
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/panel')->assertRedirect(route('login'));
    }

    public function test_disabling_an_already_signed_in_user_revokes_panel_access(): void
    {
        $user = $this->makeUser();
        $this->post('/panel/login', ['username' => 'paneluser', 'password' => 'correct-password']);
        $this->get('/panel')->assertOk();

        $user->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->get('/panel')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_password_reset_revokes_an_existing_session(): void
    {
        $user = $this->makeUser();
        $this->post('/panel/login', ['username' => 'paneluser', 'password' => 'correct-password']);
        $this->get('/panel')->assertOk();

        $user->update(['password' => 'replacement-password']);
        Auth::forgetGuards();

        $this->get('/panel')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_command_sets_a_legacy_users_password_without_printing_it(): void
    {
        $user = $this->makeUser(['password' => null]);
        $this->artisan('support-user:password', ['username' => '@PANELUSER'])
            ->expectsQuestion('رمز عبور جدید (حداقل ۸ کاراکتر)', 'replacement-password')
            ->expectsQuestion('تکرار رمز عبور', 'replacement-password')
            ->doesntExpectOutput('replacement-password')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->post('/panel/login', ['username' => 'paneluser', 'password' => 'replacement-password'])
            ->assertRedirect(route('panel.index'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_password_command_rejects_invalid_confirmation_without_changing_password(): void
    {
        $user = $this->makeUser();
        $oldHash = $user->password;
        $this->artisan('support-user:password', ['username' => 'paneluser'])
            ->expectsQuestion('رمز عبور جدید (حداقل ۸ کاراکتر)', 'replacement-password')
            ->expectsQuestion('تکرار رمز عبور', 'different-password')
            ->assertFailed();

        $this->assertSame($oldHash, $user->fresh()->password);
        $this->artisan('support-user:password', ['username' => 'unknown'])->assertFailed();
    }
}
