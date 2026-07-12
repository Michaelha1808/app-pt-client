<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quan trắc & công cụ hệ thống cho trang Admin Settings:
 * - info: thông tin môi trường + health check các dịch vụ phụ thuộc
 * - clearCache: xoá cache ứng dụng / config / route / view
 * - logs: đọc N entry mới nhất từ laravel.log (lọc theo level)
 * - failedJobs / retryFailedJobs / deleteFailedJob: quản lý bảng failed_jobs
 */
class SystemController extends Controller
{
    public function info(): JsonResponse
    {
        return response()->json([
            'app' => [
                'name'     => config('app.name'),
                'env'      => config('app.env'),
                'debug'    => (bool) config('app.debug'),
                'url'      => config('app.url'),
                'timezone' => config('app.timezone'),
                'locale'   => config('app.locale'),
                'laravel'  => app()->version(),
                'php'      => PHP_VERSION,
            ],
            'server' => [
                'os'                  => php_uname('s') . ' ' . php_uname('r'),
                'memory_limit'        => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'server_time'         => now()->toDateTimeString(),
            ],
            'database' => $this->databaseInfo(),
            'cache'    => ['driver' => config('cache.default')],
            'queue'    => $this->queueInfo(),
            'storage'  => $this->storageInfo(),
            'health'   => $this->healthChecks(),
        ]);
    }

    public function clearCache(Request $request): JsonResponse
    {
        $target = $request->validate([
            'target' => 'required|in:cache,config,route,view',
        ])['target'];

        try {
            match ($target) {
                'cache'  => Cache::flush(),
                'config' => Artisan::call('config:clear'),
                'route'  => Artisan::call('route:clear'),
                'view'   => Artisan::call('view:clear'),
            };
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Xoá cache thất bại: ' . $e->getMessage()], 500);
        }

        AuditLogger::log('system.cache_clear', 'system', null, ['target' => $target]);

        $labels = ['cache' => 'cache ứng dụng', 'config' => 'config cache', 'route' => 'route cache', 'view' => 'view cache'];

        return response()->json(['ok' => true, 'message' => 'Đã xoá ' . $labels[$target]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines' => 'sometimes|integer|between:10,500',
            'level' => 'sometimes|nullable|in:emergency,alert,critical,error,warning,notice,info,debug',
        ]);
        $limit = (int) ($validated['lines'] ?? 100);
        $level = $validated['level'] ?? null;

        $file = $this->latestLogFile();
        if (! $file) {
            return response()->json(['file' => null, 'entries' => []]);
        }

        $entries = $this->parseLogEntries($file, $limit, $level);

        return response()->json([
            'file'    => basename($file),
            'size'    => filesize($file),
            'entries' => $entries,
        ]);
    }

    // ── Failed jobs ──────────────────────────────────────────────────────

