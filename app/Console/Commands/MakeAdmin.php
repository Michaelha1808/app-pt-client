<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email : Email của user cần cấp quyền admin}';

    protected $description = 'Cấp quyền admin cho user theo email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Không tìm thấy user với email: {$email}");
            return self::FAILURE;
        }

        $user->update(['role' => 'admin', 'status' => 'active']);
        $this->info("✅ Đã cấp quyền admin cho {$user->name} <{$user->email}>");

        return self::SUCCESS;
    }
}
