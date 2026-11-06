<?php

namespace Tests\Feature\Admin;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Models\Work;
use App\Support\WordDiff;
use Tests\TestCase;

/**
 * The review desk after its redesign (step 14.72): the record's title on top and in the lists, the suggestion as one
 * sentence with a word-level comparison, the submitter's record, and the decision panel or the outcome beside it.
 */
class ReviewDeskTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر میز داوری', 'email' => 'desk-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
    }

    private function work(string $title = 'الفوائد الرجالیة', string $author = 'محمدمهدی بحرالعلوم'): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'desk-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1, 'author_name' => $author]);
    }

    private function suggestion(Work $work, array $attributes = []): FieldSuggestion
    {
        return FieldSuggestion::create($attributes + ['suggestable_type' => Work::class, 'suggestable_id' => $work->id, 'kind' => 'field', 'field_name' => 'author_name',
            'current_value' => $work->author_name, 'suggested_value' => 'سید محمدمهدی بن مرتضی طباطبایی بحرالعلوم',
            'rationale_citation' => 'الذریعة، ج ۱۶، ص ۳۳۴', 'guest_name' => 'علی رضایی', 'status' => 'pending']);
    }

    public function test_desk_names_the_record_and_states_the_suggestion_with_a_word_diff(): void
    {
        $work = $this->work();
        $suggestion = $this->suggestion($work);

        $html = $this->actingAs($this->admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()
            ->assertSee('data-record-title', false)
            ->assertSee('الفوائد الرجالیة')
            ->assertSee('دیدن رکورد در سایت')
            ->assertSee('data-decision-panel', false)
            ->assertSee('ابزارهای دیگر')
            ->getContent();

        $claim = $this->section($html, 'data-claim');
        $this->assertStringContainsString('«علی رضایی»', $claim);
        $this->assertStringContainsString('پیشنهاد می‌کند <b>پدیدآور</b> این اثر از <b>«محمدمهدی بحرالعلوم»</b> به <b>«سید محمدمهدی بن مرتضی طباطبایی بحرالعلوم»</b> تغییر کند', preg_replace('/\s+/u', ' ', $claim));

        // the additions are marked, the kept words are not
        $this->assertMatchesRegularExpression('#<ins[^>]*data-diff="ins">سید</ins>#u', $html);
        $this->assertMatchesRegularExpression('#<ins[^>]*data-diff="ins">بن مرتضی طباطبایی</ins>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#<del[^>]*data-diff="del">#u', $html);

        // the evidence sits under the claim, the decision has its three choices and keeps the forms' fields
        $this->assertLessThan(strpos($html, 'data-evidence'), strpos($html, 'data-claim'));
        $this->assertStringContainsString('x-data="{ choice: \'approve\' }"', $html);
        foreach (['approve', 'reject', 'ask'] as $choice) {
            $this->assertStringContainsString('data-choice="' . $choice . '"', $html);
        }
        $this->assertStringContainsString('name="applied_value"', $html);
        $this->assertStringContainsString('name="outcome" value="adopt"', $html);
        $this->assertStringContainsString('data-outcome', $html);
        $this->assertStringContainsString('data-approve-summary', $html);
    }

    public function test_a_dropped_word_is_struck_and_a_long_text_shows_only_its_changed_part(): void
    {
        $work = $this->work();
        $suggestion = $this->suggestion($work, ['suggested_value' => 'بحرالعلوم']);
        $this->actingAs($this->admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()
            ->assertSee('data-diff="del">محمدمهدی</del>', false);

        $words = implode(' ', array_map(fn ($i) => 'کلمه' . \App\Support\Fa::digits($i), range(1, 80)));
        $long = $this->suggestion($work, ['field_name' => 'description', 'current_value' => $words, 'suggested_value' => str_replace('کلمه۴۰ ', 'واژه‌ی تازه ', $words)]);
        $html = $this->actingAs($this->admin)->get(route('admin.suggestions.show', $long))->assertOk()->getContent();
        $diff = $this->section($html, 'data-diff-table');
        $this->assertStringContainsString('data-diff="gap"', $diff);
        $this->assertStringContainsString('واژه‌ی تازه', $diff);
        $this->assertStringNotContainsString('کلمه۱ ', $diff);
        $this->assertStringContainsString('متن کامل دو مقدار', $html);
    }

    public function test_submitter_card_counts_their_suggestions(): void
    {
        $user = User::create(['name' => 'پژوهشگر پرکار', 'email' => 'desk-scholar@fahares.net', 'password' => 'password123', 'role' => 'user']);
        $work = $this->work();
        foreach (['approved', 'approved', 'approved', 'rejected', 'pending'] as $status) {
            $this->suggestion($work, ['user_id' => $user->id, 'status' => $status]);
        }
        $this->suggestion($work, ['status' => 'approved']); // another submitter's
        $current = $this->suggestion($work, ['user_id' => $user->id]);

        $html = $this->actingAs($this->admin)->get(route('admin.suggestions.show', $current))->assertOk()->getContent();
        $stats = $this->section($html, 'data-submitter-stats');
        $this->assertStringContainsString('۳ تأییدشده', $stats);
        $this->assertStringContainsString('۱ ردشده', $stats);
        $this->assertStringContainsString('۲ در انتظار', $stats);
    }

    public function test_a_decided_suggestion_shows_its_outcome_in_the_panel(): void
    {
        $suggestion = $this->suggestion($this->work(), ['status' => 'rejected', 'public_note' => 'مستند کافی نبود', 'reviewed_by' => $this->admin->id, 'reviewed_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()
            ->assertSee('data-outcome-summary', false)
            ->assertDontSee('data-choice=', false)
            ->assertSee('مستند کافی نبود')
            ->assertSee('لغو رد و بازگشت به صف داوری');
    }

    public function test_list_and_dashboard_rows_name_the_record_and_the_change(): void
    {
        $work = $this->work('رسالة فی الرضاع ٣');
        $this->suggestion($work);

        $html = $this->actingAs($this->admin)->get(route('admin.suggestions.index'))->assertOk()
            ->assertSee('رسالة فی الرضاع ۳')
            ->getContent();
        $this->assertMatchesRegularExpression('#data-from>محمدمهدی بحرالعلوم</span>\s*<span[^>]*>←</span>\s*<span[^>]*data-to>سید محمدمهدی#u', $html);

        // a long text shows its changed words, not the start both share
        $words = implode(' ', array_map(fn ($i) => 'کلمه' . \App\Support\Fa::digits($i), range(1, 30)));
        $this->suggestion($work, ['field_name' => 'description', 'current_value' => $words, 'suggested_value' => str_replace('کلمه۲۰ ', 'واژه‌ی تازه ', $words)]);
        $html = $this->actingAs($this->admin)->get(route('admin.suggestions.index'))->assertOk()->getContent();
        $start = strpos($html, 'data-change');
        $this->assertNotFalse($start);
        $change = substr($html, $start, strpos($html, '</td>', $start) - $start);
        $this->assertStringContainsString('data-diff="del">کلمه۲۰</del>', $change);
        $this->assertStringContainsString('data-diff="ins">واژه‌ی تازه</ins>', $change);
        $this->assertStringNotContainsString('کلمه۱ ', $change);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('رسالة فی الرضاع ۳')
            ->assertSee('محمدمهدی بحرالعلوم ←');
    }

    public function test_word_diff_marks_words_and_shortens_long_kept_stretches(): void
    {
        $ops = WordDiff::ops('۱۰۷۲ ق کتابت شد', '۱۰۲۲ ق کتابت شد');
        $this->assertSame([['-', '۱۰۷۲'], ['+', '۱۰۲۲'], ['=', ' ق کتابت شد']], $ops);
        $this->assertTrue(WordDiff::changed($ops));
        $this->assertFalse(WordDiff::changed(WordDiff::ops("الف  ب", 'الف ب')));
        $this->assertSame([['-', '۱۰۷۲'], ['=', ' ق کتابت شد']], WordDiff::side($ops, 'old'));

        $long = WordDiff::compact(WordDiff::ops(str_repeat('واژه ', 60) . 'پایان', str_repeat('واژه ', 60) . 'انجام'), 3);
        $this->assertSame('…', $long[0][0]);
        $this->assertSame(['-', 'پایان'], $long[2]);
        $this->assertSame(' واژه واژه واژه ', $long[1][1]);
    }

    /** The element carrying the data attribute, up to its closing tag (no nesting of the same tag inside). */
    private function section(string $html, string $attribute): string
    {
        $start = strpos($html, $attribute);
        $this->assertNotFalse($start, "no $attribute on the page");
        $open = strrpos(substr($html, 0, $start), '<');
        preg_match('/<(\w+)/', substr($html, $open), $m);

        return substr($html, $open, strpos($html, '</' . $m[1] . '>', $start) - $open);
    }
}
