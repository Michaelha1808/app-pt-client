<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lịch sử chat của user (khác hẳn Admin\ChatLogController — đó là log audit nội bộ
 * chứa system prompt, không lộ ra ngoài). Chỉ trả về hội thoại của chính user đang đăng nhập.
 */
class ChatHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'nullable|integer|between:1,50']);

        $paginator = $request->user()->chatConversations()
            ->whereHas('messages')
            ->withCount('messages')
            // 2 tin nhắn (user + ai) trong cùng lượt thường trùng giây tạo → id DESC làm tie-break
            // để chắc chắn lấy đúng tin CUỐI (thường là câu trả lời AI) chứ không phải câu hỏi user.
            ->with(['messages' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ChatConversation $c) => [
                'id'               => $c->id,
                'title'            => $c->title,
                'preview'          => Str::limit($c->messages->first()?->text ?? '', 100),
                'message_count'    => $c->messages_count,
                'last_message_at'  => optional($c->last_message_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 403);

        return response()->json([
            'id'         => $conversation->id,
            'title'      => $conversation->title,
            'created_at' => $conversation->created_at->toIso8601String(),
            'messages'   => $conversation->messages->map(fn ($m) => [
                'role'       => $m->role,
                'text'       => $m->text,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function destroy(Request $request, ChatConversation $conversation): \Illuminate\Http\Response
    {
        abort_if($conversation->user_id !== $request->user()->id, 403);

        $conversation->delete();

        return response()->noContent();
    }
}
