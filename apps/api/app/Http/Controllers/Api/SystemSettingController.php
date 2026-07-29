<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\SystemSettingCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    public function index()
    {
        $values = DB::table('system_settings')
            ->whereIn('setting_key', array_keys(SystemSettingCatalog::FIELDS))
            ->pluck('setting_value', 'setting_key');

        return [
            'groups' => collect(SystemSettingCatalog::FIELDS)
                ->map(function (array $field, string $key) use ($values) {
                    unset($field['rules']);
                    $field['key'] = $key;
                    $field['value'] = $this->typedValue(
                        $field['type'],
                        $values[$key] ?? '',
                    );

                    return $field;
                })
                ->groupBy('group')
                ->map(fn ($fields) => $fields->values())
                ->all(),
        ];
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'present',
        ]);
        $submitted = $request->input('settings');
        $unknown = array_diff(
            array_keys($submitted),
            array_keys(SystemSettingCatalog::FIELDS),
        );
        abort_if($unknown !== [], 422, 'One or more setting keys are not editable.');

        $rules = [];
        foreach (array_keys($submitted) as $key) {
            $rules["settings.{$key}"] = SystemSettingCatalog::FIELDS[$key]['rules'];
        }
        $data = $request->validate($rules)['settings'];

        return DB::transaction(function () use ($data, $request) {
            $old = DB::table('system_settings')
                ->whereIn('setting_key', array_keys($data))
                ->pluck('setting_value', 'setting_key')
                ->all();
            $new = [];

            foreach ($data as $key => $value) {
                $stored = $this->storedValue(
                    SystemSettingCatalog::FIELDS[$key]['type'],
                    $value,
                );
                DB::table('system_settings')->updateOrInsert(
                    ['setting_key' => $key],
                    [
                        'setting_value' => $stored,
                        'updated_at' => now()->format('Y-m-d H:i:s'),
                    ],
                );
                $new[$key] = $stored;
            }

            AuditLogger::record($request, 'settings.updated', 'system_settings', null, [
                'old' => $old,
                'new' => $new,
            ]);

            return $this->index();
        });
    }

    private function typedValue(string $type, string $value): string|float|bool
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'number' => (float) $value,
            default => $value,
        };
    }

    private function storedValue(string $type, mixed $value): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'number' => (string) round((float) $value, 2),
            default => (string) ($value ?? ''),
        };
    }
}
