<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createMeal(User $user, array $overrides = []): \App\Models\MealLog
    {
        return $user->mealLogs()->create(array_merge([
            'food_name' => 'Phở bò',
            'serving'   => '1 tô',
            'calories'  => 450,
            'protein'   => 25,
            'carbs'     => 55,
            'fat'       => 12,
            'sodium'    => 1200,
            'logged_at' => now(),
        ], $overrides));
    }

    public function test_relog_creates_new_entry_with_same_nutrition(): void
    {
        $user = User::factory()->create();
        $log  = $this->createMeal($user);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/food/relog/{$log->id}");

        $response->assertStatus(201);
        $this->assertDatabaseCount('meal_logs', 2);
        $this->assertDatabaseHas('meal_logs', [
            'user_id'   => $user->id,
            'food_name' => 'Phở bò',
            'calories'  => 450,
        ]);
    }

    public function test_relog_allows_serving_override(): void
    {
        $user = User::factory()->create();
        $log  = $this->createMeal($user);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/food/relog/{$log->id}", [
            'serving' => '1 tô lớn',
        ]);

        $response->assertStatus(201);
        $newId = $response->json('id');
        $this->assertDatabaseHas('meal_logs', ['id' => $newId, 'serving' => '1 tô lớn']);
    }

    public function test_cannot_relog_another_users_meal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $log   = $this->createMeal($owner);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/food/relog/{$log->id}")
            ->assertStatus(404);
    }

    public function test_frequent_only_returns_items_logged_at_least_twice(): void
    {
        $user = User::factory()->create();
        $this->createMeal($user, ['food_name' => 'Phở bò', 'logged_at' => now()->subDays(1)]);
        $this->createMeal($user, ['food_name' => 'Phở bò', 'logged_at' => now()->subDays(3)]);
        $this->createMeal($user, ['food_name' => 'Bún chả', 'logged_at' => now()->subDays(2)]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/food/frequent');

        $response->assertOk();
        $items = collect($response->json('items'));
        $this->assertCount(1, $items);
        $this->assertEquals('Phở bò', $items->first()['food_name']);
        $this->assertEquals(2, $items->first()['count']);
    }

    public function test_frequent_groups_case_and_diacritic_insensitively(): void
    {
        $user = User::factory()->create();
        $this->createMeal($user, ['food_name' => 'Phở Bò', 'logged_at' => now()->subDays(1)]);
        $this->createMeal($user, ['food_name' => 'pho bo', 'logged_at' => now()->subDays(2)]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/food/frequent');

        $items = collect($response->json('items'));
        $this->assertCount(1, $items);
        $this->assertEquals(2, $items->first()['count']);
    }

    public function test_frequent_filters_by_slot(): void
    {
        $user = User::factory()->create();
        $morning = today()->setTime(7, 0);
        $this->createMeal($user, ['food_name' => 'Bánh mì', 'logged_at' => $morning]);
        $this->createMeal($user, ['food_name' => 'Bánh mì', 'logged_at' => $morning->copy()->subDay()]);
        $evening = today()->setTime(19, 0);
        $this->createMeal($user, ['food_name' => 'Cơm gà', 'logged_at' => $evening]);
        $this->createMeal($user, ['food_name' => 'Cơm gà', 'logged_at' => $evening->copy()->subDay()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/food/frequent?slot=morning');

        $items = collect($response->json('items'));
        $this->assertCount(1, $items);
        $this->assertEquals('Bánh mì', $items->first()['food_name']);
    }

    public function test_add_favorite_from_meal_log(): void
    {
        $user = User::factory()->create();
        $log  = $this->createMeal($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/favorites', [
            'meal_log_id' => $log->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('item.food_name', 'Phở bò');
        $this->assertDatabaseHas('favorite_meals', ['user_id' => $user->id, 'food_name' => 'Phở bò']);
    }

    public function test_add_favorite_manual_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/favorites', [
            'food_name' => 'Cà phê sữa', 'serving' => '1 ly', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ])->assertStatus(201);
    }

    public function test_add_duplicate_favorite_returns_409(): void
    {
        $user = User::factory()->create();
        $user->favoriteMeals()->create([
            'food_name' => 'Cà phê sữa', 'serving' => '1 ly', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/favorites', [
            'food_name' => 'Cà phê sữa', 'serving' => '1 ly', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ])->assertStatus(409);
    }

    public function test_favorites_limit_50(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 50; $i++) {
            $user->favoriteMeals()->create([
                'food_name' => "Món {$i}", 'calories' => 100,
                'protein' => 1, 'carbs' => 1, 'fat' => 1, 'sodium' => 1,
            ]);
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/food/favorites', [
            'food_name' => 'Món thứ 51', 'calories' => 100,
            'protein' => 1, 'carbs' => 1, 'fat' => 1, 'sodium' => 1,
        ])->assertStatus(422);
    }

    public function test_log_favorite_creates_meal_log(): void
    {
        $user = User::factory()->create();
        $fav  = $user->favoriteMeals()->create([
            'food_name' => 'Cà phê sữa', 'serving' => '1 ly', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/food/favorites/{$fav->id}/log")
            ->assertStatus(201);

        $this->assertDatabaseHas('meal_logs', ['user_id' => $user->id, 'food_name' => 'Cà phê sữa']);
    }

    public function test_cannot_delete_another_users_favorite(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $fav   = $owner->favoriteMeals()->create([
            'food_name' => 'Cà phê sữa', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/food/favorites/{$fav->id}")
            ->assertStatus(404);
    }

    public function test_delete_favorite(): void
    {
        $user = User::factory()->create();
        $fav  = $user->favoriteMeals()->create([
            'food_name' => 'Cà phê sữa', 'calories' => 120,
            'protein' => 2, 'carbs' => 18, 'fat' => 4, 'sodium' => 30,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/food/favorites/{$fav->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('favorite_meals', ['id' => $fav->id]);
    }
}
