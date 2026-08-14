<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalCorsSanctumTest extends TestCase
{
    private const LOCAL_ORIGINS = [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];

    public function test_local_frontend_origins_receive_credentialed_cors_preflight_headers(): void
    {
        foreach (self::LOCAL_ORIGINS as $origin) {
            $this->withHeaders([
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
            ])->options('/api/login')
                ->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_untrusted_origin_is_not_granted_cors_access(): void
    {
        $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/login')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_csrf_cookie_endpoint_supports_both_local_origins(): void
    {
        foreach (self::LOCAL_ORIGINS as $origin) {
            $this->withHeader('Origin', $origin)
                ->get('/sanctum/csrf-cookie')
                ->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true')
                ->assertCookie('XSRF-TOKEN', null, false);
        }
    }

    public function test_stateful_login_me_refresh_and_logout_keep_cors_headers(): void
    {
        DB::table('admin_users')->insert([
            'username' => 'cors-login-test',
            'password_hash' => Hash::make('testing-password'),
            'full_name' => 'CORS Login Test',
            'role_id' => DB::table('roles')->where('name', 'Cashier')->value('id'),
            'status' => 'Active',
        ]);
        $origin = 'http://localhost:5173';
        $this->withHeaders([
            'Origin' => $origin,
            'Referer' => "{$origin}/",
        ]);

        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->postJson('/api/login', [
            'username' => 'cors-login-test',
            'password' => 'testing-password',
        ])->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertJsonPath('user.username', 'cors-login-test');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertJsonPath('user.username', 'cors-login-test');
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'cors-login-test');

        $this->postJson('/api/logout')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $origin);
        $this->assertGuest('web');
    }

    public function test_unauthenticated_me_keeps_401_and_credentialed_cors_headers(): void
    {
        $origin = 'http://127.0.0.1:5173';

        $this->withHeader('Origin', $origin)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_sanctum_stateful_domains_include_both_local_host_forms(): void
    {
        $this->assertEqualsCanonicalizing([
            'localhost:5173',
            '127.0.0.1:5173',
            'localhost',
            '127.0.0.1',
        ], config('sanctum.stateful'));
    }
}
