<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * meal_plans.scope được tạo với enum('daily','monthly') — thiếu 'weekly'.
     *
     * Trên PostgreSQL (driver thật của app), Schema::enum() không sinh native ENUM type mà
     * sinh varchar(255) + CHECK constraint KHÔNG TÊN gắn trực tiếp vào cột (xem
     * Illuminate\Database\Schema\Grammars\PostgresGrammar::typeEnum()). Laravel's ->change()
     * cho enum trên Postgres sinh `alter column ... type varchar(255) check (...)` — cú pháp
     * này KHÔNG hợp lệ với Postgres (ALTER COLUMN ... TYPE không chấp nhận mệnh đề CHECK đi
     * kèm) nên phải tự viết raw SQL: tra pg_constraint để lấy đúng tên constraint thật (Postgres
     * tự đặt tên theo quy ước "{table}_{column}_check" khi không đặt tên, nhưng không hardcode
     * để tránh đoán sai), drop rồi add lại với danh sách giá trị mới.
     *
     * Trên SQLite (driver dùng cho test, DB :memory:), Schema::table(...)->change() hoạt động
     * đúng vì Laravel tự rebuild toàn bộ bảng (SQLiteGrammar::compileTableAlteration) — dùng
     * cách này cho các driver còn lại thay vì lặp lại logic raw SQL.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::transaction(function () {
                $row = DB::selectOne(<<<SQL
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(con.conkey)
                    WHERE rel.relname = 'meal_plans'
                      AND con.contype = 'c'
                      AND att.attname = 'scope'
                    SQL
                );

                if ($row && $row->conname) {
                    DB::statement('ALTER TABLE meal_plans DROP CONSTRAINT "' . $row->conname . '"');
                }

                DB::statement(
                    "ALTER TABLE meal_plans ADD CONSTRAINT meal_plans_scope_check " .
                    "CHECK (scope IN ('daily', 'weekly', 'monthly'))"
                );
            });

            return;
        }

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->enum('scope', ['daily', 'weekly', 'monthly'])->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::transaction(function () {
                DB::statement('ALTER TABLE meal_plans DROP CONSTRAINT IF EXISTS meal_plans_scope_check');

                // Lưu ý: nếu đã có dòng scope='weekly' trong bảng, ADD CONSTRAINT dưới đây
                // sẽ FAIL — Postgres validate toàn bộ dữ liệu hiện có khi thêm CHECK mới.
                DB::statement(
                    "ALTER TABLE meal_plans ADD CONSTRAINT meal_plans_scope_check " .
                    "CHECK (scope IN ('daily', 'monthly'))"
                );
            });

            return;
        }

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->enum('scope', ['daily', 'monthly'])->change();
        });
    }
};
