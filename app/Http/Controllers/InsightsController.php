<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Models\CatalogVolume;
use App\Models\Language;
use App\Models\Library;
use App\Models\Person;
use App\Models\Script;
use App\Models\Subject;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\Insights;
use App\Support\InsightsScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class InsightsController extends Controller
{
    public function index(Request $request)
    {
        $scope = InsightsScope::fromRequest($request);
        // A scope not yet in the cache is built now, which reads much of the data: a few per visitor and hour
        if (! $scope->isEmpty() && ! Insights::isCached($scope)) {
            $key = 'insights-scope:' . $request->ip();
            abort_if(RateLimiter::tooManyAttempts($key, (int) config('insights.scope_builds_per_hour')), 429);
            RateLimiter::hit($key, 3600);
        }

        return view('insights.index', ['data' => Insights::get($scope), 'map' => Insights::MAP, 'scope' => $scope]);
    }

    /**
     * The choices of one filter of the form, matching what is typed: [{v: value, label, note}]. The short lists
     * (subjects, languages, scripts, cities) also answer an empty text, with their largest entries.
     */
    public function lookup(Request $request)
    {
        $field = (string) $request->query('field', '');
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $people = function (string $role) use ($q, $like) {
            if (mb_strlen($q) < 2) {
                return collect();
            }
            try {
                $found = Person::search($q)->take(30)->get()->filter(fn (Person $p) => $p->{"is_{$role}"})->take(10);
            } catch (\Throwable) {
                $found = Person::where("is_{$role}", true)->where('name', 'like', $like)->orderByDesc('manuscripts_count')->limit(10)->get();
            }

            return $found->map(fn (Person $p) => ['v' => $p->id, 'label' => $p->name, 'note' => $p->life_span]);
        };

        $items = match ($field) {
            'author' => $people('author'),
            'scribe' => $people('scribe'),
            'work' => mb_strlen($q) < 2 ? collect() : rescue(
                fn () => Work::search($q)->take(10)->get(),
                fn () => Work::where('primary_title', 'like', $like)->orderByDesc('manuscripts_count')->limit(10)->get(),
                false,
            )->map(fn (Work $w) => ['v' => $w->id, 'label' => $w->primary_title, 'note' => $w->author_name]),
            'library' => Library::when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('city', 'like', $like)))
                ->orderByDesc('manuscripts_count')->limit(12)->get()
                ->map(fn (Library $l) => ['v' => $l->id, 'label' => $l->name, 'note' => $l->city]),
            'city' => collect(Cache::remember('insights.lookup.cities', 86400, fn () => DB::table('manuscripts')->whereNotNull('city')->where('city', '!=', '')
                ->selectRaw('city, COUNT(*) AS n')->groupBy('city')->orderByDesc('n')->pluck('n', 'city')->all()))
                ->filter(fn ($n, $city) => $q === '' || str_contains(TextNormalizer::normalize((string) $city), TextNormalizer::normalize($q)))
                ->take(12)->map(fn ($n, $city) => ['v' => (string) $city, 'label' => (string) $city, 'note' => null])->values(),
            'copy_city' => collect(CopyPlaces::cities())
                ->filter(fn ($info, $city) => $q === '' || collect([$city, ...$info['aliases']])->contains(fn ($name) => str_contains(TextNormalizer::normalize($name), TextNormalizer::normalize($q))))
                ->take(12)->map(fn ($info, $city) => ['v' => $city, 'label' => $city, 'note' => $info['aliases'] ? implode('، ', $info['aliases']) : null])->values(),
            'subject' => Subject::with('parent:id,name')->when($q !== '', fn ($w) => $w->where('name', 'like', $like))->orderByDesc('works_count')->limit(12)->get()
                ->map(fn (Subject $s) => ['v' => $s->id, 'label' => $s->name, 'note' => $s->parent?->name]),
            'language' => Language::when($q !== '', fn ($w) => $w->where('name', 'like', $like))->withCount('works')->orderByDesc('works_count')->limit(12)->get()
                ->map(fn (Language $l) => ['v' => $l->id, 'label' => $l->name, 'note' => null]),
            'script' => Script::when($q !== '', fn ($w) => $w->where('name', 'like', $like))->withCount('manuscripts')->orderByDesc('manuscripts_count')->limit(12)->get()
                ->map(fn (Script $s) => ['v' => $s->id, 'label' => $s->name, 'note' => null]),
            'catalog' => CatalogVolume::when($q !== '', fn ($w) => $w->where('title', 'like', $like))->where('manuscripts_count', '>', 0)
                ->orderByDesc('manuscripts_count')->limit(12)->get(['id', 'title', 'volume_number'])
                ->map(fn (CatalogVolume $c) => ['v' => $c->id, 'label' => $c->title, 'note' => $c->volume_number ? 'ج ' . $c->volume_number : null]),
            default => collect(),
        };

        return response()->json($items->values());
    }
}
