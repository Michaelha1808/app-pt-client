<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestLog extends Command
{
    protected $signature = 'app:test-log {message? : Nội dung log tuỳ chọn}';

    protected $description = 'Ghi thử log ở mọi mức để kiểm tra log file trên server';

    public function handle(): int
    {
        $message = $this->argument('message') ?? 'Test log từ artisan';
        $context = ['at' => now()->toIso8601String(), 'channel' => config('logging.default')];

        Log::debug("[test-log] DEBUG: {$message}", $context);
        Log::info("[test-log] INFO: {$message}", $context);
        Log::warning("[test-log] WARNING: {$message}", $context);
        Log::error("[test-log] ERROR: {$message}", $context);

        $this->info('✅ Đã ghi 4 dòng log (debug/info/warning/error).');
        $this->line('   Channel mặc định: ' . config('logging.default'));

        // Đường dẫn file của channel "single" (mặc định storage/logs/laravel.log)
        $path = config('logging.channels.single.path', storage_path('logs/laravel.log'));
        $this->line('   File log (channel single): ' . $path);

        if (is_file($path)) {
            $this->line('   File tồn tại, kích thước: ' . number_format(filesize($path)) . ' bytes');
        } else {
            $this->warn('   ⚠️  File chưa tồn tại tại đường dẫn trên — có thể channel khác hoặc thiếu quyền ghi.');
        }

        return self::SUCCESS;
    }
}
