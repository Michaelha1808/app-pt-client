<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'admin_id' => 'nullable|integer',
            'action'   => 'nullable|string|max:60',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        $query = AdminAuditLog::query()->with('admin:id,name,email')->latest();

        if ($adminId = $request->query('admin_id')) $query->where('admin_id', $adminId);
        if ($action = $request->query('action'))     $query->where('action', $action);

        $paginator = $query->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($l) => [
                'id'          => $l->id,
                'admin'       => $l->admin ? ['id' => $l->admin->id, 'name' => $l->admin->name, 'email' => $l->admin->email] : null,
                'action'      => $l->action,
                'target_type' => $l->target_type,
                'target_id'   => $l->target_id,
                'meta'        => $l->meta,
                'ip'          => $l->ip,
                'created_at'  => optional($l->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
