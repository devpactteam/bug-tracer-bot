<?php

namespace Tests\Feature\Panel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.force_https' => true,
            'app.https_hsts_max_age' => 31536000,
        ]);
    }

    public function test_login_page_redirects_to_https_before_rendering_the_form(): void
    {
        $this->get('/panel/login')
            ->assertRedirect('https://localhost/panel/login');
    }

    public function test_insecure_login_submission_is_rejected(): void
    {
        $this->post('/panel/login', [
            'username' => 'panel_user',
            'password' => 'password',
        ])->assertBadRequest();
    }

    public function test_secure_login_page_sends_hsts_header(): void
    {
        $this->get('https://localhost/panel/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
