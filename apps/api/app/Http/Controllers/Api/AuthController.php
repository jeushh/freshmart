<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    public function login(Request $r)
    {
        $d = $r->validate(['username' => 'required|string|max:60', 'password' => 'required|string|max:255']);
        $u = User::with('role')->where('username', $d['username'])->where('status', 'Active')->first();
        if (! $u || ! Hash::check($d['password'], $u->password_hash)) {
            throw ValidationException::withMessages(['username' => ['Invalid credentials.']]);
        }
        Auth::guard('web')->login($u);
        $r->session()->regenerate();
        $u->update(['last_login' => now()->format('Y-m-d H:i:s')]);

        return $this->me($r);
    }

    public function me(Request $r)
    {
        $u = $r->user()?->load('role');
        abort_unless($u, 401);

        return response()->json([
            'user' => [
                'id' => $u->id,
                'username' => $u->username,
                'full_name' => $u->full_name,
                'employee_id' => $u->employee_id,
            ],
            'permissions' => $u->role?->permissions ?? [],
            'landing_page' => $u->role?->landing_page ?? 'dashboard',
            'settings' => $this->settings->public(),
        ]);
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }
}
