<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dish;
use App\Services\DishCatalogService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản trị thư viện món ăn chuẩn (nutrition DB) dùng cho grounding nhận diện.
 */
class DishController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q'        => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        $query = Dish::query()->orderBy('name');

        if ($q = $request->query('q')) {
            $norm = DishCatalogService::normalize($q);
            $query->where(function ($w) use ($q, $norm) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('name_normalized', 'like', "%{$norm}%");
            });
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Dish $d) => $this->row($d)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['name_normalized'] = DishCatalogService::normalize($data['name']);

        $dish = Dish::create($data);
        AuditLogger::log('dish.create', 'dish', (string) $dish->id, ['name' => $dish->name]);

        return response()->json($this->row($dish), 201);
    }

    public function update(Request $request, Dish $dish): JsonResponse
    {
        $data = $this->validated($request);
        $data['name_normalized'] = DishCatalogService::normalize($data['name']);

        $dish->update($data);
        AuditLogger::log('dish.update', 'dish', (string) $dish->id, ['name' => $dish->name]);

        return response()->json($this->row($dish->fresh()));
    }

    public function destroy(Dish $dish): JsonResponse
    {
        AuditLogger::log('dish.delete', 'dish', (string) $dish->id, ['name' => $dish->name]);
        $dish->delete();

        return response()->json(['message' => 'Đã xoá món']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name'       => 'required|string|max:150',
            'aliases'    => 'nullable|array',
            'aliases.*'  => 'string|max:100',
            'unit_type'  => 'required|in:countable,portion',
            'unit_label' => 'required|string|max:30',
            'serving'    => 'required|string|max:100',
            'calories'   => 'required|integer|min:0|max:10000',
            'protein'    => 'required|integer|min:0|max:1000',
            'carbs'      => 'required|integer|min:0|max:1000',
            'fat'        => 'required|integer|min:0|max:1000',
            'sodium'     => 'required|integer|min:0|max:100000',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Dish $d): array
    {
        return [
            'id'         => $d->id,
            'name'       => $d->name,
            'aliases'    => $d->aliases ?? [],
            'unit_type'  => $d->unit_type,
            'unit_label' => $d->unit_label,
            'serving'    => $d->serving,
            'calories'   => $d->calories,
            'protein'    => $d->protein,
            'carbs'      => $d->carbs,
            'fat'        => $d->fat,
            'sodium'     => $d->sodium,
        ];
    }
}
