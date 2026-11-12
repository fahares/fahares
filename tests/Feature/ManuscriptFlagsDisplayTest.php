<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 14.150: the copy page shows only the codicological marks the record has; the rest wait for suggestion mode */
class ManuscriptFlagsDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function copy(array $flags): Manuscript
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی نشان‌ها', 'clean_title' => 'اثر آزمایشی نشان‌ها',
            'slug' => 'flags-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'library' => 'کتابخانه‌ی آزمایشی', 'city' => 'قم', 'shelfmark' => '۱۲', 'raw_text' => '1. قم؛ کتابخانه‌ی آزمایشی', ...$flags]);
    }

    public function test_unset_marks_are_hidden_outside_suggestion_mode()
    {
        $html = $this->get(route('manuscripts.show', $this->copy(['is_corrected' => true, 'has_marginal_notes' => true])))
            ->assertOk()->getContent();

        // the two set marks are plain rows; the section is always shown
        $this->assertMatchesRegularExpression('/<li[^>]*data-flag="is_corrected"(?![^>]*x-show)[^>]*>/u', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-flag="has_marginal_notes"(?![^>]*x-show)[^>]*>/u', $html);
        $this->assertMatchesRegularExpression('/<section(?![^>]*x-show)[^>]*data-flags-section[^>]*>/u', $html);
        $this->assertSame(2, preg_match_all('/data-flag="/u', $html));

        // the eleven others are there for suggestion mode only, and say what an unset mark means
        $this->assertSame(11, preg_match_all('/<li[^>]*data-flag-unset="[a-z_]+" x-show="\$store\.suggest\.on" x-cloak/u', $html));
        $this->assertStringNotContainsString('ندارد</span>', $html);
        $this->assertStringContainsString('(ثبت نشده)', $html);
    }

    public function test_a_copy_with_no_mark_hides_the_whole_section()
    {
        $html = $this->get(route('manuscripts.show', $this->copy([])))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<section[^>]*data-flags-section[^>]*>/u', $html, $m));
        $this->assertStringContainsString('x-show="$store.suggest.on"', $m[0]);
        $this->assertStringContainsString('x-cloak', $m[0]);
        $this->assertSame(0, preg_match_all('/data-flag="/u', $html));
        $this->assertSame(13, preg_match_all('/data-flag-unset="/u', $html));
    }
}
