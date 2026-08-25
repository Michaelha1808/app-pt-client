<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1 nguyên liệu FCT trong công thức nấu 1 khẩu phần món ăn.
 *
 * @property int    $dish_id
 * @property int    $vdd_food_id
 * @property float  $grams_per_serving
 * @property string $note
 * @property int    $order
 */
class DishRecipe extends Model
{
    protected $fillable = ['dish_id', 'vdd_food_id', 'grams_per_serving', 'note', 'order'];

    protected $casts = ['grams_per_serving' => 'float'];

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(VddFood::class, 'vdd_food_id');
    }
}
