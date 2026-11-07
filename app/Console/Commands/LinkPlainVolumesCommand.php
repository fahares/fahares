<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links the treatises of a bound volume whose shelfmarks have no part number (step 14.79). fahares:fill-collection-keys
 * keys «4824/5» by its volume 4824, but a plain «4824» names no volume by itself. Two kinds are linked here, where their
 * leaf ranges show them bound together:
 *
 *  - one number shared (the National Library prints «4824» for each of the thirty treatises of its volume 4824): the
 *    copies of a library with one plain shelfmark (Manuscript::plainVolumeKeyOf), and any copy keyed «4824» from a
 *    shelfmark «4824/n», are one volume when their leaf ranges do not overlap, two ranges or one range past the first
 *    leaves show a bound volume, and no work comes twice unless every copy of it has its own range. The key is the
 *    shared shelfmark.
 *  - a run of numbers (Razavi numbers each treatise of a volume: 38131 at 19ر–22پ, 38134 at 94ر–115ر, 38137 at
 *    173ر–244ر): copies with a plain number of their own, within five numbers of each other, of one size («اندازه»),
 *    whose leaf ranges go on in the order of their numbers, each next one the nearest after the last. A copy of that
 *    size without a range whose number falls inside the run joins it; a ranged one left out (two volumes' numbers
 *    interleaved) leaves the run unlinked. The key is the run's first and last number («38131–38137»).
 *
 * Without --apply it only counts. A key a reviewed correction set is kept. Each run recomputes every such key, so a
 * copy whose volume no longer passes loses its key. Nothing searchable changes, so nothing is reindexed. Safe to run again.
 */
class LinkPlainVolumesCommand extends Command
{
    protected $signature = 'fahares:link-plain-volumes
        {--apply : Write; without it the command only counts}
        {--show=12 : How many examples to list per kind}';

    protected $description = 'Link the treatises of a bound volume whose shelfmarks have no part number';

    /** The most numbers apart two treatises of one run may be. */
    private const RUN_GAP = 5;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, string[]> */
    private array $examples = [];

