<?php

/*
 * Visit stats (backlog item 103), see App\Support\PageViews.
 */

return [

    // Where page views wait, one file a minute, until fahares:flush-page-views loads them; the day's salt is here too
    'buffer' => env('PAGE_VIEWS_BUFFER', storage_path('framework/page-views')),

];
