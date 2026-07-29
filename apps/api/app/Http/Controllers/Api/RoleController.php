<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $roles = DB::table('roles')
            ->select('roles.*')
            ->selectSub(
                DB::table('admin_users')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('admin_users.role_id', 'roles.id')
                    ->where('admin_users.status', 'Active'),
                'active_user_count',
            )
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 20)
            ->through(fn ($role) => $this->serializeRole($role));

        return [
            'roles' => $roles,
            'permission_groups' => PermissionCatalog::GROUPS,
            'landing_pages' => PermissionCatalog::LANDING_PAGES,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $request) {
            $id = DB::table('roles')->insertGetId([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'permissions' => json_encode($data['permissions'], JSON_THROW_ON_ERROR),
                'landing_page' => $data['landing_page'],
                'is_system' => 0,
            ]);
            AuditLogger::record($request, 'role.created', 'role', $id, [
                'name' => $data['name'],
                'landing_page' => $data['landing_page'],
                'permissions' => $data['permissions'],
            ]);

            return response()->json(
                $this->serializeRole(DB::table('roles')->find($id)),
                201,
            );
        });
    }

    public function update(Request $request, int $role)
    {
        $data = $this->validated($request, $role);

        return DB::transaction(function () use ($data, $request, $role) {
            $current = DB::table('roles')->where('id', $role)->lockForUpdate()->first();
            abort_unless($current, 404);
            abort_if(
                (bool) $current->is_system && $data['name'] !== $current->name,
                422,
                'Built-in roles cannot be renamed.',
            );

            $oldPermissions = json_decode(
                $current->permissions,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->ensureRoleManagementContinuity(
                $role,
                $oldPermissions,
                $data['permissions'],
            );

            DB::table('roles')->where('id', $role)->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'permissions' => json_encode($data['permissions'], JSON_THROW_ON_ERROR),
                'landing_page' => $data['landing_page'],
            ]);
            AuditLogger::record($request, 'role.updated', 'role', $role, [
                'old' => [
                    'name' => $current->name,
                    'description' => $current->description,
                    'landing_page' => $current->landing_page,
                    'permissions' => $oldPermissions,
                ],
                'new' => [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'landing_page' => $data['landing_page'],
                    'permissions' => $data['permissions'],
                ],
            ]);

            return $this->serializeRole(DB::table('roles')->find($role));
        });
    }

    private function validated(Request $request, ?int $role = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->ignore($role),
            ],
            'description' => 'nullable|string|max:500',
            'landing_page' => [
                'required',
                Rule::in(PermissionCatalog::LANDING_PAGES),
            ],
            'permissions' => 'present|array',
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(PermissionCatalog::all()),
            ],
        ]);
        $data['permissions'] = array_values(array_unique($data['permissions']));
        sort($data['permissions']);

        return $data;
    }

    private function ensureRoleManagementContinuity(
        int $roleId,
        array $oldPermissions,
        array $newPermissions,
    ): void {
        if (
            ! in_array('system.roles.manage', $oldPermissions, true)
            || in_array('system.roles.manage', $newPermissions, true)
        ) {
            return;
        }

        $activeUsersOnRole = DB::table('admin_users')
            ->where('role_id', $roleId)
            ->where('status', 'Active')
            ->exists();
        if (! $activeUsersOnRole) {
            return;
        }

        $otherManagerRoleIds = DB::table('roles')
            ->where('id', '!=', $roleId)
            ->get(['id', 'permissions'])
            ->filter(fn ($role) => in_array(
                'system.roles.manage',
                json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR),
                true,
            ))
            ->pluck('id');

        abort_unless(
            DB::table('admin_users')
                ->where('status', 'Active')
                ->whereIn('role_id', $otherManagerRoleIds)
                ->exists(),
            422,
            'At least one active account must retain role-management permission.',
        );
    }

    private function serializeRole(object $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => json_decode(
                $role->permissions,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'landing_page' => $role->landing_page,
            'is_system' => (bool) $role->is_system,
            'active_user_count' => (int) ($role->active_user_count ?? 0),
            'created_at' => $role->created_at,
        ];
    }
}
