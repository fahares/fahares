<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as Importer;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SourceEntrySync::fitToVolume: a citation's volume and page, read with the tied catalogue volume in mind (step 14.327)
 */
class CitationFitToVolumeTest extends TestCase
{
    private array $sources = [];

    protected function tearDown(): void
    {
        DB::table('source_volumes')->whereIn('source_id', $this->sources)->delete();
        DB::table('sources')->whereIn('id', $this->sources)->delete();
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<int, int>} the source's id and its volumes' ids by number */
    private function source(array $volumes): array
    {
        $kind = Source::KIND_BIBLIOGRAPHY;
        $source = Source::create(['code' => 'test-fit-' . uniqid(), 'kind' => $kind, 'rank' => Source::KIND_RANKS[$kind], 'title' => 'فهرست آزمایشی', 'volumes_count' => count($volumes)]);
        $this->sources[] = $source->id;
        $ids = [];
        foreach ($volumes as $number => $pages) {
            $ids[$number] = DB::table('source_volumes')->insertGetId(['source_id' => $source->id, 'volume_number' => $number, 'title' => "جلد {$number}",
                'page_first' => $pages[0] ?? null, 'page_last' => $pages[1] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$source->id, $ids];
    }

    private function fit(string $raw, int $volumeId, int $sourceId): array
    {
        $fitted = SourceEntrySync::fitToVolume($raw, Importer::parse($raw), $volumeId, $sourceId);

        return [$fitted['volume'], $fitted['page']];
    }

    public function test_a_lone_number_is_the_page_of_a_one_volume_catalogue(): void
    {
        [$one, $v] = $this->source([1 => [1242, 1797]]);
        $this->assertSame([null, 1421], $this->fit('[رشت و همدان: ف: 1421]', $v[1], $one));
        $this->assertSame([null, 1476], $this->fit('[رشت و همدان - ف: 1476]', $v[1], $one));
        $this->assertSame([null, 1718], $this->fit('[رشت و همدان - ف: 1718 (مدرسه خیرات خان، ش 21)]', $v[1], $one));
        // a number outside the volume's known pages is left
        $this->assertSame([null, null], $this->fit('[رشت و همدان: ف: 1090]', $v[1], $one));

        // a number before a dash reads as a volume, which a catalogue of one volume does not have
        $this->assertSame([null, 1300], $this->fit('[رشت و همدان: ف: 1300 -]', $v[1], $one));
        $this->assertSame([null, 1356], $this->fit('[ف: 1356-]', $v[1], $one));

        [$unknown, $u] = $this->source([1 => []]);
        $this->assertSame([null, 109], $this->fit('[ف: 109]', $u[1], $unknown));

        // in a catalogue of several volumes the number may be either
        [$many, $m] = $this->source([1 => [], 2 => []]);
        $this->assertSame([null, null], $this->fit('[ف: 10]', $m[1], $many));
    }

    public function test_of_several_citations_the_one_of_the_tied_volume_is_read(): void
    {
        [$source, $v] = $this->source([3 => [], 21 => []]);
        $this->assertSame([21, 1059], $this->fit('[اهدائی رهبر: 3-528 و ف: 21-1059]', $v[21], $source));
        // the first is the tied volume's: it stays
        $this->assertSame([3, 528], $this->fit('[اهدائی رهبر: 3-528 و ف: 21-1059]', $v[3], $source));
        // two pages of one citation are not two citations
        $this->assertSame([3, 88], $this->fit('[ف: 3 - 88 و 89]', $v[3], $source));
    }

    public function test_a_volume_and_page_written_the_other_way_round_are_swapped(): void
    {
        [$source, $v] = $this->source([7 => []]);
        $this->assertSame([7, 512], $this->fit('[نشریه: 512-7]', $v[7], $source));

        // «2-1» on volume 1 is volume 2, which the site may lack: left as printed
        [$one, $o] = $this->source([1 => []]);
        $this->assertSame([2, 1], $this->fit('[عکسی - ف: 2-1]', $o[1], $one));
    }

    public function test_a_citation_kept_with_the_end_of_the_line_before_it_is_read_from_its_last_bracket(): void
    {
        [$source, $v] = $this->source([8 => []]);
        $this->assertSame([8, 536], $this->fit('[(4پ-23پ)، 16 سطر (5×12)، اندازه: 10×17سم [ف: 8-536]', $v[8], $source));
        // a first citation that names the tied volume stays, whatever a second bracket holds
        [$other, $o] = $this->source([1 => [], 6 => []]);
        $this->assertSame([1, 413], $this->fit('[ف: 1-413 و [عکسی ف: 6-361]', $o[1], $other));
    }
}
