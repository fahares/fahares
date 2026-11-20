<?php

namespace App\Console\Commands;

use App\Console\Commands\MatchCatalogEditionCommand as Format;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Sources\Extractors\Extractors;
use Illuminate\Console\Command;

/**
 * Shelfmark claims for the copies a library catalogue cites whose text sources:take-texts did not find (backlog item
 * 147, step 14.266): Fankha's number is a misprint of the one the catalogue prints at that place («3600» for ۳۶۰,
 * «8972/30» for ۸۹۷۷/۳۰). Each number one digit away from the copy's (a digit dropped, added, changed, or two swapped)
 * is looked for on the cited page and the next and previous ones, with the copy's treatise; the one found is claimed
 * when it is the only one, no other record of the library has it, and the work's title stands in its entry (certainty
 * 80), or it is the only one found at all (50). A claim goes to the review queue; accepting it corrects the record, and
 * sources:take-texts then takes the copy's text. With --correct, the claims of certainty 80 are accepted at once: the
 * record's shelfmark is corrected, with an «update» in the audit log that rolls it back. Candidate rule claims of a run
 * are made again on the next one.
 */
class ClaimShelfmarksCommand extends Command
{
    protected $signature = 'sources:shelfmark-claims
        {source : The source, by id or code}
        {--apply : Save the claims (without it, only count)}
        {--correct : With --apply, correct the copies claimed with the title seen at once (an accepted claim), and queue only the rest}
        {--report= : Write each copy, its numbers found and the decision to this TSV file}';

    protected $description = 'Claim the printed shelfmark for copies a catalogue cites under a misprinted number';

