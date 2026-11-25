<?php

namespace Tests\Feature;

use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use Tests\TestCase;

/** Saving a copy rewrites only its citation entry's own columns, not what a match or a review wrote on it (backlog item 166). */
class CitedEntryKeepsMatchTest extends TestCase
{
    protected Library $library;

    protected CatalogVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        $this->volume = CatalogVolume::create(['title' => 'فهرست آزمایشی - جلد پنجم', 'volume_number' => 5, 'library_id' => $this->library->id]);
    }

    protected function copy(string $raw): Manuscript
    {
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create(['work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $this->volume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'raw_text' => $raw]);
    }

    protected function entry(Manuscript $m, string $type = 'fankha_citation'): SourceEntry
    {
        return SourceEntry::where('legacy_type', $type)->where('legacy_id', $m->id)->orderBy('legacy_index')->firstOrFail();
    }

    /** What a match writes: the entry found a page before the citation's, its text, number and shelfmark page. */
    protected function match(SourceEntry $entry, int $page): array
    {
        $values = ['page_start' => $page, 'page_end' => $page + 1, 'entry_number' => 1234, 'shelfmark_page' => $page + 1,
            'entry_text' => 'متن مدخل در فهرست', 'copy_text' => 'سطر نسخه در فهرست', 'text_volume_number' => 5, 'text_page' => $page,
            'text_status' => SourceEntry::TEXT_AUTO, 'match_method' => 'shelfmark', 'confidence' => 90,
            'status' => SourceEntry::STATUS_VERIFIED, 'notes' => 'یادداشت بازبین'];
        $entry->forceFill($values)->saveQuietly();

        return $values;
    }

    public function test_a_save_keeps_the_matched_text_and_the_corrected_page(): void
    {
        $copy = $this->copy('نسخه، 21گ، 15 سطر [ف: 5-197]');
        $entry = $this->entry($copy);
        $this->assertSame([5, 197], [$entry->volume_number, $entry->page_start]);
        $matched = $this->match($entry, 196);

        // an admin's edit of the copy, as any Eloquent save
        $copy->update(['shelfmark' => '777']);

        $after = $this->entry($copy);
        $this->assertSame($entry->id, $after->id);
        foreach ($matched as $column => $value) {
            $this->assertEquals($value, $after->$column, $column);
        }
        $this->assertSame('[ف: 5-197]', $after->citation_text);
        $this->assertSame($this->volume->id, $after->source_volume_id);
    }

    public function test_an_entry_nothing_corrected_follows_its_citation(): void
    {
        $copy = $this->copy('نسخه [ف: 5-197]');
        $entry = $this->entry($copy);
        $entry->forceFill(['copy_text' => 'سطر نسخه', 'text_volume_number' => 5, 'text_page' => 197, 'text_status' => SourceEntry::TEXT_AUTO])->saveQuietly();

        // the citation now names another page: the entry moves there, its text kept and marked stale
        $copy->update(['raw_text' => 'نسخه [ف: 5-300]']);
        $after = $this->entry($copy);
        $this->assertSame([5, 300], [$after->volume_number, $after->page_start]);
        $this->assertSame('سطر نسخه', $after->copy_text);
        $this->assertSame(SourceEntry::TEXT_STALE, $after->text_status);
    }

    public function test_a_corrected_entry_whose_citation_names_another_place_moves_there(): void
    {
        $copy = $this->copy('نسخه [ف: 5-197]');
        $this->match($this->entry($copy), 196);

        $copy->update(['raw_text' => 'نسخه [ف: 5-410]']);
        $after = $this->entry($copy);
        $this->assertSame([5, 410], [$after->volume_number, $after->page_start]);
        $this->assertNull($after->page_end);
        $this->assertSame('متن مدخل در فهرست', $after->entry_text);
        $this->assertSame(SourceEntry::TEXT_STALE, $after->text_status);
        // a citation edited only where it reads the same keeps the corrected page
        $other = $this->copy('نسخه [ف: 5-197]');
        $this->match($this->entry($other), 196);
        $other->update(['raw_text' => 'نسخه [ف: 5 - 197]']);
        $this->assertSame(196, $this->entry($other)->page_start);
        $this->assertSame('[ف: 5 - 197]', $this->entry($other)->citation_text);
    }

    public function test_an_other_place_keeps_what_a_match_wrote(): void
    {
        // the last bracket is the catalogue entry's, the first another place
        $copy = $this->copy('نسخه [ف: 5-640] و [ف: 5-197]');
        $other = $this->entry($copy, 'fankha_citation_more');
        $this->assertSame(640, $other->page_start);
        $other->forceFill(['page_start' => 639, 'entry_number' => 88, 'copy_text' => 'سطر نسخه', 'text_volume_number' => 5, 'text_page' => 639,
            'text_status' => SourceEntry::TEXT_AUTO])->saveQuietly();

        $copy->update(['shelfmark' => '778']);
        $after = $this->entry($copy, 'fankha_citation_more');
        $this->assertSame([639, 88, 'سطر نسخه', SourceEntry::TEXT_AUTO], [$after->page_start, $after->entry_number, $after->copy_text, $after->text_status]);
    }
}
