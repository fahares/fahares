<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Support\CopyDateRange;
use App\Support\HijriYear;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What checking backlog item 52 against the library catalogues' printed pages found besides the scripts (step 14.444),
 * from database/data/step14_444_copy_side_fixes.json:
 *  - «dates»: copy dates the printed page gives otherwise (three copies typed in 1313 and 1314 Shamsi, not «1314ق»;
 *    Marashi 14269/1 copied in 1263, not 1363);
 *  - «scribe_names»: a scribe name that is no name («حروچینی», the catalogue's «حروفچینی» in the script's place);
 *  - «photo_citations»: Fankha citations «[عکسی …: ف: N-P]» of a library's copies that point to its main catalogue.
 *    They go to the same volume of its catalogue of photographic copies, with the cataloguer that volume's other
 *    entries have.
 * A copy is changed only while its field still holds «from», and a citation only while it points to the main
 * catalogue, so a second run changes nothing. Every write is in the audit log.
 */
class FixCopySideFindingsCommand extends Command
{
    protected $signature = 'fahares:fix-copy-side-findings
        {--file=database/data/step14_444_copy_side_fixes.json : The decision file}
        {--apply : Write; without it the command only reports}';

    protected $description = 'Step 14.444: copy dates, a scribe name and photographic-copy citations the printed catalogues correct';

    private const FIX = 'fix-copy-side-findings (step 14.444)';

    private array $counts = ['dates' => 0, 'scribe_names' => 0, 'citations' => 0, 'already' => 0, 'missing' => 0];

    public function handle(): int
    {
        // a fresh count each run: the container hands out the same command object to each call in one process
        $this->counts = array_map(fn () => 0, $this->counts);
        $apply = (bool) $this->option('apply');
        $data = json_decode((string) file_get_contents(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR);
        $touched = [];

        foreach ($data['dates'] as $row) {
            if (! ($m = $this->copy($row)) || $this->already($m->copy_date_raw, $row)) {
                continue;
            }
            $this->counts['dates']++;
            $range = CopyDateRange::parse($row['copy_date_raw']);
            $new = ['copy_date_raw' => $row['copy_date_raw'], 'copy_date_hijri_year' => HijriYear::parse($row['copy_date_raw']), 'copy_date_from' => $range['from'],
                'copy_date_to' => $range['to'], 'copy_date_kind' => $range['kind'], 'copy_date_certainty' => $range['certainty'], 'copy_date_hedge_raw' => $range['hedge']];
            $this->line("  #{$m->id} date «{$m->copy_date_raw}» → «{$row['copy_date_raw']}» ({$range['from']}–{$range['to']})");
            if ($apply) {
                $this->write($m, $new, $row['page']);
                $touched[] = $m->id;
            }
        }

        foreach ($data['scribe_names'] as $row) {
            if (! ($m = $this->copy($row)) || $this->already($m->scribe_name, $row)) {
                continue;
            }
            $this->counts['scribe_names']++;
            $this->line("  #{$m->id} scribe «{$m->scribe_name}» → —");
            if ($apply) {
                DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('role', 'scribe')->whereNull('person_id')->where('name', $row['from'])->delete();
                $this->write($m, ['scribe_name' => null], $row['page']);
                $touched[] = $m->id;
            }
        }

        foreach ($data['photo_citations'] as $rule) {
            $this->photoCitations($rule, $apply, $touched);
        }

        if ($touched) {
            Manuscript::whereIn('id', array_unique($touched))->get()->searchable();
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($this->counts as $k => $n) {
            $this->line(sprintf('  %-14s %4d', $k, $n));
        }

        return self::SUCCESS;
    }

    private function copy(array $row): ?Manuscript
    {
        $m = Manuscript::where('city', $row['city'])->where('library', $row['library'])->where('shelfmark', $row['shelfmark'])
            ->whereHas('work', fn ($q) => $q->where('slug', $row['work']))->first();
        if (! $m) {
            $this->counts['missing']++;
            $this->warn("  not found: {$row['city']} / {$row['library']} / {$row['shelfmark']} ({$row['work']})");
        }

        return $m;
    }

    private function already(?string $value, array $row): bool
    {
        if ($value === $row['from']) {
            return false;
        }
        $this->counts['already']++;

        return true;
    }

    private function write(Manuscript $m, array $new, string $page): void
    {
        DB::transaction(function () use ($m, $new, $page) {
            DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => array_intersect_key($m->getAttributes(), $new), 'new_values' => $new + ['page' => $page, 'corpus_fix' => self::FIX]]);
        });
    }

    /** «[عکسی: ف: 5-16]» of a copy of the library, on its main catalogue: to the same volume of the photographic one. */
    private function photoCitations(array $rule, bool $apply, array &$touched): void
    {
        $from = DB::table('sources')->where('code', $rule['from'])->value('id');
        $to = DB::table('sources')->where('code', $rule['to'])->value('id');
        $volumes = DB::table('source_volumes')->where('source_id', $to)->pluck('id', 'volume_number');
        // each volume's cataloguer, as its other Fankha citations have it
        $catalogers = DB::table('source_entries')->where('source_id', $to)->where('legacy_type', 'fankha_citation')->whereNotNull('cataloger_id')
            ->select('source_volume_id', 'cataloger_id', DB::raw('count(*) as n'))->groupBy('source_volume_id', 'cataloger_id')->orderByDesc('n')->get()
            ->unique('source_volume_id')->pluck('cataloger_id', 'source_volume_id');

        $entries = DB::table('source_entries as se')->join('manuscripts as m', 'm.id', '=', 'se.citable_id')
            ->where('se.citable_type', (new Manuscript)->getMorphClass())->where('se.legacy_type', 'fankha_citation')
            ->where('se.source_id', $from)->where('m.library', $rule['library'])
            ->whereRaw("se.citation_text regexp '^\\\\[[[:space:]]*عکسی'")
            ->orderBy('se.id')->get(['se.id', 'se.citable_id', 'se.volume_number', 'se.source_volume_id', 'se.cataloger_id', 'se.citation_text']);
        foreach ($entries as $e) {
            $volume = $volumes[$e->volume_number] ?? null;
            if (! $volume) {
                $this->warn("  entry #{$e->id} {$e->citation_text}: the photographic catalogue has no volume {$e->volume_number}");
                continue;
            }
            $this->counts['citations']++;
            $new = ['source_id' => $to, 'source_volume_id' => $volume, 'cataloger_id' => $catalogers[$volume] ?? null];
            if ($this->counts['citations'] <= 5) {
                $this->line("  copy #{$e->citable_id} {$e->citation_text} → {$rule['to']} vol {$e->volume_number}");
            }
            if ($apply) {
                DB::transaction(function () use ($e, $new, $from) {
                    DB::table('source_entries')->where('id', $e->id)->update($new + ['updated_at' => now()]);
                    AuditLog::create(['user_id' => null, 'auditable_type' => SourceEntry::class, 'auditable_id' => $e->id, 'action' => 'corpus_update',
                        'old_values' => ['source_id' => $from, 'source_volume_id' => $e->source_volume_id, 'cataloger_id' => $e->cataloger_id],
                        'new_values' => $new + ['corpus_fix' => self::FIX]]);
                });
                $touched[] = $e->citable_id;
            }
        }
    }
}
