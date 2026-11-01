<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A known ruler's spellings in the dedication field become other names of his record (backlog item 35). */
class AddRulerAliasesTest extends TestCase
{
    public function test_a_rulers_spellings_are_added_once_to_his_other_names()
    {
        config(['scout.driver' => 'collection']);
        DB::table('people')->where('name', 'سنجر سلجوقی، سلطان')->delete();
        $id = DB::table('people')->insertGetId(['name' => 'سنجر سلجوقی، سلطان', 'normalized_name' => 'سنجر سلجوقی، سلطان',
            'slug' => 'test-sanjar-' . uniqid(), 'aliases' => json_encode(['سلطان  سنجر'])]);
        try {
            $this->artisan('fahares:add-ruler-aliases')->expectsOutputToContain('سلطان سنجر سلجوقی')->assertSuccessful();
            $this->assertSame(['سلطان  سنجر'], Person::find($id)->aliases);

            $this->artisan('fahares:add-ruler-aliases', ['--apply' => true])->assertSuccessful();
            // «سلطان  سنجر» is already there, written with two spaces
            $this->assertSame(['سلطان  سنجر', 'سلطان سنجر سلجوقی', 'سنجر بن ملکشاه'], Person::find($id)->aliases);
            $this->assertSame(1, AuditLog::where('auditable_type', Person::class)->where('auditable_id', $id)->count());

            $this->artisan('fahares:add-ruler-aliases', ['--apply' => true])->assertSuccessful();
            $this->assertCount(3, Person::find($id)->aliases);
        } finally {
            AuditLog::where('auditable_type', Person::class)->where('auditable_id', $id)->delete();
            DB::table('people')->where('id', $id)->delete();
        }
    }
}