    public function handle(): int
    {
        // every copy with a plain shelfmark, and the ranges of the copies already keyed by a «n/m» shelfmark
        $plain = [];
        $keyed = [];
        DB::table('manuscripts')->select(['id', 'library_id', 'library', 'shelfmark', 'raw_text', 'collection_key', 'work_id', 'range_from_number', 'range_to_number'])
            ->orderBy('id')->chunkById(5000, function ($rows) use (&$plain, &$keyed) {
                foreach ($rows as $row) {
                    $lib = $row->library_id ?? (trim((string) $row->library) !== '' ? 'n:' . trim($row->library) : null);
                    if ($lib === null) {
                        continue;
                    }
                    $range = $row->range_from_number !== null ? [(int) $row->range_from_number, (int) ($row->range_to_number ?? $row->range_from_number)] : null;
                    $derived = Manuscript::collectionKeyOf($row->shelfmark, $row->raw_text);
                    if ($derived !== null) {
                        $keyed[$lib][$derived][] = ['id' => $row->id, 'work' => $row->work_id, 'range' => $range];

                        continue;
                    }
                    $key = Manuscript::plainVolumeKeyOf($row->shelfmark, $row->raw_text);
                    if ($key === null && $row->collection_key === null) {
                        continue;
                    }
                    $plain[$row->id] = ['id' => $row->id, 'lib' => $lib, 'key' => $key, 'work' => $row->work_id, 'range' => $range,
                        'number' => Manuscript::citesOnlySenate($row->raw_text) ? null : Manuscript::plainNumber($row->shelfmark),
                        'size' => self::size($row->raw_text), 'shelfmark' => $row->shelfmark, 'stored' => $row->collection_key];
                }
            });

        $desired = array_fill_keys(array_keys($plain), null);
        $groups = [];
        foreach ($plain as $copy) {
            if ($copy['key'] !== null) {
                $groups[$copy['lib']][$copy['key']][] = $copy['id'];
            }
        }

        // one number shared
        $alone = [];
        foreach ($groups as $lib => $byKey) {
            foreach ($byKey as $key => $ids) {
                // PHP makes a numeric array key («6982») an int; the stored key is a string
                $key = (string) $key;
                $members = array_map(fn ($id) => $plain[$id], $ids);
                $others = $keyed[$lib][$key] ?? [];
                if (count($members) + count($others) < 2) {
                    $alone[$lib][] = $ids[0];

                    continue;
                }
                $verdict = self::sharedVolume(array_merge($members, $others));
                $this->count("shared:{$verdict}", count($members));
                if ($verdict === 'linked') {
                    $this->counts['shared:volumes'] = ($this->counts['shared:volumes'] ?? 0) + 1;
                    foreach ($ids as $id) {
                        $desired[$id] = $key;
                    }
                    $this->example('shared:linked', "«{$key}» (" . count($members) . ' + ' . count($others) . '): #' . implode(' #', array_slice($ids, 0, 6)));
                } else {
                    $this->example("shared:{$verdict}", "«{$key}»: #" . implode(' #', array_slice($ids, 0, 6)));
                }
            }
        }

        // a run of numbers, among the copies whose plain number is theirs alone in the library
        foreach ($alone as $lib => $ids) {
            $numbered = array_values(array_filter(array_map(fn ($id) => $plain[$id], $ids), fn ($c) => $c['number'] !== null && $c['size'] !== null));
            usort($numbered, fn ($a, $b) => $a['number'] <=> $b['number']);
            foreach (self::runs($numbered) as $run) {
                $key = $run['first'] . '–' . $run['last'];
                if (isset($run['unsure'])) {
                    $this->count('run:unsure', count($run['unsure']));
                    $this->example('run:unsure', "«{$key}»: " . implode(' ', array_map(fn ($id) => "{$plain[$id]['shelfmark']}({$plain[$id]['range'][0]}–{$plain[$id]['range'][1]})#{$id}", $run['unsure'])));

                    continue;
                }
                foreach ($run['ids'] as $id) {
                    $desired[$id] = $key;
                }
                $this->count('run:linked', count($run['ids']));
                $this->counts['run:volumes'] = ($this->counts['run:volumes'] ?? 0) + 1;
                $this->example('run:linked', "«{$key}»: " . implode(' ', array_map(fn ($id) => "{$plain[$id]['shelfmark']}" . ($plain[$id]['range'] ? "({$plain[$id]['range'][0]}–{$plain[$id]['range'][1]})" : '') . "#{$id}", $run['ids'])));
            }
        }

        // write what differs
        $guard = ProtectedColumns::for(Manuscript::class);
        $writes = [];
        foreach ($desired as $id => $key) {
            $stored = $plain[$id]['stored'];
            if ($key === $stored) {
                continue;
            }
            if ($guard->strip($id, ['collection_key' => 1]) === []) {
                $this->count('kept:protected');

                continue;
            }
            $this->count($key === null ? 'cleared' : ($stored === null ? 'set' : 'moved'));
            $writes[$id] = $key;
        }
        if ($this->option('apply')) {
            foreach (array_chunk($writes, 1000, true) as $chunk) {
                DB::transaction(function () use ($chunk) {
                    foreach ($chunk as $id => $key) {
                        DB::table('manuscripts')->where('id', $id)->update(['collection_key' => $key]);
                    }
                });
            }
        }

        $this->info($this->option('apply') ? 'Written.' : 'Dry run: nothing written.');
        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-28s %8d', $kind, $n));
        }
        foreach ($this->examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Whether copies sharing a plain number are one bound volume: 'linked', or why not ('overlap': two ranges share
     * leaves, so the copies are two volumes or one copy described twice; 'repeated-work': a work comes twice without
     * its own ranges; 'no-evidence': nothing shows a bound volume).
     */
    private static function sharedVolume(array $copies): string
    {
        $ranges = array_values(array_filter(array_map(fn ($c) => $c['range'], $copies)));
        usort($ranges, fn ($a, $b) => $a <=> $b);
        for ($i = 1; $i < count($ranges); $i++) {
            // «1پ–27» and «27پ–93» share leaf 27, a leaf each half of
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                return 'overlap';
            }
        }
        $byWork = [];
        foreach ($copies as $c) {
            $byWork[$c['work']][] = $c['range'] !== null;
        }
        foreach ($byWork as $ranged) {
            if (count($ranged) > 1 && in_array(false, $ranged, true)) {
                return 'repeated-work';
            }
        }
        if (count($ranges) >= 2 || ($ranges && $ranges[0][0] > 2)) {
            return 'linked';
        }

        return 'no-evidence';
    }

