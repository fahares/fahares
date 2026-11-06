<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Two catalogue volumes had their copies credited to the wrong cataloger, while the volume already named the right
 * one (step 14.62). Both look like matches on a shared name:
 *
 *  - «فهرست نسخه‌های خطی کتابخانه دانشکده الهیات» (Tehran), vol. 1: Fankha's table of catalogues, row 156, names «سید
 *    محمدباقر حجتی», with «محمدتقی دانش‌پژوه» as reviewer (دانشگاه تهران 1345, 1227 pages). 2,828 of its copies and a
 *    cataloger link were given to «محمود فاضل», who catalogued the Elahiyat faculty library of Mashhad.
 *  - «فهرست نسخه‌های خطی کتابخانه سید احمد روضاتی» (Isfahan): row 30 names «رحیم قاسمی» (مجمع ذخائر اسلامی 1385, 151
 *    pages), whose pages its copies cite (pp. 13–95). 212 of its 228 copies were given to «سید محمدعلی روضاتی».
 *
 * The copies of each volume move from the wrong cataloger to the right one, and the wrong one's link to the volume is
 * removed; the volume's other links (such as a reviewer) stay. Sources are found by code and catalogers by MTIF id,
 * and the command refuses unless the right cataloger is already linked to the volume. Safe to run again.
 */
class FixCopyCatalogersCommand extends Command
{
    protected $signature = 'catalogs:fix-copy-catalogers {--dry-run : Report the changes without saving them}';

    protected $description = 'Give the copies of the Tehran Elahiyat and Sayyid Ahmad Rowzati catalogues their real cataloger';

    public const FAZEL = 37263;

    public const HOJJATI = 37282;

    public const ROWZATI = 37309;

    public const GHASEMI = 43413;

    /** [source code, volume number, wrong cataloger, right cataloger], catalogers by MTIF id */
    public const FIXES = [
        ['catalogue-7006195fe18b', 1, self::FAZEL, self::HOJJATI],
        ['catalogue-54ef008d33d5', 1, self::ROWZATI, self::GHASEMI],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $ids = collect(static::FIXES)->flatMap(fn ($f) => [$f[2], $f[3]])->unique()->values()->all();
        // toBase(): Eloquent's only() would filter by primary key, not by MTIF id
        $catalogers = Cataloger::whereIn('mtif_entry_id', $ids)->get()->keyBy('mtif_entry_id')->toBase();
        if ($missing = array_diff($ids, $catalogers->keys()->all())) {
            $this->error('Catalogers not found by MTIF id: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $plan = [];
        foreach (static::FIXES as [$code, $number, $fromId, $toId]) {
            $source = Source::where('code', $code)->first();
            $volume = $source ? CatalogVolume::whereIn('id', $source->volumes()->pluck('id'))->where('volume_number', $number)->first() : null;
            if (! $volume) {
                $this->error("Source {$code} or its volume {$number} not found");

                return self::FAILURE;
            }
            [$from, $to] = [$catalogers[$fromId], $catalogers[$toId]];
            if (! $volume->catalogers()->whereKey($to->id)->exists()) {
                $this->error("Volume {$volume->id} is not linked to «{$to->name}»");

                return self::FAILURE;
            }
            $copies = DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('cataloger_id', $from->id)->count();
            $linked = $volume->catalogers()->whereKey($from->id)->exists();
            $this->line("{$code} vol {$number} ({$volume->id}): {$copies} copies «{$from->name}» → «{$to->name}»"
                . ($linked ? ", link to «{$from->name}» removed" : '') . ', '
                . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->count() . ' copies in all');
            $plan[] = [$volume, $from, $to];
        }
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        $touched = collect($plan)->flatMap(fn ($p) => [$p[1]->id, $p[2]->id])->unique()->values();
        DB::transaction(function () use ($plan, $touched) {
            foreach ($plan as [$volume, $from, $to]) {
                DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('cataloger_id', $from->id)->update(['cataloger_id' => $to->id]);
                $volume->catalogers()->detach($from->id);
            }
            Cataloger::recount($touched);
        });

        foreach (Cataloger::whereIn('id', $touched)->get() as $c) {
            $this->line("«{$c->name}» ({$c->id}): {$c->volumes_count} volumes, {$c->manuscripts_count} copies, {$c->supervised_count} supervised");
        }
        $this->info('Done.');

        return self::SUCCESS;
    }
}
