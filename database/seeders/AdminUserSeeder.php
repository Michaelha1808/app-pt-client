<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Tài khoản admin mặc định. Idempotent — chạy lại không tạo trùng.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name'     => 'Admin',
                'password' => 'admin123', // cast 'hashed' của model tự băm
                'role'     => 'admin',
                'status'   => 'active',
            ],
        );
    }
}