    public function handle(): int
    {
        $source = Source::where('id', $this->argument('source'))->orWhere('code', $this->argument('source'))->first();
        if (! $source || ! ($extractor = Extractors::for($source->extractor))) {
            $this->error($source ? "{$source->title} has no extractor" : "No source {$this->argument('source')}");

            return self::FAILURE;
        }
        $single = $source->volumes()->count() === 1;
        $stats = ['without text' => 0, 'claimed' => 0, 'claimed, title not seen' => 0, 'another record has it' => 0, 'more than one' => 0, 'none found' => 0, 'corrected' => 0];
        $report = $this->option('report') ? fopen($this->option('report'), 'w') : null;
        $report && fputcsv($report, ['entry_id', 'manuscript_id', 'shelfmark', 'page', 'title', 'decision', 'found'], "\t");

        $ids = SourceEntry::where('source_id', $source->id)->where('citable_type', Manuscript::class)->where('text_status', SourceEntry::TEXT_NONE)->orderBy('id')->pluck('id');
        foreach ($ids->chunk(500) as $batch) {
            foreach (SourceEntry::with(['sourceVolume', 'citable.work'])->whereIn('id', $batch->all())->get() as $entry) {
                $page = $entry->page_start ?? ($single && preg_match('/^\s*\[\s*ف\s*:\s*(\d+)\s*\]\s*$/u', (string) $entry->citation_text, $c) ? (int) $c[1] : null);
                $copy = $entry->citable;
                if (! $page || ! $copy || ! $entry->source_volume_id || ! preg_match('/^\D*(\d+)(?:\s*\/\s*(\d+))?/', Format::digits((string) $copy->shelfmark), $m)) {
                    continue;
                }
                $pages = Extractors::pages($entry->source_volume_id);
                if (! $pages) {
                    continue;
                }
                $stats['without text']++;
                $part = isset($m[2]) ? '/' . $m[2] : '';
                $title = self::words((string) ($copy->work->primary_title ?? ''));
                $found = [];
                foreach (self::neighbours($m[1]) as $number) {
                    foreach ([$page, $page - 1, $page + 1] as $p) {
                        if ($text = $extractor->extract($pages, $number . $part, $p, ['strict_part' => true])) {
                            $words = self::words($text['entry_text'] . ' ' . $text['copy_text']);
                            $found[$number] = [
                                'title' => $title ? count(array_intersect($title, $words)) / count($title) : 0.0,
                                'other' => Manuscript::where('library_id', $copy->library_id)->where('shelfmark', $number . $part)->where('id', '!=', $copy->id)->value('id'),
                                'line' => trim((string) strtok(trim((string) ($text['copy_text'] ?: $text['entry_text'])), "\n")),
                            ];

                            break;
                        }
                    }
                }
                uasort($found, fn ($a, $b) => $b['title'] <=> $a['title']);
                $best = array_key_first($found);
                $second = count($found) > 1 ? array_values($found)[1]['title'] : -1;
                $decision = match (true) {
                    ! $found => 'none found',
                    (bool) $found[$best]['other'] => 'another record has it',
                    $found[$best]['title'] >= 0.5 && $found[$best]['title'] > $second => 'claimed',
                    count($found) === 1 => 'claimed, title not seen',
                    default => 'more than one',
                };
                $stats[$decision]++;
                $report && fputcsv($report, [$entry->id, $copy->id, $copy->shelfmark, $page, $copy->work->primary_title ?? '', $decision, json_encode($found, JSON_UNESCAPED_UNICODE)], "\t");
                if (! $this->option('apply') || ! str_starts_with($decision, 'claimed')) {
                    continue;
                }
                // the copy's own form, with the printed number in place of Fankha's
                $value = preg_replace('/' . $m[1] . '/', (string) $best, Format::digits((string) $copy->shelfmark), 1);
                Claim::where('source_entry_id', $entry->id)->where('field', 'shelfmark')->where('method', Claim::METHOD_RULE)->candidate()->delete();
                $correct = $this->option('correct') && $decision === 'claimed';
                Claim::create([
                    'subject_type' => Manuscript::class, 'subject_id' => $copy->id, 'field' => 'shelfmark',
                    'value_text' => $value, 'current_text' => $copy->shelfmark, 'raw_text' => $found[$best]['line'],
                    'source_entry_id' => $entry->id, 'method' => Claim::METHOD_RULE,
                    'certainty' => $decision === 'claimed' ? 80 : 50,
                ] + ($correct ? ['status' => Claim::STATUS_ACCEPTED, 'reviewed_at' => now()] : []));
                if ($correct) {
                    $old = $copy->shelfmark;
                    $copy->shelfmark = $value;
                    $copy->save();
                    AuditLog::create([
                        'user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copy->id, 'action' => 'update',
                        'old_values' => ['shelfmark' => $old],
                        'new_values' => ['shelfmark' => $value, 'command' => 'sources:shelfmark-claims', 'source_entry_id' => $entry->id],
                    ]);
                    $stats['corrected']++;
                }
            }
        }
        Extractors::forget();
        $report && fclose($report);

        $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
        $this->option('apply') || $this->warn('Dry run: pass --apply to save the claims.');

        return self::SUCCESS;
    }

    /** The numbers one digit away: a digit dropped, added, changed, or two neighbours swapped. */
    public static function neighbours(string $number): array
    {
        $out = [];
        $n = strlen($number);
        for ($i = 0; $i < $n; $i++) {
            $out[] = substr($number, 0, $i) . substr($number, $i + 1);
            for ($d = 0; $d <= 9; $d++) {
                $out[] = substr($number, 0, $i) . $d . substr($number, $i + 1);
            }
            if ($i < $n - 1) {
                $out[] = substr($number, 0, $i) . $number[$i + 1] . $number[$i] . substr($number, $i + 2);
            }
        }
        for ($i = 0; $i <= $n; $i++) {
            for ($d = 0; $d <= 9; $d++) {
                $out[] = substr($number, 0, $i) . $d . substr($number, $i);
            }
        }

        return array_values(array_filter(array_unique($out), fn ($x) => $x !== '' && $x !== $number && $x[0] !== '0'));
    }

    /** A title's words for comparing: three letters or more, the article and the letter forms set aside. */
    public static function words(string $text): array
    {
        $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ۀ' => 'ه', "\u{200C}" => ' ']);
        $text = (string) preg_replace(['/[\x{064B}-\x{065F}\x{0670}]/u', '/(^|\s)ال/u'], ['', '$1'], $text);
        preg_match_all('/\p{Arabic}{3,}/u', $text, $m);

        return array_values(array_unique($m[0]));
    }
}
