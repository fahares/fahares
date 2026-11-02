<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FillLineCountsCommandTest extends TestCase
{
    private ?int $work = null;
    private ?int $copy = null;

    protected function tearDown(): void
    {
        DB::table('manuscripts')->where('id', $this->copy)->delete();
        DB::table('works')->where('id', $this->work)->delete();
        parent::tearDown();
    }

    public function test_layouts_are_filled_from_the_text_and_shown_on_the_copys_page()
    {
        config(['scout.driver' => 'collection']);
        $this->work = DB::table('works')->insertGetId([
            'primary_title' => 'اثر آزمایشی سطرها', 'clean_title' => 'اثر آزمایشی سطرها',
            'slug' => 'test-lines-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);
        $this->copy = DB::table('manuscripts')->insertGetId([
            'work_id' => $this->work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => '16/1-ف', 'lines' => 21,
            'raw_text' => "تهران؛ دانشگاه؛ شماره نسخه: 16/1-ف\nخط: نسخ؛ 316گ، 18 و 21 سطر، اندازه: 20×27سم",
        ]);

        $this->artisan('fahares:fill-line-counts', ['--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $this->copy)->first();
        $this->assertSame('18 و 21 سطر', $row->lines_raw);
        $this->assertSame([[18, 18], [21, 21]], json_decode($row->line_counts, true));
        // the single count the site sorts by stays
        $this->assertSame(21, (int) $row->lines);
        $this->followingRedirects()->get("/m/{$this->copy}")->assertOk()->assertSee('۱۸ و ۲۱ سطر');
    }
}
