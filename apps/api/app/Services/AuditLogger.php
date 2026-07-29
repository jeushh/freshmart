<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    public static function record(
        Request $request,
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        array $details = [],
    ): void {
        DB::table('audit_logs')->insert([
            'username' => $request->user()?->username,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
