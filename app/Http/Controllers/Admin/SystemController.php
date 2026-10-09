<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class SystemController extends Controller
{
    public function index()
    {
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'db_connection' => config('database.default'),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'queue_connection' => config('queue.default'),
            'scout_driver' => config('scout.driver'),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug') ? 'روشن (Debug On)' : 'خاموش (Debug Off)',
        ];

        // Meilisearch Index Stats
        $meiliStats = ['healthy' => false, 'indexes' => []];
        try {
            $host = config('scout.meilisearch.host') ?: 'http://127.0.0.1:7700';
            $key = config('scout.meilisearch.key');
            $client = Http::timeout(2)->withHeaders($key ? ['Authorization' => "Bearer {$key}"] : []);
            
            $health = $client->get(rtrim($host, '/') . '/health');
            if ($health->successful()) {
                $meiliStats['healthy'] = true;
                $indexes = $client->get(rtrim($host, '/') . '/indexes');
                if ($indexes->successful()) {
                    $meiliStats['indexes'] = $indexes->json('results') ?? [];
                }
            }
        } catch (\Throwable $e) {
            $meiliStats['error'] = $e->getMessage();
        }

        return view('admin.system.index', compact('systemInfo', 'meiliStats'));
    }

    public function clearCache(Request $request)
    {
        Artisan::call('cache:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => 'System',
            'auditable_id' => 1,
            'action' => 'update',
            'new_values' => ['operation' => 'clear_cache'],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'حافظه‌های موقت (Cache, Views, Routes) با موفقیت پاکسازی شدند.');
    }

    public function reindex(Request $request)
    {
        $model = $request->input('model', 'Work');

        if ($model === 'Work') {
            Artisan::queue('scout:import', ['model' => 'App\Models\Work']);
            $msg = 'عملیات بازنمایه‌سازی آثار (Works) در صف پردازش پس‌زمینه قرار گرفت.';
        } elseif ($model === 'Manuscript') {
            Artisan::queue('scout:import', ['model' => 'App\Models\Manuscript']);
            $msg = 'عملیات بازنمایه‌سازی نسخه‌ها (Manuscripts) در صف پردازش پس‌زمینه قرار گرفت.';
        } else {
            return back()->with('error', 'مدل انتخابی معتبر نیست.');
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => 'Meilisearch',
            'auditable_id' => 1,
            'action' => 'update',
            'new_values' => ['reindex_model' => $model],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', $msg);
    }
}
