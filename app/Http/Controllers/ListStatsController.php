<?php

namespace App\Http\Controllers;

use App\Support\ListStats;
use Illuminate\Http\Response;

/** The summary of a long list, asked for by its record page when it was not yet in the cache (backlog item 106) */
class ListStatsController extends Controller
{
    public function show(string $param, int $id): Response
    {
        $stats = ListStats::for($param, $id);
        abort_unless($stats, 404);

        return response()->view('list-stats.panel', ['stats' => $stats])
            ->header('X-Robots-Tag', 'noindex');
    }
}
