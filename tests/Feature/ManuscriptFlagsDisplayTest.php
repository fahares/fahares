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

    /** Step 14.157: the main fields always show, «ثبت نشده» when empty; the lesser ones wait for suggestion mode */
    public function test_empty_fields_say_so_or_wait_for_suggestion_mode()
    {
        $html = $this->get(route('manuscripts.show', $this->copy(['paper' => 'فرنگی'])))->assertOk()
            ->assertSeeInOrder(['کاتب', 'ثبت نشده', 'تاریخ کتابت', 'ثبت نشده', 'محل کتابت', 'ثبت نشده', 'خط', 'ثبت نشده', 'شمار برگ', 'ثبت نشده'])
            ->assertDontSee('نامشخص</span>', false)
            ->getContent();

        // lines, size and binding are empty: their rows are there for suggestion mode only; the paper's row is plain
        $rows = preg_match_all('/<div class="contents suggest-row"[^>]*x-show[^>]*>\s*<dt[^>]*>(شمار سطر|اندازه \(سانتی‌متر\)|جلد|سفارش‌دهنده)<\/dt>/u', $html);
        $this->assertSame(4, $rows);
        $this->assertMatchesRegularExpression('/<div class="contents suggest-row"(?![^>]*x-show)[^>]*>\s*<dt[^>]*>کاغذ<\/dt>/u', $html);
    }

    /** The «خط» row holds two fields: the pencil of an empty «کیفیت خط» waits for suggestion mode, with its name */
    public function test_the_script_row_shows_one_pencil_when_the_style_is_empty()
    {
        $html = $this->get(route('manuscripts.show', $this->copy(['script_names' => 'نسخ'])))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/data-suggest-field="script_names"/u', $html));
        $this->assertMatchesRegularExpression('/<span x-show="\$store\.suggest\.on" x-cloak data-script-style-empty>.*?data-suggest-field="script_style"/su', $html);

        $html = $this->get(route('manuscripts.show', $this->copy(['script_names' => 'نسخ', 'script_style' => 'خوش'])))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-script-style-empty', $html);
        $this->assertMatchesRegularExpression('/\(خوش\)<\/span>\s*<button[^>]*data-suggest-field="script_style"[^>]*title="پیشنهاد اصلاح کیفیت خط"/su', $html);
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
