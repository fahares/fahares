<?php

namespace App\Providers;

use App\Helpers\SearchHelper;
use App\Suggestions\SuggestionReadings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('highlight', function ($expression) {
            return "<?php echo \App\Helpers\SearchHelper::highlight({$expression}); ?>";
        });

        // Record pages share the readings of their fields so the per-field badges and the readings list can show them
        $records = ['manuscripts.show' => 'manuscript', 'works.show' => 'work', 'people.show' => 'person', 'libraries.show' => 'library'];
        View::composer(array_keys($records), function ($view) use ($records) {
            $record = $view->getData()[$records[$view->name()]] ?? null;
            View::share('suggestionReadings', $record ? SuggestionReadings::for($record) : collect());
        });
    }
}

if (!function_exists('highlight_search')) {
    function highlight_search(?string $text, ?string $query): string
    {
        return \App\Helpers\SearchHelper::highlight($text, $query);
    }
}

