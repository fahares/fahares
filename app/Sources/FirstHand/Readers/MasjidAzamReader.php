<?php

namespace App\Sources\FirstHand\Readers;

use App\Sources\FirstHand\CopyLine;

/**
 * The copy line of «فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم» (Ostadi; backlog item 147, step 14.250). Two layouts:
 *
 *   one book: «ش 176، 397 برگ، 18×28، نسخ محمد در 1084، با سر لوح …» — shelfmark, leaves, size, then the script, the
 *     scribe and «در» the date (or «بیکا، بیتا»), up to the first «.»; «مؤلف» or «مترجم» for the scribe is the author's
 *     or translator's own hand;
 *   a collection: «( 649 )», a line for each treatise, and «212 برگ، 11 × 18» last, the count of the whole volume.
 *     Only the shelfmark and the size are read: the treatises' dates are not tied here to the copy's own row.
 */
class MasjidAzamReader implements Reader
{
    public function read(string $text): CopyLine
    {
        $line = new CopyLine();
        $text = CopyLine::normalize($text);

        if (preg_match('/^\(\s*(\d+)\s*\)/u', $text, $m)) {
            $line->wholeVolume = true;
            $line->set('shelfmark', $m[1], $m[0]);
            foreach (array_reverse(preg_split('/\R/u', $text)) as $row) {
                if (preg_match('/(\d+)\s*برگ\s*،\s*(\d+(?:\.\d+)?)\s*[×x*]\s*(\d+(?:\.\d+)?)/u', $row, $m)) {
                    $line->set('folios', (int) $m[1], $m[0]);
                    $line->set('dimensions', GolpayeganiReader::size($m[2], $m[3]), $m[0]);
                    break;
                }
            }

            return $line;
        }

        if (! preg_match('/^ش\s*\.?\s*(\d+)\s*،/u', $text, $m)) {
            return $line;
        }
        $line->set('shelfmark', $m[1], $m[0]);
        $rest = mb_substr($text, mb_strlen($m[0]));
        // the description runs to the first «.» that ends a sentence (not a decimal point)
        $head = preg_split('/\.(?!\d)|\R/u', $rest)[0];
        $pieces = array_values(array_filter(array_map('trim', preg_split('/\s*،\s*/u', $head)), fn ($p) => $p !== ''));

        foreach ($pieces as $i => $piece) {
            if (preg_match('/^(\d+)\s*برگ$/u', $piece, $m)) {
                $line->set('folios', (int) $m[1], $piece);
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*[×x*]\s*(\d+(?:\.\d+)?)(.*)$/u', $piece, $m)) {
                $line->set('dimensions', GolpayeganiReader::size($m[1], $m[2]), $m[1] . '×' . $m[2]);
                // «18×24 نستعلیق محمدبن حسن اصفهانی در 995»: the script may follow the size without a comma
                if (trim($m[3]) !== '') {
                    $this->hand(trim($m[3]), array_slice($pieces, $i + 1), $line);
                    break;
                }
            } elseif (CopyLine::scriptsAt($piece)) {
                $this->hand($piece, array_slice($pieces, $i + 1), $line);
                break;
            }
        }

        return $line;
    }

    /** «نسخ محمد در 1084»، or «نستعلیق» followed by «بیکا»، «بیتا». */
    private function hand(string $piece, array $after, CopyLine $line): void
    {
        $scripts = CopyLine::scriptsAt($piece);
        if (! $scripts) {
            return;
        }
        $line->set('scripts', $scripts, $piece);
        $rest = CopyLine::afterScripts($piece);
        foreach (array_merge([$rest], $after) as $part) {
            $part = trim($part, " .\t");
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(بی\s*تاریخ|بدون تاریخ|تاریخ ندارد)/u', $part)) {
                $line->set('bita', true, $part);
                break;
            }
            if (preg_match('/^(سده|قرن|حدود|تاریخ کتابت|تحریر)(?!\p{L})/u', $part)) {
                // «سده 13»، «حدود 1255»، «تاریخ کتابت 1240 یا 1248»: a date, no scribe
                $line->set('copy_date', preg_replace('/^(تاریخ کتابت|تحریر)\s*/u', '', $part), $part);
                break;
            }
            if (preg_match('/^\d/u', $part)) {
                // «1306 یا 1321»، «1217»: a date with no scribe
                $line->set('copy_date', preg_replace('/\s+در\s+.+$/u', '', $part), $part);
                break;
            }
            if (preg_match('/^بی\s*کا(?!\p{L})/u', $part)) {
                $line->set('bika', true, $part);
                $part = trim(preg_replace('/^بی\s*کا/u', '', $part));
            }
            if (preg_match('/^بی\s*تا(?!\p{L})/u', $part)) {
                $line->set('bita', true, $part);
                break;
            }
            if ($part === '') {
                continue;
            }
            // «محمد در محرم 1236 در ساری»: the scribe before the first «در», the date after it, to the next «در»
            if (preg_match('/^(.*?)\s*(?<!\p{L})در\s+(?:سال\s+)?(.+?)(?:\s+در\s+.+)?$/u', $part, $m) && preg_match('/\d{3,4}/u', $m[2])) {
                $this->scribe(trim($m[1]), $line);
                $line->set('copy_date', trim($m[2]), $part);
            } elseif (preg_match('/^(?:[^\d]*?\s)?(\d{3,4}(?:\s*ق)?)(?:\s+در\s+.+)?$/u', $part, $m)) {
                // «نستعلیق 1238»، «نسخ 1202»، «نستعلیق 1062 در اصفهان»
                $this->scribe(trim(preg_replace('/\d.*$/u', '', $part)), $line);
                $line->set('copy_date', $m[1], $part);
            } else {
                $this->scribe($part, $line);
            }
            break;
        }
    }

    private function scribe(string $name, CopyLine $line): void
    {
        if ($name === '') {
            return;
        }
        if (in_array($name, ['همان کاتب', 'همان'], true)) {
            return;
        }
        if (CopyLine::isNote($name)) {
            return;
        }
        if (CopyLine::isAutographWord($name)) {
            $line->set('autograph', true, $name);

            return;
        }
        $line->set('scribe', $name, $name);
    }
}
