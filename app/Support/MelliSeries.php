<?php

namespace App\Support;

/**
 * The two number series of the National Library in Tehran (step 14.416). Its older manuscripts are numbered twice
 * over, the Persian ones («ف») and the Arabic ones («ع») each from 1, and Fankha prints the number without the
 * letter: «2897» is an Arabic book of 137 leaves and «2897/1» to «2897/4» are the treatises of a Persian volume. What
 * tells them apart is the volume of the library's catalogue the entry cites («[ف: 13-418]»): volumes 1 to 6 describe
 * the Persian series (numbers 1 to 3083) and volumes 7 to 19 the Arabic one (1 to 4700). The language of the work
 * does not: the Arabic volumes hold Persian treatises and the Persian ones Arabic. Later numbers (volume 22, and the
 * copies cited from the library's database, «[رایانه]») are one series.
 *
 * A copy of the Arabic series takes the mark «ع» in its volume key («2897-ع», as «791-سنا» for the Senate), so the
 * two books of one number are two volumes; the Persian series and a copy that cites no volume keep the plain key. The
 * shelfmark itself stays as Fankha prints it. A copy written by hand with the mark («2897/5-ع») gets the same key
 * from Manuscript::collectionKey.
 */
class MelliSeries
{
    /** The mark of the Arabic series in a volume key and beside a shown shelfmark. */
    public const ARABIC = 'ع';

    /** The catalogue volumes that describe the Arabic series. */
    private const ARABIC_VOLUMES = [7, 19];

    /** No catalogue volume has a higher number: a higher first number is the page, printed before the volume («[ف: 596-6]»). */
    private const LAST_VOLUME = 30;

    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /** Whether a copy's Fankha text is of the National Library in Tehran and cites a catalogue volume of its Arabic series. */
    public static function arabic(?string $rawText): bool
    {
        $volume = self::catalogueVolume($rawText);

        return $volume !== null && $volume >= self::ARABIC_VOLUMES[0] && $volume <= self::ARABIC_VOLUMES[1];
    }

    /**
     * The volume of the National Library's catalogue a copy's text cites («[ف: 13-418]», «[ملی ایران: ف: 13 - 418]»),
     * null for a copy of another library or one that cites the library's database or a journal («[رایانه]», «[نشریه: …]»).
     */
    public static function catalogueVolume(?string $rawText): ?int
    {
        // «259. تهران؛ ملی؛ شماره نسخه: 2897»: the city and library open the first line
        if ($rawText === null || ! preg_match('/^[\s\d.\-]*تهران\s*؛\s*مل[یيى](?:\s+ایران)?\s*؛/u', $rawText)) {
            return null;
        }
        $text = strtr(preg_replace('/<!--.*?-->/su', ' ', $rawText), self::DIGITS);
        if (! preg_match('/\[\s*(?:ملی(?:\s+ایران)?\s*:?\s*)?ف\s*:\s*(\d+)\s*[-–—]?\s*(\d+)\s*\]\s*$/u', $text, $m)) {
            return null;
        }
        [$first, $second] = [(int) $m[1], (int) $m[2]];
        if ($first <= self::LAST_VOLUME) {
            return $first;
        }

        return $second <= self::LAST_VOLUME ? $second : null;
    }
}
