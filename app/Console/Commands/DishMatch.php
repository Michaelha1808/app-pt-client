<?php

namespace App\Console\Commands;

use App\Services\DishCatalogService;
use Illuminate\Console\Command;

class DishMatch extends Command
{
    protected $signature = 'dish:match {name : Tên món cần khớp với thư viện}';

    protected $description = 'Thử khớp 1 tên món với thư viện nutrition (debug grounding)';

    public function handle(DishCatalogService $catalog): int
    {
        $name = $this->argument('name');
        $this->line('normalized: ' . DishCatalogService::normalize($name));

        $match = $catalog->match($name);
        if (!$match) {
            $this->warn("Không khớp món nào trong thư viện cho: \"{$name}\"");
            return self::SUCCESS;
        }

        $this->info("Khớp: {$match->name} (#{$match->id})");
        $this->table(
            ['serving', 'calo', 'P', 'C', 'F', 'sodium'],
            [[$match->serving, $match->calories, $match->protein, $match->carbs, $match->fat, $match->sodium]],
        );

        return self::SUCCESS;
    }
}
