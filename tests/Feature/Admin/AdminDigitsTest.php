<?php

namespace Tests\Feature\Admin;

use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Support\AdminNav;
use App\Support\Fa;
use Tests\TestCase;

/**
 * The panel shows Persian digits only (backlog item 89, step 14.70): every page of the admin menu, and the review desk,
 * is searched for a Latin or Arabic-Indic digit in its visible text. Code, left-to-right spans (emails, addresses),
 * form controls, scripts and attributes are left out: they hold values to copy or edit, not text to read.
 */
class AdminDigitsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر آزمایشی', 'email' => 'digits-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
    }

    public function test_fa_digits_turns_arabic_indic_digits_persian(): void
    {
        $this->assertSame('۱۲۳ و ۴۵۶', Fa::digits('123 و ٤٥٦'));

        // the scanner itself: text is caught, code, ltr spans, fields and Alpine attributes are not
        $this->assertSame(['ص 12', 'ج ٣'], self::digitsInText('<body><p>ص 12</p><b>ج ٣</b><code>id 5</code><span dir="ltr">a@b7.org</span>'
            . '<input value="9"><div x-show="n > 1" @click="n = 2" :class="{ a: n > 3 }">۴</div></body>'));
    }

    public function test_menu_pages_and_review_desk_have_no_latin_digits(): void
    {
        $person = Person::create(['name' => 'شخص آزمایشی ارقام', 'normalized_name' => 'شخص آزمایشی ارقام', 'slug' => 'digits-person-' . uniqid(), 'is_author' => true, 'death_year_hijri' => 1091]);
        $library = Library::create(['name' => 'کتابخانه‌ی آزمایشی ارقام', 'city' => 'قم', 'slug' => 'digits-library-' . uniqid()]);
        \App\Models\AuditLog::create(['auditable_type' => Library::class, 'auditable_id' => $library->id, 'action' => 'update', 'user_id' => $this->admin->id,
            'old_values' => ['name' => 'نام پیشین'], 'new_values' => ['name' => 'نام تازه'], 'created_at' => now()]);
        $work = Work::create(['primary_title' => 'اثر آزمایشی ٣٤', 'clean_title' => 'اثر آزمایشی', 'slug' => 'digits-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1, 'author_name' => 'نام ۱']);
        $suggestion = FieldSuggestion::create(['suggestable_type' => Work::class, 'suggestable_id' => $work->id, 'kind' => 'field', 'field_name' => 'author_name',
            'suggested_value' => 'نام ۲', 'rationale_citation' => 'الذریعة', 'guest_name' => 'پژوهشگر', 'status' => 'pending']);

        $uris = [];
        foreach (AdminNav::groups($this->admin) as $group) {
            foreach ($group['items'] as $item) {
                $uris[] = $item['url'];
            }
        }
        $uris[] = route('admin.suggestions.show', $suggestion);
        $uris[] = route('admin.catalogers.create');
        $uris[] = route('admin.people.edit', $person);

        $found = [];
        // pages of the public layout (my notifications) are the site's, not the panel's
        $uris = array_filter($uris, fn ($u) => str_starts_with(parse_url($u, PHP_URL_PATH), '/admin'));
        foreach (array_unique($uris) as $uri) {
            $html = $this->actingAs($this->admin)->get($uri)->assertOk()->getContent();
            foreach (self::digitsInText($html) as $snippet) {
                $found[] = parse_url($uri, PHP_URL_PATH) . ': ' . $snippet;
            }
        }

        $this->assertSame([], $found, "Latin or Arabic digits in the panel's text:\n" . implode("\n", $found));
    }

    public function test_field_key_is_shown_to_the_admin_only(): void
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی کلید', 'clean_title' => 'اثر آزمایشی کلید', 'slug' => 'key-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1, 'author_name' => 'نام']);
        $suggestion = FieldSuggestion::create(['suggestable_type' => Work::class, 'suggestable_id' => $work->id, 'kind' => 'field', 'field_name' => 'author_name',
            'suggested_value' => 'نام دیگر', 'rationale_citation' => 'منبع', 'guest_name' => 'پژوهشگر', 'status' => 'pending']);
        $editor = User::create(['name' => 'ویراستار', 'email' => 'digits-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);

        $this->actingAs($this->admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()->assertSee('title="کلید فنی فیلد"', false);
        $this->actingAs($editor)->get(route('admin.suggestions.show', $suggestion))->assertOk()->assertSee('پدیدآور')->assertDontSee('title="کلید فنی فیلد"', false);
    }

    /** @return list<string> each text node of the page that holds a Latin or Arabic-Indic digit */
    private static function digitsInText(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        // libxml takes Alpine's «@click» and «:class» for broken markup and spills the attribute into the text
        $html = preg_replace('/(\s)[@:]([\w.:-]+=)/u', '$1data-alpine-$2', $html);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $skip = '//script | //style | //code | //pre | //kbd | //input | //textarea | //select | //template | //svg | //*[@dir="ltr"] | //*[@translate="no"] | //head';
        foreach (iterator_to_array($xpath->query($skip)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $out = [];
        foreach ($xpath->query('//body//text()') as $text) {
            $value = trim(preg_replace('/\s+/u', ' ', $text->nodeValue));
            if ($value !== '' && preg_match('/[0-9\x{0660}-\x{0669}]/u', $value)) {
                $out[] = mb_substr($value, 0, 80);
            }
        }

        return $out;
    }
}
