<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonReview;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Tests\TestCase;

/** Step 14.193: lives tied to more than one person are settled from a judged list: merged, rejected or queued. */
class SettleDoubleLivesTest extends TestCase
{
    public function test_merges_rejects_and_queues_from_the_judged_list(): void
    {
        $code = 'double-' . uniqid();
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'الأعلام', 'short_name' => 'الأعلام']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => $code]);
        $item = fn (string $key) => SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 1,
            'item_key' => $key, 'position' => 1, 'kind' => 'entry', 'page_start' => 1, 'page_end' => 1, 'title' => 'x', 'title_normalized' => 'x',
            'text' => 'x', 'text_status' => 'auto']);
        $person = fn (string $n) => Person::create(['name' => $n, 'normalized_name' => $n, 'slug' => 'dl-' . uniqid(), 'works_count' => 0, 'manuscripts_count' => 0]);
        $tie = fn (Person $p, $it, int $n) => SourceEntry::create(['citable_type' => Person::class, 'citable_id' => $p->id, 'source_id' => $source->id,
            'source_item_id' => $it->id, 'volume_number' => 1, 'page_start' => 1, 'role' => 'entry', 'origin' => 'match',
            'legacy_type' => 'test_double', 'legacy_id' => $p->id * 10 + $n, 'legacy_index' => 0]);

        $one = $item('1#1');
        $two = $item('2#1');
        $three = $item('3#1');
        [$keep, $gone] = [$person('کشفی، جعفر'), $person('کاشفی، جعفر')];
        $tie($keep, $one, 1);
        $tie($gone, $one, 1);
        [$owner, $wrong] = [$person('مجریطی، مسلمة'), $person('مجریطی، محمد')];
        $tie($owner, $two, 1);
        $wrongTie = $tie($wrong, $two, 1);
        [$a, $b] = [$person('خوئی، ابراهیم'), $person('دنبلی خوئی، ابراهیم')];
        $tie($a, $three, 1);
        $tie($b, $three, 1);

        $file = 'storage/framework/testing-double-lives.json';
        file_put_contents(base_path($file), json_encode([
            'merges' => [['keep' => $keep->slug, 'merge' => [$gone->slug]]],
            'rejects' => [['person_slug' => $wrong->slug, 'source' => $code, 'item_key' => '2#1', 'volume' => 1]],
            'unsure' => [['slugs' => [$a->slug, $b->slug], 'note' => 'نامطمئن']],
        ]));
        $this->artisan('fahares:settle-double-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertNull(Person::find($gone->id));
        $this->assertSame(1, SourceEntry::where('citable_type', Person::class)->where('citable_id', $keep->id)->where('source_item_id', $one->id)->count());
        $this->assertSame(SourceEntry::STATUS_REJECTED, $wrongTie->fresh()->status);
        $this->assertNotNull($wrongTie->fresh()->reviewed_at);
        $ids = collect([$a->id, $b->id])->sort()->values()->all();
        $this->assertTrue(PersonReview::where('type', 'duplicate')->where('person_ids', json_encode($ids))->exists());

        // a second run finds the merged record gone into the kept one and changes nothing
        $this->artisan('fahares:settle-double-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, PersonReview::where('type', 'duplicate')->where('person_ids', json_encode($ids))->count());
        @unlink(base_path($file));
    }
}
