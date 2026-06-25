<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Tư vấn dinh dưỡng & kế hoạch ăn uống/tập luyện — SSE streaming.
     * Mỗi request rebuild ngữ cảnh từ DB → luôn dựa trên dữ liệu mới nhất.
     */
    public function send(Request $request, ChatService $service): StreamedResponse
    {
        $request->validate([
            'messages'        => 'required|array|min:1|max:30',
            'messages.*.role' => 'required|string|in:user,ai,model',
            'messages.*.text' => 'required|string|max:2000',
        ]);

        // Không bắt buộc auth: resolve user qua sanctum guard nếu có Bearer token (khách → null)
        $user     = $request->user('sanctum');
        $messages = $request->input('messages');

        return response()->stream(
            function () use ($service, $user, $messages) {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                try {
                    // Cổng phân loại: chặn sớm yêu cầu ngoài phạm vi dinh dưỡng/tập luyện
                    if (!$service->isInScope($messages)) {
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
