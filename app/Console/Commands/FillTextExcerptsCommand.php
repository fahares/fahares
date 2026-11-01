<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\TextExcerpt;
use App\Models\Work;
use App\Support\FankhaWorkLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Fills text_excerpts (backlog item 98) from a decision file of the corpus script fankha_v2_excerpts_export.py: a
 * work's numbered incipits and explicits («آغاز 1: …»، «آغاز 2: …») and those of its parts («جلد دوم»), found on the
 * site by title and page (FankhaWorkLocator). Then each copy of those works whose incipit or explicit on the site is a
 * pointer («برابر 2», «برابر جلد دوم», «برابر دیباچه») gets a row pointing at the excerpt it names; the copy's own
 * text is not touched. Rows are keyed by work, kind, number and label, so a run can be repeated.
 */
class FillTextExcerptsCommand extends Command
{
    protected $signature = 'fahares:fill-text-excerpts
        {--file= : Decision file (database/data/fankha_v2_excerpts_20261031.json)}
        {--apply : Write; without it the command only counts}
        {--show=12 : How many examples to list per kind}';

    protected $description = 'Fill works\' numbered and part incipits and explicits, and link copies\' «برابر …» to them';

    private const ORDINALS = ['اول' => 1, 'نخست' => 1, 'یکم' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6, 'هفتم' => 7, 'هشتم' => 8, 'نهم' => 9, 'دهم' => 10];

    private const LISTS = ['incipit_numbered' => ['incipit', false], 'explicit_numbered' => ['explicit', false], 'incipit_parts' => ['incipit', true], 'explicit_parts' => ['explicit', true]];

    private array $counts = [];

