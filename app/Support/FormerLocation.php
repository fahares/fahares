<?php

namespace App\Support;

/** One former place of a copy (metadata.former_locations), as the copy's page and its correction history read it. */
class FormerLocation
{
    /**
     * Where the copy sat in the former volume (step 14.80): «برگ ۲۱۳پ تا ۲۴۶پ (۳۴ برگ)», or the leaf count alone.
     * A copy that two catalogues described was often one treatise of a collection the present library split up.
     */
    public static function extent(array $former): ?string
    {
        $from = trim((string) ($former['range_from'] ?? ''));
        $to = trim((string) ($former['range_to'] ?? ''));
        $folios = (int) ($former['folios'] ?? 0);
        $count = $folios > 0 ? Fa::num($folios) . ' برگ' : null;
        if ($from !== '' && $to !== '') {
            return 'برگ ' . Fa::digits($from) . ' تا ' . Fa::digits($to) . ($count ? ' (' . $count . ')' : '');
        }

        return $count;
    }
}
