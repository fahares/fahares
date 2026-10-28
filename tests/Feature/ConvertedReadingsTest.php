<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Suggestions\SuggestionReadings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConvertedReadingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_fields_converted_from_one_suggestion_read_as_one_section()
    {
        $person = Person::create(['name' => 'آزمون سده', 'normalized_name' => 'ازمون سده', 'slug' => 'test-century-'.uniqid()]);
        // The original suggestion is approved with its value cleared; the century is converted from it
        $originalId = null;
        foreach (['death_year_hijri' => ['۳۲۹', ''], 'century_hijri' => [null, '4']] as $field => [$current, $value]) {
            $id = FieldSuggestion::create([
                'suggestable_type' => Person::class, 'suggestable_id' => $person->id, 'kind' => 'field',
                'field_name' => $field, 'current_value' => $current, 'suggested_value' => $value,
                'payload' => $originalId ? ['derived_from' => $originalId] : null, 'status' => 'approved', 'standing' => 'adopted', 'reviewed_at' => now(),
            ])->id;
            $originalId ??= $id;
        }

        $readings = SuggestionReadings::for($person);

        $this->assertSame(1, $readings->reject(fn ($r) => isset($r['merged_into']))->count());
        $this->assertSame('death_year_hijri', $readings['century_hijri']['merged_into']);
        $this->assertSame("سال وفات (قمری): نامشخص\nسده (قمری): 4", $readings['death_year_hijri']['value']);
        $this->assertNotNull(SuggestionReadings::badge($readings, 'century_hijri'));
    }
}
