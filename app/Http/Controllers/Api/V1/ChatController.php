<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use App\Services\PreferenceService;
use App\Support\UsageTracker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Tư vấn dinh dưỡng & kế hoạch ăn uống/tập luyện — SSE streaming.
     * Mỗi request rebuild ngữ cảnh từ DB → luôn dựa trên dữ liệu mới nhất.
     */
    public function send(Request $request, ChatService $service, PreferenceService $preferences): StreamedResponse
    {
        $request->validate([
            'messages'        => 'required|array|min:1|max:30',
            'messages.*.role' => 'required|string|in:user,ai,model',
            'messages.*.text' => 'required|string|max:2000',
        ]);

        // Không bắt buộc auth: resolve user qua sanctum guard nếu có Bearer token (khách → null)
        $user     = $request->user('sanctum');
        $messages = $request->input('messages');

        UsageTracker::record('chat', $user?->id);

        return response()->stream(
            function () use ($service, $preferences, $user, $messages) {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                $inScope = true;

                try {
                    // Cổng phân loại: chặn sớm yêu cầu ngoài phạm vi dinh dưỡng/tập luyện
                    if (!$service->isInScope($messages)) {
                        $inScope = false;
                        echo 'data: ' . json_encode([
                            'type'  => 'text',
                            'delta' => 'Mình là trợ lý dinh dưỡng của CaloEye nên chỉ hỗ trợ về ăn uống, dinh dưỡng và tập luyện thôi nhé 🥗 Bạn muốn mình gợi ý kế hoạch ăn uống cho ngày mai không?',
                        ]) . "\n\n";
                        flush();
                    } else {
                        foreach ($service->streamReply($user, $messages) as $delta) {
                            echo 'data: ' . json_encode(['type' => 'text', 'delta' => $delta]) . "\n\n";
                            flush();
                        }
                    }
                } catch (\Throwable $e) {
                    echo 'data: ' . json_encode([
                        'type'    => 'error',
                        'message' => 'Không thể kết nối trợ lý AI. Vui lòng thử lại.',
                    ]) . "\n\n";
                    flush();
                }

                // Ghi nhớ sở thích từ lượt user mới nhất (sau khi đã trả lời xong → không tăng độ trễ).
                // Chỉ chạy khi đăng nhập, câu trong phạm vi, và qua cổng heuristic rẻ.
                if ($user && $inScope) {
                    try {
                        $lastUser = collect($messages)->reverse()
                            ->first(fn ($m) => ($m['role'] ?? 'user') === 'user')['text'] ?? '';

                        if ($preferences->shouldExtract($lastUser)) {
                            $result = $preferences->extractFromTurn($user, $lastUser);
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
}
