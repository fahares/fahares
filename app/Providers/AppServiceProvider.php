<?php

namespace App\Providers;

use App\Helpers\SearchHelper;
use Illuminate\Support\Facades\Blade;
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
    }
}

if (!function_exists('highlight_search')) {
    function highlight_search(?string $text, ?string $query): string
    {
        return \App\Helpers\SearchHelper::highlight($text, $query);
    }
}