    private array $examples = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_v2_excerpts_20261031.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['works'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->counts = [];
        $this->examples = [];
        $locator = new FankhaWorkLocator(array_merge($plan['works'], $plan['peers'] ?? []));

        foreach (array_chunk($plan['works'], 50) as $chunk) {
            $write = function () use ($chunk, $locator, $apply) {
                foreach ($chunk as $w) {
                    $this->work($w, $locator, $apply);
                }
            };
            $apply ? DB::transaction($write) : $write();
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-34s %6d', $kind, $n));
        }
        foreach ($this->examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }

    private function work(array $w, FankhaWorkLocator $locator, bool $apply): void
    {
        $label = "v{$w['key']['vol']}-{$w['key']['ordinal']} «{$w['title']}»";
        $id = $locator->find((int) $w['key']['vol'], (int) $w['key']['ordinal'], (string) $w['title'], (int) ($w['provenance']['page_start'] ?? 0));
        if (! $id) {
            $this->count('works:not_found', $label);

            return;
        }
        $this->count('works:found');
        $work = Work::find($id);
        // The work's excerpts, by kind and by number or part label
        $excerpts = ['incipit' => ['n' => [], 'label' => []], 'explicit' => ['n' => [], 'label' => []]];
        foreach (self::LISTS as $list => [$kind, $isPart]) {
            foreach ($w[$list] ?? [] as $i => $item) {
                $n = $isPart ? null : (isset($item['n']) ? (int) $item['n'] : null);
                $partLabel = $item['label'] ?? null;
                $values = ['text' => $item['text'] ?? null, 'position' => $isPart ? $i + 1 : (int) ($n ?? $i + 1)];
                $this->count("excerpts:{$kind}" . ($isPart ? '_part' : '_numbered'));
                $row = $apply
                    ? TextExcerpt::updateOrCreate(['owner_type' => Work::class, 'owner_id' => $id, 'kind' => $kind, 'is_part' => $isPart, 'n' => $n, 'label' => $partLabel], $values)
                    : null;
                if ($n !== null) {
                    $excerpts[$kind]['n'][$n] = $row;
                }
                if ($partLabel) {
                    $excerpts[$kind]['label'][self::labelKey($partLabel)] = $row;
                }
            }
        }

        // The copies of the work that point at one of them
        foreach (Manuscript::where('work_id', $id)->get(['id', 'incipit_text', 'explicit_text']) as $copy) {
            foreach (self::linkCopy($copy, $excerpts, $apply) as $kind => [$outcome, $text]) {
                $this->count("refs:{$kind}_{$outcome}", $outcome === 'unmatched' ? "#{$copy->id} {$label}: {$text}" : null);
            }
        }
    }

    /**
     * A work's excerpts on the site by kind, keyed by number and by part label (labelKey), as linkCopy takes them.
     *
     * @return array<string, array{n: array<int, TextExcerpt>, label: array<string, TextExcerpt>}>
     */
    public static function excerptsOf(int $workId): array
    {
        $excerpts = ['incipit' => ['n' => [], 'label' => []], 'explicit' => ['n' => [], 'label' => []]];
        foreach (TextExcerpt::where('owner_type', Work::class)->where('owner_id', $workId)->orderBy('id')->get() as $row) {
            if ($row->n !== null && ! $row->is_part) {
                $excerpts[$row->kind]['n'][$row->n] = $row;
            }
            if ($row->label) {
                $excerpts[$row->kind]['label'][self::labelKey($row->label)] = $row;
            }
        }

        return $excerpts;
    }

    /**
     * The excerpt a copy's incipit or explicit points at («برابر 2», «برابر جلد دوم»): null when the text is no
     * pointer, else whether the work has the excerpt it names and that excerpt (null in a dry run of the filler).
     *
     * @return ?array{found: bool, target: ?TextExcerpt}
     */
    public static function pointer(?string $text, array $excerpts): ?array
    {
        if (! preg_match('/^برابر\s*(.+)$/u', trim((string) $text), $m)) {
            return null;
        }
        $ref = trim(strtr($m[1], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        // «برابر3 خط: نستعلیق…»: the old parser glued the physical line on; the number still names the excerpt
        if (preg_match('/^(\d+)(?!\d)(?![\s\x{200c}]*[و،-]\s*\d)/u', $ref, $num)) {
            return ['found' => array_key_exists((int) $num[1], $excerpts['n']), 'target' => $excerpts['n'][(int) $num[1]] ?? null];
        }
        $key = self::labelKey($ref);

        return ['found' => array_key_exists($key, $excerpts['label']), 'target' => $excerpts['label'][$key] ?? null];
    }

    /**
     * Gives a copy a pointer row for each of its incipit and explicit that names one of the work's excerpts; its own
     * text is not touched. With $prune (a copy the corpus commands rebuilt) a row whose text no longer names one is
     * removed. The one write path of the filler, the corpus apply commands and fahares:refresh-derived.
     *
     * @return array<string, array{0: string, 1: string}> kind => what happened (linked, unmatched, removed) and the text
     */
    public static function linkCopy(object $copy, array $excerpts, bool $apply, bool $prune = false): array
    {
        $out = [];
        foreach (['incipit' => $copy->incipit_text, 'explicit' => $copy->explicit_text] as $kind => $text) {
            $text = trim((string) $text);
            $pointer = self::pointer($text, $excerpts[$kind]);
            if ($pointer && $pointer['found']) {
                $out[$kind] = ['linked', $text];
                if ($apply) {
                    TextExcerpt::updateOrCreate(['owner_type' => Manuscript::class, 'owner_id' => $copy->id, 'kind' => $kind],
                        ['same_as_excerpt_id' => $pointer['target']?->id, 'same_as_raw' => mb_substr($text, 0, 120)]);
                }
                continue;
            }
            if ($pointer) {
                $out[$kind] = ['unmatched', $text];
            }
            if ($prune && $apply && TextExcerpt::where('owner_type', Manuscript::class)->where('owner_id', $copy->id)->where('kind', $kind)->delete()) {
                $out[$kind] = ['removed', $text];
            }
        }

        return $out;
    }

    /** «جلد دوم», «جلد 2», «ج 2» and «جلد۲» alike; «دیباچه» as it is. */
    public static function labelKey(string $label): string
    {
        $label = strtr(trim($label), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', 'ي' => 'ی', 'ك' => 'ک']);
        $label = preg_replace_callback('/(?<!\p{L})(' . implode('|', array_keys(self::ORDINALS)) . ')(?!\p{L})/u', fn ($m) => (string) self::ORDINALS[$m[1]], $label);
        $label = preg_replace('/^ج(?=\s*\d)/u', 'جلد', $label);

        return preg_replace('/[\s\x{200c}.:،]+/u', '', $label);
    }

    private function count(string $kind, ?string $example = null): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
        if ($example !== null && count($this->examples[$kind] ?? []) < (int) $this->option('show')) {
            $this->examples[$kind][] = $example;
        }
    }
}