    /**
     * The runs of separately numbered treatises in a library's copies (sorted by number): a ranged copy links to the
     * next ranged copy of its size within RUN_GAP numbers when that one's leaves start where its own end or later, and
     * a run of two or more takes in the copies of its size with no range whose numbers fall inside it. A run that
     * leaves out a ranged copy of its size inside it comes back 'unsure', with no ids.
     *
     * @return list<array{first: int, last: int, ids: int[], unsure?: int[]}>
     */
    private static function runs(array $copies): array
    {
        $runs = [];
        $used = [];
        foreach ($copies as $i => $start) {
            if (isset($used[$i]) || $start['range'] === null) {
                continue;
            }
            // each next treatise is the one of the window whose leaves start nearest after the last one's end
            $chain = [$i];
            $at = $i;
            while (true) {
                $best = null;
                for ($j = $at + 1; $j < count($copies) && $copies[$j]['number'] - $copies[$at]['number'] <= self::RUN_GAP; $j++) {
                    $next = $copies[$j];
                    if ($next['range'] !== null && $next['size'] === $start['size'] && ! isset($used[$j]) && $next['range'][0] >= $copies[$at]['range'][1]
                        && ($best === null || $next['range'][0] < $copies[$best]['range'][0])) {
                        $best = $j;
                    }
                }
                if ($best === null) {
                    break;
                }
                $chain[] = $at = $best;
            }
            if (count($chain) < 2) {
                continue;
            }
            // a ranged copy of that size left out inside the run, or one near it whose leaves start among the run's
            // (two volumes' numbers interleaved), makes it unsure; the next volume, starting where the run starts, does not
            $leaves = [$copies[$i]['range'][0], $copies[end($chain)]['range'][1]];
            $left = [];
            foreach ($copies as $k => $c) {
                if (in_array($k, $chain, true) || $c['range'] === null || $c['size'] !== $start['size']
                    || $c['number'] < $copies[$i]['number'] - self::RUN_GAP || $c['number'] > $copies[end($chain)]['number'] + self::RUN_GAP) {
                    continue;
                }
                $inside = $c['number'] > $copies[$i]['number'] && $c['number'] < $copies[end($chain)]['number'];
                if ($inside || ($c['range'][0] > $leaves[0] && $c['range'][0] < $leaves[1])) {
                    $left[] = $k;
                }
            }
            if ($left) {
                $runs[] = ['first' => $copies[$i]['number'], 'last' => $copies[end($chain)]['number'], 'ids' => [], 'unsure' => array_map(fn ($k) => $copies[$k]['id'], array_merge($chain, $left))];
                foreach (array_merge($chain, $left) as $k) {
                    $used[$k] = true;
                }

                continue;
            }
            $first = $copies[$i]['number'];
            $end = $copies[end($chain)]['number'];
            $ids = [];
            for ($k = $i; $k <= end($chain); $k++) {
                $c = $copies[$k];
                if ($c['size'] === $start['size'] && (in_array($k, $chain, true) || $c['range'] === null) && ! isset($used[$k])) {
                    $ids[] = $c['id'];
                    $used[$k] = true;
                }
            }
            $runs[] = ['first' => $first, 'last' => $end, 'ids' => $ids];
        }

        return $runs;
    }

    /** The page size the catalogue gives («اندازه: 10×21سم»), as «10×21», or null. */
    private static function size(?string $rawText): ?string
    {
        $text = strtr((string) $rawText, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', 'x' => '×', '*' => '×']);

        return preg_match('/اندازه\s*:\s*([\d\/\.]+)\s*×\s*([\d\/\.]+)/u', $text, $m) ? "{$m[1]}×{$m[2]}" : null;
    }

    private function count(string $kind, int $n = 1): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + $n;
    }

    private function example(string $kind, string $line): void
    {
        if (count($this->examples[$kind] ?? []) < (int) $this->option('show')) {
            $this->examples[$kind][] = $line;
        }
    }
}
