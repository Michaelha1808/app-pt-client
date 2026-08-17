<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatPromptLog;
use App\Services\ChatService;
use App\Services\MealPlanService;
use App\Services\PreferenceService;
use App\Support\UsageTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Tư vấn dinh dưỡng & kế hoạch ăn uống/tập luyện — SSE streaming.
     * Mỗi request rebuild ngữ cảnh từ DB → luôn dựa trên dữ liệu mới nhất.
     */
    public function send(Request $request, ChatService $service, PreferenceService $preferences): StreamedResponse|JsonResponse
    {
        if ($disabled = $this->chatDisabled()) return $disabled;

        $request->validate([
            'messages'          => 'required|array|min:1|max:30',
            'messages.*.role'   => 'required|string|in:user,ai,model',
            'messages.*.text'   => 'required|string|max:2000',
            'conversation_id'   => 'nullable|integer',
        ]);

        // Không bắt buộc auth: resolve user qua sanctum guard nếu có Bearer token (khách → null)
        $user     = $request->user('sanctum');
        $messages = $request->input('messages');

        UsageTracker::record('chat', $user?->id);

        $lastUserMessage = collect($messages)->reverse()
            ->first(fn ($m) => ($m['role'] ?? 'user') === 'user')['text'] ?? '';

        // Chỉ user đăng nhập mới có lịch sử lưu server-side. Nạp conversation nếu client gửi
        // kèm id (đang tiếp tục phiên hôm nay), hoặc tạo mới nếu đây là tin đầu tiên/sau "Làm mới".
        $conversation = null;
        if ($user) {
            $conversationId = $request->input('conversation_id');
            if ($conversationId) {
                $conversation = ChatConversation::where('id', $conversationId)->where('user_id', $user->id)->first();
                abort_if(!$conversation, 403);
            } else {
                $conversation = $user->chatConversations()->create([
                    'title'           => Str::limit($lastUserMessage, 60, ''),
                    'last_message_at' => now(),
                ]);
            }
        }

        return response()->stream(
            function () use ($service, $preferences, $user, $messages, $lastUserMessage, $conversation) {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                if ($conversation) {
                    echo 'data: ' . json_encode(['type' => 'conversation', 'id' => $conversation->id]) . "\n\n";
                    flush();
                }

                $inScope      = true;
                $reply        = '';
                $systemPrompt = null;

                try {
                    // Cổng phân loại: chặn sớm yêu cầu ngoài phạm vi dinh dưỡng/tập luyện
                    if (!$service->isInScope($messages)) {
                        $inScope = false;
                        $reply   = 'Mình là trợ lý dinh dưỡng của CaloEye nên chỉ hỗ trợ về ăn uống, dinh dưỡng và tập luyện thôi nhé 🥗 Bạn muốn mình gợi ý kế hoạch ăn uống cho ngày mai không?';
                        echo 'data: ' . json_encode(['type' => 'text', 'delta' => $reply]) . "\n\n";
                        flush();
                    } else {
                        // Build prompt cá nhân hóa TRƯỚC khi gọi Gemini, để log lại đúng nội dung đã gửi.
                        $systemPrompt = $service->buildSystemPrompt($user);
                        foreach ($service->streamReply($user, $messages, $systemPrompt) as $delta) {
                            $reply .= $delta;
                            echo 'data: ' . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                            flush();
                        }
                    }
                } catch (\Throwable $e) {
                    // Trước đây exception bị nuốt hoàn toàn (không report()) → không có cách nào
                    // debug khi lỗi xảy ra trên production. Log lại kèm user_id để đối chiếu.
                    Log::error('Chat streamReply/isInScope thất bại', [
                        'user_id' => $user?->id,
                        'error'   => $e->getMessage(),
                    ]);
                    report($e);

                    echo 'data: ' . json_encode([
                        'type'    => 'error',
                        'message' => 'Không thể kết nối trợ lý AI. Vui lòng thử lại.',
                    ]) . "\n\n";
                    flush();
                }

                // Lưu lại câu hỏi + prompt cá nhân hóa cuối cùng đã gửi Gemini (phục vụ đối chiếu/kiểm tra).
                try {
                    ChatPromptLog::create([
                        'user_id'      => $user?->id,
                        'user_message' => $lastUserMessage,
                        'final_prompt' => $systemPrompt,
                        'reply'        => $reply,
                        'model'        => $service->modelName(),
                        'in_scope'     => $inScope,
                    ]);
                } catch (\Throwable $e) {
                    report($e); // log lỗi không được làm hỏng chat
                }

                // Lưu lượt hội thoại vào lịch sử của user (để xem lại ở /chat/history).
                if ($conversation) {
                    try {
                        $conversation->messages()->create(['role' => 'user', 'text' => $lastUserMessage]);
                        if ($reply !== '') {
                            $conversation->messages()->create(['role' => 'ai', 'text' => $reply]);
                        }
                        $conversation->update(['last_message_at' => now()]);
                    } catch (\Throwable $e) {
                        report($e); // log lỗi không được làm hỏng chat
                    }
                }

                // Gợi ý nút hành động theo ngữ cảnh (chỉ user đăng nhập — hành động cần dữ liệu cá nhân).
                if ($user && $inScope && $reply !== '') {
                    $actions = $service->suggestActions($reply, $lastUserMessage);
                    if ($actions !== []) {
                        echo 'data: ' . json_encode(['type' => 'actions', 'actions' => $actions]) . "\n\n";
                        flush();
                    }
                }

                // Ghi nhớ sở thích từ lượt user mới nhất (sau khi đã trả lời xong → không tăng độ trễ).
                // Chỉ chạy khi đăng nhập, câu trong phạm vi, và qua cổng heuristic rẻ.
                if ($user && $inScope) {
                    try {
                        if ($preferences->shouldExtract($lastUserMessage)) {
                            $result = $preferences->extractFromTurn($user, $lastUserMessage);
                            if ($result['saved'] !== [] || $result['conflicts'] !== []) {
                                echo 'data: ' . json_encode([
                                    'type'      => 'memory',
                                    'items'     => $result['saved'],
                                    'conflicts' => $result['conflicts'],
                                ]) . "\n\n";
                                flush();
                            }
                        }
                    } catch (\Throwable $e) {
                        report($e); // extraction lỗi không được làm hỏng chat
                    }
                }

                echo "data: [DONE]\n\n";
                flush();
            },
            200,
            [
                'Content-Type'      => 'text/event-stream; charset=utf-8',
                'Cache-Control'     => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
                'Connection'        => 'keep-alive',
            ]
        );
    }

    /**
     * "Thiết lập kế hoạch ăn hôm nay": biến lời tư vấn trong hội thoại thành
     * kế hoạch daily cho HÔM NAY → hiển thị dưới dạng nhiệm vụ (Home / /plan).
     */
    public function applyPlan(Request $request, MealPlanService $service): JsonResponse
    {
        if ($disabled = $this->chatDisabled()) return $disabled;

        $request->validate([
            'messages'        => 'required|array|min:1|max:30',
            'messages.*.role' => 'required|string|in:user,ai,model',
            'messages.*.text' => 'required|string|max:2000',
        ]);

        $user = $request->user();

        try {
            $context = $service->buildContext($user, 'daily');
        } catch (\RuntimeException $e) {
            // Thiếu hồ sơ (chiều cao/cân nặng/năm sinh)
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $plan = $service->planFromConversation($context, $request->input('messages'));
        } catch (\Throwable $e) {
            Log::error('applyPlan thất bại', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            report($e);

            return response()->json(['message' => 'Không thể thiết lập kế hoạch. Vui lòng thử lại.'], 500);
        }

        UsageTracker::record('chat_apply_plan', $user->id);

        $record = $user->mealPlans()->updateOrCreate(
            ['scope' => 'daily', 'target_date' => today()->toDateString()],
            [
                'plan'             => $plan,
                'context_snapshot' => $context,
                'data_hash'        => $context['data_hash'],
                'reasoning'        => null,
            ]
        );

        return response()->json([
            'message'     => 'Đã thiết lập kế hoạch cho hôm nay',
            'plan'        => $record->plan,
            'target_date' => $record->target_date->toDateString(),
        ]);
    }

    /** Admin tắt ai.chat_enabled trong Settings → chặn chat + apply-plan. */
    private function chatDisabled(): ?JsonResponse
    {
        if (app(\App\Services\SettingsService::class)->get('ai.chat_enabled', true) !== true) {
            return response()->json([
                'detail' => 'Trợ lý AI chat đang tạm tắt. Vui lòng quay lại sau.',
                'code'   => 'feature_disabled',
            ], 503);
        }
        return null;
    }
}