    public function failedJobs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|between:1,100',
        ]);

        $paginator = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($job) => [
                'id'                => $job->id,
                'uuid'              => $job->uuid,
                'connection'        => $job->connection,
                'queue'             => $job->queue,
                'job_name'          => $this->failedJobName($job),
                'exception_excerpt' => $this->exceptionExcerpt($job->exception),
                'failed_at'         => $job->failed_at,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /** Retry 1 job (body có uuid) hoặc toàn bộ (không có uuid). */
    public function retryFailedJobs(Request $request): JsonResponse
    {
        $uuid = $request->validate(['uuid' => 'sometimes|nullable|string|max:64'])['uuid'] ?? null;

        if ($uuid && ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            return response()->json(['ok' => false, 'message' => 'Không tìm thấy job lỗi này', 'retried' => 0], 404);
        }

        $before = DB::table('failed_jobs')->count();
        if ($before === 0) {
            return response()->json(['ok' => true, 'message' => 'Không có job lỗi nào để thử lại', 'retried' => 0]);
        }

        try {
            Artisan::call('queue:retry', ['id' => $uuid ? [$uuid] : ['all']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Thử lại thất bại: ' . $e->getMessage(), 'retried' => 0], 500);
        }

        // queue:retry xoá row khỏi failed_jobs sau khi đẩy lại queue → đếm chênh lệch là số retry thật
        $retried = $before - DB::table('failed_jobs')->count();

        AuditLogger::log('system.failed_jobs_retry', 'system', $uuid, ['uuid' => $uuid ?? 'all', 'retried' => $retried]);

        return response()->json([
            'ok'      => true,
            'message' => $retried > 0 ? "Đã đưa {$retried} job vào hàng đợi để chạy lại" : 'Không có job nào được retry',
            'retried' => $retried,
        ]);
    }

    public function deleteFailedJob(string $uuid): JsonResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        if (! $deleted) {
            return response()->json(['ok' => false, 'message' => 'Không tìm thấy job lỗi này'], 404);
        }

        AuditLogger::log('system.failed_jobs_delete', 'system', $uuid, ['uuid' => $uuid]);

        return response()->json(['ok' => true, 'message' => 'Đã xoá job lỗi']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Tên job đọc từ payload JSON (displayName), fallback uuid. */
    private function failedJobName(object $job): string
    {
        $payload = json_decode((string) $job->payload, true);

        return is_array($payload) && ! empty($payload['displayName'])
            ? (string) $payload['displayName']
            : (string) $job->uuid;
    }

    /** Dòng đầu của exception, cắt 500 ký tự. */
    private function exceptionExcerpt(?string $exception): string
    {
        $firstLine = strtok((string) $exception, "\n");

        return mb_substr(trim($firstLine === false ? '' : $firstLine), 0, 500);
    }

    private function databaseInfo(): array
    {
        $driver = config('database.default');
        $info   = ['driver' => $driver, 'version' => null, 'size' => null];

        try {
            $info['version'] = match ($driver) {
                'pgsql'  => DB::selectOne('SHOW server_version')->server_version ?? null,
                'mysql', 'mariadb' => DB::selectOne('SELECT VERSION() AS v')->v ?? null,
                'sqlite' => DB::selectOne('SELECT sqlite_version() AS v')->v ?? null,
                default  => null,
            };
            $info['size'] = match ($driver) {
                'pgsql' => (int) (DB::selectOne('SELECT pg_database_size(current_database()) AS s')->s ?? 0),
                'mysql', 'mariadb' => (int) (DB::selectOne(
                    'SELECT SUM(data_length + index_length) AS s FROM information_schema.tables WHERE table_schema = DATABASE()'
                )->s ?? 0),
                'sqlite' => ($p = config('database.connections.sqlite.database')) && is_file($p) ? filesize($p) : null,
                default  => null,
            };
        } catch (\Throwable) {
            // health check bên dưới sẽ báo trạng thái DB
        }

        return $info;
    }

    private function queueInfo(): array
    {
        $info = ['driver' => config('queue.default'), 'pending' => null, 'failed' => null];

        try {
            if (config('queue.default') === 'database') {
                $info['pending'] = DB::table('jobs')->count();
            }
            $info['failed'] = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
        }

        return $info;
    }

    private function storageInfo(): array
    {
        $logsPath = storage_path('logs');
        $logsSize = 0;
        foreach (glob($logsPath . '/*.log') ?: [] as $f) {
            $logsSize += filesize($f) ?: 0;
        }

        return [
            'logs_size'  => $logsSize,
            'disk_free'  => @disk_free_space(base_path()) ?: null,
            'disk_total' => @disk_total_space(base_path()) ?: null,
        ];
    }

    /** @return array<int, array{name:string,label:string,ok:bool,detail:?string}> */
    private function healthChecks(): array
    {
        $checks = [];

        // Database
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = (int) round((microtime(true) - $start) * 1000);
            $checks[] = ['name' => 'database', 'label' => 'Cơ sở dữ liệu', 'ok' => true, 'detail' => "Phản hồi {$ms}ms"];
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'database', 'label' => 'Cơ sở dữ liệu', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Cache read/write
        try {
            $key = 'health:probe';
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            $checks[] = ['name' => 'cache', 'label' => 'Cache', 'ok' => $ok, 'detail' => $ok ? 'Đọc/ghi OK (' . config('cache.default') . ')' : 'Ghi được nhưng đọc sai'];
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'cache', 'label' => 'Cache', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Storage writable
        $writable = is_writable(storage_path()) && is_writable(storage_path('logs'));
        $checks[] = ['name' => 'storage', 'label' => 'Thư mục storage', 'ok' => $writable, 'detail' => $writable ? 'Có quyền ghi' : 'Không có quyền ghi'];

        // AI key
        $aiKey = app(\App\Services\SettingsService::class)->get('ai.api_key', config('services.gemini.key'));
        $checks[] = ['name' => 'ai', 'label' => 'AI (Gemini)', 'ok' => (bool) $aiKey, 'detail' => $aiKey ? 'Đã cấu hình API key' : 'Chưa có API key'];

        // Firebase
        $fbProject = config('firebase.projects.app.project_id') ?? env('FIREBASE_PROJECT_ID');
        $checks[] = ['name' => 'firebase', 'label' => 'Firebase (Push)', 'ok' => (bool) $fbProject, 'detail' => $fbProject ? "Project: {$fbProject}" : 'Chưa cấu hình'];

        // Mail
        $from = config('mail.from.address');
        $checks[] = ['name' => 'mail', 'label' => 'Email', 'ok' => (bool) $from, 'detail' => $from ? "Mailer: " . config('mail.default') . " — from {$from}" : 'Chưa cấu hình from address'];

        return $checks;
    }

    private function latestLogFile(): ?string
    {
        $files = glob(storage_path('logs') . '/*.log') ?: [];
        if (! $files) return null;
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * Đọc tối đa ~512KB cuối file log, tách entry theo header "[YYYY-MM-DD ...]",
     * trả về mới nhất trước. Mỗi entry cắt gọn 2000 ký tự để không phình response.
     *
     * @return array<int, array{timestamp:string,level:string,message:string}>
     */
    private function parseLogEntries(string $file, int $limit, ?string $level): array
    {
        $size   = filesize($file) ?: 0;
        $offset = max(0, $size - 512 * 1024);

        $h = fopen($file, 'rb');
        if (! $h) return [];
        fseek($h, $offset);
        $content = stream_get_contents($h) ?: '';
        fclose($h);

        // Tách theo dòng bắt đầu bằng "[timestamp]" — phần trước match đầu (rác do cắt giữa entry) bỏ đi.
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[T ][\d:.,+\-]+)\]\s+(\w+)\.(\w+):\s?/m';
        preg_match_all($pattern, $content, $headers, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        if (! $headers) return [];

        $entries = [];
        foreach ($headers as $i => $m) {
            $bodyStart = $m[0][1];
            $bodyEnd   = $headers[$i + 1][0][1] ?? strlen($content);
            $raw       = trim(substr($content, $bodyStart, $bodyEnd - $bodyStart));
            $lvl       = strtolower($m[3][0]);
            if ($level && $lvl !== $level) continue;

            // Bỏ phần header ra khỏi message
            $message = trim(preg_replace($pattern, '', $raw, 1) ?? $raw);
            $entries[] = [
                'timestamp' => $m[1][0],
                'level'     => $lvl,
                'message'   => mb_substr($message, 0, 2000),
            ];
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }
}
