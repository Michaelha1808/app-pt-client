<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Đổi `calories` từ integer sang decimal(8,2) để lưu đúng số thập phân
 * từ nguồn VDD (vd 420.5 kcal) thay vì làm tròn.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dishes ALTER COLUMN calories TYPE numeric(8,2) USING calories::numeric(8,2)');
        DB::statement('ALTER TABLE dishes ALTER COLUMN calories SET DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dishes ALTER COLUMN calories TYPE integer USING round(calories)::integer');
        DB::statement('ALTER TABLE dishes ALTER COLUMN calories SET DEFAULT 0');
    }
};
