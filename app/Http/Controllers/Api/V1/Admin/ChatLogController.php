<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatPromptLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nhật ký prompt chatbot tư vấn: câu hỏi của user + prompt cá nhân hóa cuối cùng
 * gửi Gemini + câu trả lời. Dùng để đối chiếu mức độ cá nhân hóa của hệ thống.
 */
class ChatLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'nullable|integer',
            'in_scope' => 'nullable|boolean',
            'search'   => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        $query = ChatPromptLog::query()->with('user:id,name,email')->latest();

        if ($userId = $request->query('user_id'))            $query->where('user_id', $userId);
        if ($request->filled('in_scope'))                    $query->where('in_scope', $request->boolean('in_scope'));
        if ($search = $request->query('search'))              $query->where('user_message', 'like', "%{$search}%");

        $paginator = $query->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ChatPromptLog $l) => [
                'id'           => $l->id,
                'user'         => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
                'user_message' => $l->user_message,
                'reply'        => $l->reply,
                'model'        => $l->model,
                'in_scope'     => $l->in_scope,
                'has_prompt'   => $l->final_prompt !== null,
                'created_at'   => optional($l->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(ChatPromptLog $chatLog): JsonResponse
    {
        $chatLog->loadMissing('user:id,name,email');

        return response()->json([
            'id'           => $chatLog->id,
            'user'         => $chatLog->user ? ['id' => $chatLog->user->id, 'name' => $chatLog->user->name, 'email' => $chatLog->user->email] : null,
            'user_message' => $chatLog->user_message,
            'final_prompt' => $chatLog->final_prompt,
            'reply'        => $chatLog->reply,
            'model'        => $chatLog->model,
            'in_scope'     => $chatLog->in_scope,
            'created_at'   => optional($chatLog->created_at)->toIso8601String(),
        ]);
    }
}
