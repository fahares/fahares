<?php

namespace Tests\Feature;

use App\Console\Commands\MatchCatalogEditionCommand;
use App\Console\Commands\TieLivesToPeopleCommand as Tie;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use Tests\TestCase;

/**
 * A person tied to his life in a book of lives from his works tied to it (step 14.83): by the life's heading, not by the
 * work's title in another man's life; the life most of his works give; nothing from ties by the family name alone.
 */
class TieLivesToPeopleTest extends TestCase
{
    public function test_a_persons_works_tied_to_his_life_give_him_the_life_and_his_page_shows_it()
    {
        $source = Source::create(['code' => 'lives-people', 'kind' => Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'معجم المؤلفین',
            'short_name' => 'معجم المؤلفین']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3, 'facsimile_code' => 'lives-people']);
        $life = fn (int $page, string $title, string $text) => SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id,
            'volume_number' => 3, 'item_key' => "{$page}#1", 'position' => $page, 'kind' => 'entry', 'page_start' => $page, 'page_end' => $page,
            'title' => $title, 'title_normalized' => MatchCatalogEditionCommand::norm($title), 'text' => $text, 'text_status' => 'auto']);
        $his = $life(41, 'عبد الكريم بن هوازن القشيري', 'عبد الكريم بن هوازن القشيري، صوفي. من آثاره «الرسالة».');
        $other = $life(42, 'محمد بن احمد الطوسي', 'محمد بن احمد الطوسي. نقل عن «الرسالة القشيرية».');
        $another = $life(43, 'عبد الكريم القشيري الصغير', 'عبد الكريم القشيري الصغير.');

        $person = Person::create(['name' => 'قشیری، عبدالکریم بن هوازن', 'normalized_name' => 'قشیری عبدالکریم بن هوازن', 'slug' => 'qushayri-' . uniqid(),
            'death_year_hijri' => 465]);
        $tie = function (string $title, SourceItem $item, string $method, int $confidence, string $status = SourceEntry::STATUS_UNVERIFIED) use ($person, $source) {
            $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1,
                'page_start' => 1, 'author_name' => $person->name, 'author_id' => $person->id]);
            SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $source->id, 'source_item_id' => $item->id,
                'volume_number' => 3, 'page_start' => $item->page_start, 'role' => SourceEntry::ROLE_MENTION, 'origin' => SourceEntry::ORIGIN_MATCH,
                'match_method' => $method, 'confidence' => $confidence, 'status' => $status]);

            return $work;
        };
        $tie('الرسالة', $his, 'author_life_title', 90);
        $tie('لطائف الاشارات', $his, 'author_life', 75);
        // the work's title in another man's life is no life of its author's
        $tie('الرسالة القشیریة', $other, 'title_in_text', 40);

        $this->artisan('sources:tie-lives-to-people')->assertSuccessful();
        $mine = SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->get();
        $this->assertCount(1, $mine);
        $this->assertSame([$his->id, SourceEntry::ROLE_ENTRY, Tie::METHOD, 90, SourceEntry::STATUS_UNVERIFIED],
            [$mine[0]->source_item_id, $mine[0]->role, $mine[0]->match_method, $mine[0]->confidence, $mine[0]->status]);

        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع')->assertSee('عبد الكريم بن هوازن القشيري');

        // run again: the same tie; a reviewer's verdict on a work's tie carries over
        SourceEntry::where('citable_type', Work::class)->where('source_item_id', $his->id)->where('match_method', 'author_life')
            ->update(['status' => SourceEntry::STATUS_VERIFIED]);
        $this->artisan('sources:tie-lives-to-people')->assertSuccessful();
        $this->assertSame(SourceEntry::STATUS_VERIFIED, SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->sole()->status);

        // a person whose only tie is by the family name alone (30) gets nothing
        $weak = Person::create(['name' => 'قشیری، عبدالکریم', 'normalized_name' => 'قشیری عبدالکریم', 'slug' => 'q2-' . uniqid()]);
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
            'author_name' => $weak->name, 'author_id' => $weak->id]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $source->id, 'source_item_id' => $another->id,
            'volume_number' => 3, 'page_start' => 43, 'role' => SourceEntry::ROLE_MENTION, 'origin' => SourceEntry::ORIGIN_MATCH,
            'match_method' => 'edition_place', 'confidence' => 30, 'status' => SourceEntry::STATUS_UNVERIFIED]);
        $this->artisan('sources:tie-lives-to-people')->assertSuccessful();
        $this->assertFalse(SourceEntry::where('citable_type', Person::class)->where('citable_id', $weak->id)->exists());
    }
}
