<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FoodDetectionSample;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Duyệt dataset nhận diện món ăn (AI đoán vs user sửa) để theo dõi model sai ở đâu
 * và chọn món bổ sung vào thư viện. Read-only + xoá mẫu rác.
 */
class DatasetController extends Controller
{
    private const DISK = 'local';

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'           => FoodDetectionSample::count(),
            'with_correction' => FoodDetectionSample::where('has_correction', true)->count(),
            'saved'           => FoodDetectionSample::where('saved', true)->count(),
            'with_image'      => FoodDetectionSample::whereNotNull('image_path')->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'only_corrections' => 'nullable|boolean',
            'input_type'       => 'nullable|in:image,text',
            'per_page'         => 'nullable|integer|between:1,100',
        ]);

        $query = FoodDetectionSample::query()->latest('id');

        if ($request->boolean('only_corrections')) {
            $query->where('has_correction', true);
        }
        if ($type = $request->query('input_type')) {
            $query->where('input_type', $type);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (FoodDetectionSample $s) => [
                'id'             => $s->id,
                'input_type'     => $s->input_type,
                'has_image'      => (bool) $s->image_path,
                'text_input'     => $s->text_input,
                'model'          => $s->model,
                'ai_count'       => count($s->ai_dishes ?? []),
                'has_correction' => $s->has_correction,
                'saved'          => $s->saved,
                'created_at'     => optional($s->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(FoodDetectionSample $sample): JsonResponse
    {
        return response()->json([
            'id'               => $sample->id,
            'input_type'       => $sample->input_type,
            'text_input'       => $sample->text_input,
            'model'            => $sample->model,
            'ai_dishes'        => $sample->ai_dishes,
            'corrected_dishes' => $sample->corrected_dishes,
            'has_correction'   => $sample->has_correction,
            'saved'            => $sample->saved,
            'created_at'       => optional($sample->created_at)->toIso8601String(),
            // Ảnh nhúng base64 (đã downscale) — tránh phải gửi Bearer token trên thẻ <img>.
            'image'            => $this->imageDataUri($sample),
        ]);
    }

    public function destroy(FoodDetectionSample $sample): JsonResponse
    {
        if ($sample->image_path && Storage::disk(self::DISK)->exists($sample->image_path)) {
            Storage::disk(self::DISK)->delete($sample->image_path);
        }
        AuditLogger::log('dataset.delete', 'sample', (string) $sample->id);
        $sample->delete();

        return response()->json(['message' => 'Đã xoá mẫu']);
    }

    private function imageDataUri(FoodDetectionSample $sample): ?string
    {
        if (!$sample->image_path || !Storage::disk(self::DISK)->exists($sample->image_path)) {
            return null;
        }
        $bytes = Storage::disk(self::DISK)->get($sample->image_path);

        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }
}
