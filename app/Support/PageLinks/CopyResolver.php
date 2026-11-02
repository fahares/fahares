<?php

namespace App\Support\PageLinks;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\SourceItem;
use App\Support\DirectAnswer;

/**
 * The copy a library's shelfmark in a page's text names (step 13.155), found as the site's search finds a shelfmark
 * (DirectAnswer::byShelfmark). A source's numbers are often old ones or a section's («الرضوية: 468 أدبيات», the old
 * Razavi catalogue's by subject), and half the copies of a number are of another work than the entry's. So a copy is
 * linked only when its work is the entry's (Titles::agree): the one copy of the number («exact»), or the one of the
 * volume's parts or of the copies of that number that is («part»).
 */
class CopyResolver
{
    /** @return array{0: ?Manuscript, 1: string}  the copy and how, or null and why not */
    public static function resolve(int $libraryId, array $shelfmark, ?SourceItem $entry): array
    {
        $suffix = TextNormalizer::normalize($shelfmark['suffix']);
        $found = DirectAnswer::byShelfmark($libraryId, $shelfmark['number'], $suffix);
        if ($found->isEmpty()) {
            return [null, 'none'];
        }
        if (! $entry) {
            return [null, 'no-entry'];
        }
        $agrees = fn (Manuscript $m) => collect($m->work ? array_merge(...$m->work->searchTitles()) : [])->contains(fn ($t) => Titles::agree($t, $entry->title));
        $exact = $found->filter(fn (Manuscript $m) => preg_replace('/\s+/u', '', TextNormalizer::normalize($m->shelfmark)) === $shelfmark['number']
            || ($suffix !== '' && str_contains(TextNormalizer::normalize($m->shelfmark), $suffix)));
        if ($exact->count() === 1) {
            return $agrees($exact->first()) ? [$exact->first(), 'exact'] : [null, 'title-differs'];
        }
        $agreeing = $found->filter($agrees);

        return match ($agreeing->count()) {
            1 => [$agreeing->first(), 'part'],
            0 => [null, 'title-differs'],
            default => [null, 'several'],
        };
    }
}
