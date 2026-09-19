<?php

namespace Tests\Feature\Panel;

use App\Models\SupportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PanelTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(SupportUser::query()->create([
            'name' => 'مدیر پنل',
            'username' => 'panel_operator',
            'password' => 'panel-test-password',
            'role' => 'support',
            'is_active' => true,
        ]), 'web');
    }
}
