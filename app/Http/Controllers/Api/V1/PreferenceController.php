<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Services\PreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function index(Request $request, PreferenceService $service): JsonResponse
    {
        $prefs = $service->listFor($request->user());

        return response()->json([
            'preferences' => $prefs->map(fn (UserPreference $p) => $this->present($p)),
            'limit'       => PreferenceService::MAX_PREFERENCES,
        ]);
    }

    // DEFENSE: endpoint thêm sở thích/dị ứng — 5 kind (allergy/dislike/like/diet/habit), giới hạn MAX_PREFERENCES
    public function store(Request $request, PreferenceService $service): JsonResponse
    {
        $data = $request->validate([
            // DEFENSE: loại sở thích — allergy/dislike/like/diet/habit (5 kind)
            'kind'  => 'required|string|in:allergy,dislike,like,diet,habit',
            // DEFENSE: độ dài nhãn preference — max 100 ký tự
            'label' => 'required|string|max:100',
        ]);

        try {
            $pref = $service->add($request->user(), $data['kind'], $data['label'], 'manual');
        } catch (\RuntimeException $e) {
            // DEFENSE: text lỗi vượt giới hạn preference — hiện khi >= MAX_PREFERENCES
            return response()->json([
                'message' => 'Đã đạt giới hạn ' . PreferenceService::MAX_PREFERENCES . ' mục ghi nhớ.',
            ], 422);
        }

        if (!$pref) {
            return response()->json(['message' => 'Nội dung không hợp lệ.'], 422);
        }

        return response()->json(['preference' => $this->present($pref)], 201);
    }

    public function destroy(Request $request, int $id, PreferenceService $service): JsonResponse
    {
        $deleted = $service->remove($request->user(), $id);
        abort_unless($deleted, 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(UserPreference $p): array
    {
        return [
            'id'                => $p->id,
            'kind'              => $p->kind,
            'label'             => $p->label,
            'source'            => $p->source,
            'last_confirmed_at' => $p->last_confirmed_at?->toIso8601String(),
            'created_at'        => $p->created_at?->toIso8601String(),
        ];
    }
}
