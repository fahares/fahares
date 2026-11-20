<?php

namespace App\Support;

use App\Models\Language;
use App\Models\Library;
use App\Models\Person;
use App\Models\Script;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;

/**
 * «چند دامنه برای دیدن» (backlog item 155, step 14.268): the ready-made scopes of config insights.presets, as links
 * under the filter form. A preset names its records by what stays the same in every copy of the data (a slug or a
 * name, never an id), and is shown only when its records exist and its scope holds enough copies, so a link never
 * leads to a page of thin figures.
 */
class InsightsPresets
{
    private const CACHE_KEY = 'insights.presets.v1';

    /** @return array<int, array{label: string, note: string, topic: ?string, query: array, count: int}> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY . '.' . md5(serialize(config('insights.presets'))), (int) config('insights.scope_ttl'), fn () => self::resolve());
    }

    public static function resolve(): array
    {
        $presets = [];
        foreach (config('insights.presets') as $preset) {
            $scope = self::scope($preset['scope']);
            if ($scope === null) {
                continue;
            }
            $count = $scope->count();
            if ($count >= (int) config('insights.preset_min_copies')) {
                $presets[] = ['label' => $preset['label'], 'note' => $preset['note'], 'topic' => $preset['topic'] ?? null, 'query' => $scope->query(), 'count' => $count];
            }
        }

        return $presets;
    }

    /** The scope of a preset, or null when one of its filters names nothing in this data */
    public static function scope(array $filters): ?InsightsScope
    {
        $input = [];
        foreach ($filters as $param => $value) {
            $input[$param] = match ($param) {
                'author', 'scribe' => Person::where('slug', $value)->value('id'),
                'work' => Work::where('slug', $value)->value('id'),
                'library' => Library::where('slug', $value)->value('id'),
                'subject' => Subject::where('name', $value)->value('id'),
                'language' => Language::where('name', $value)->value('id'),
                'script' => Script::where('name', $value)->value('id'),
                default => $value,
            };
        }
        $scope = InsightsScope::fromArray($input);

        // A filter the scope dropped (an unknown record, a city off the map) would widen the preset: leave it out
        return count($scope->values) === count($filters) ? $scope : null;
    }

    /** Builds the figures of every preset shown, so its link opens at once (the nightly build) */
    public static function warm(): int
    {
        Cache::forget(self::CACHE_KEY . '.' . md5(serialize(config('insights.presets'))));
        $presets = self::all();
        foreach ($presets as $preset) {
            Insights::refreshScope(InsightsScope::fromArray($preset['query']));
        }

        return count($presets);
    }
}
