<?php

namespace App\Jobs;

use App\Http\Controllers\SearchController;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\Work;
use App\Notifications\Notice;
use App\Support\Fa;
use App\Support\SearchExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A search export larger than a request can build (backlog item 83, phase 4): the ids of up to QUEUED_LIMIT results
 * in their order, then the records 500 at a time, written row by row to a file on the uploads volume; the user is
 * sent its signed link, good for KEEP_DAYS days.
 */
class ExportSearchResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Seconds; 50 000 manuscripts take a few minutes */
    public int $timeout = 900;

    public int $tries = 1;

    private const MODELS = ['works' => Work::class, 'manuscripts' => Manuscript::class, 'people' => Person::class, 'sources' => SourceItem::class];

    public function __construct(public int $userId, public array $params, public string $format) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        ini_set('memory_limit', '256M');

        $request = Request::create('/search', 'GET', $this->params);
        $request->setUserResolver(fn () => $user);
        ['type' => $type, 'query' => $query, 'ids' => $ids] = app(SearchController::class)->exportIds($request, SearchExport::QUEUED_LIMIT);

        // An unguessable name: the link is the only way to the file
        $file = bin2hex(random_bytes(16)) . '.' . $this->format;
        Storage::disk('public')->makeDirectory(SearchExport::DIRECTORY);
        SearchExport::write($this->records($type, $ids), $type, $this->format, Storage::disk('public')->path(SearchExport::DIRECTORY . '/' . $file));

        $link = URL::temporarySignedRoute('search.export.file', now()->addDays(SearchExport::KEEP_DAYS), ['file' => $file]);
        $user->notify(new Notice('search.export_ready', 'خروجی جستجو آماده است',
            [($query !== '' ? "خروجی جستجوی «{$query}»" : 'خروجی فهرست') . ' با ' . Fa::num(count($ids)) . ' ردیف ساخته شد.',
                'این پیوند تا ' . Fa::num(SearchExport::KEEP_DAYS) . ' روز کار می‌کند.'],
            $link, 'دانلود فایل'));
    }

    /** The records in the order of the ids, read 500 at a time so a large file does not hold them all */
    private function records(string $type, array $ids): \Generator
    {
        $model = self::MODELS[$type];
        foreach (array_chunk($ids, 500) as $chunk) {
            $query = $model::query()->whereKey($chunk);
            $type === 'people' ? $query->withCount(['works', 'scribedManuscripts']) : $query->with(SearchController::WITH[$type]);
            $records = $query->get()->keyBy('id');
            foreach ($chunk as $id) {
                if ($record = $records->get($id)) {
                    yield $record;
                }
            }
        }
    }
}
