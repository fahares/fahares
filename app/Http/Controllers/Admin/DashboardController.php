<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\ScholarlyAnnotation;
use App\Models\Subject;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    public function index()
    {
        $counts = [
            'works' => Work::count(),
            'manuscripts' => Manuscript::count(),
            'people' => Person::count(),
            'libraries' => Library::count(),
            'catalogers' => Cataloger::count(),
            'subjects' => Subject::count(),
            'users' => User::count(),
            'annotations' => ScholarlyAnnotation::count(),
            'pending_suggestions' => FieldSuggestion::where('status', 'pending')->count(),
        ];

        $pendingSuggestions = FieldSuggestion::with(['user', 'suggestable'])
            ->where('status', 'pending')
            ->latest('id')
            ->take(6)
            ->get();

        $recentLogs = AuditLog::with('user')
            ->latest('id')
            ->take(8)
            ->get();

        // Check Meilisearch Health
        $meiliStatus = ['healthy' => false, 'version' => null, 'error' => null];
        try {
            $host = config('scout.meilisearch.host') ?: 'http://127.0.0.1:7700';
            $key = config('scout.meilisearch.key');
            $response = Http::timeout(2)
                ->withHeaders($key ? ['Authorization' => "Bearer {$key}"] : [])
                ->get(rtrim($host, '/') . '/health');

            if ($response->successful() && $response->json('status') === 'available') {
                $meiliStatus['healthy'] = true;
                $versionRes = Http::timeout(2)
                    ->withHeaders($key ? ['Authorization' => "Bearer {$key}"] : [])
                    ->get(rtrim($host, '/') . '/version');
                if ($versionRes->successful()) {
                    $meiliStatus['version'] = $versionRes->json('pkgVersion');
                }
            }
        } catch (\Throwable $e) {
            $meiliStatus['error'] = $e->getMessage();
        }

        return view('admin.dashboard', compact('counts', 'pendingSuggestions', 'recentLogs', 'meiliStatus'));
    }
}
