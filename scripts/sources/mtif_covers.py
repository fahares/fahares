#!/usr/bin/env python3
"""Cover images of the printed catalogues from mtif.org (بنیاد محقق طباطبایی), for «منابع و مراجع» (step 13.81).

    python3 -I scripts/sources/mtif_covers.py SOURCES_JSON MTIF_JSON OUT_DIR

SOURCES_JSON is the site's sources, [{"code", "title", "kind", "edition_label", "edition_of", "mtif": [volume entry
ids], "years": [...]}]; MTIF_JSON is storage/mtif_extracted_data.json, whose catalog_volumes give a volume's series and
whose catalog_series give the series' titles. A source finds its series by its volumes' MTIF ids, or else by its
title. The series page, https://mtif.org/book/{series}, shows each printing («المطبوع من الكتاب») with its cover, whose
alt text is the printing's title. A cover is taken only when that title has most of the source's own words (the volume
ids of the extracted data point to the wrong series now and then: Isfahan's catalogue to Marashi's); of those, the
printing whose year is the source's, else the first. Each cover is written as OUT_DIR/{code}.webp, 360 px wide, and
OUT_DIR/manifest.json says where each came from. A source done before is not fetched again.
"""
import argparse
import html
import io
import json
import re
import time
import urllib.request
from pathlib import Path

from PIL import Image

SITE = 'https://mtif.org'
AGENT = 'Mozilla/5.0 (fahares.net; cover images of catalogues)'
FA = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')


def norm(t: str) -> str:
    t = (t or '').translate(str.maketrans({'ي': 'ی', 'ى': 'ی', 'ك': 'ک', 'ة': 'ه', 'ۀ': 'ه', 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', '‌': ' '}))
    return re.sub(r'\s+', ' ', re.sub(r'[^\w\s]', ' ', t)).strip()


# words every catalogue's title has, which say nothing of which one it is
COMMON = set('فهرست فهرستواره نسخه نسخ های ها خطی کتب کتاب کتابهای کتابخانه کتابخانه های و در به ی از جلد اول دوم سوم مرکزی عمومی حضرت ایه الله العظمی ایت'.split())


def words(t: str) -> set[str]:
    return {w for w in norm(t).split() if w not in COMMON and not w.isdigit()}


def fits(alt: str, title: str) -> bool:
    """Whether a cover's alt text names the source: most of the source's own words are in it, and a catalogue of
    photocopies («عکسی») is not taken for one of manuscripts."""
    own = words(title)
    return bool(own) and len(own & words(alt)) / len(own) >= 0.6 and ('عکسی' in norm(alt)) == ('عکسی' in norm(title))


# covers whose title fits but which are another book of the series, seen when the covers were checked by eye
SKIP = {
    'catalogue-86c8a806d584': 'فهرست نسخ خطی قرآنهای مترجم، not the central library of Astan-e Quds',
}


def get(url: str) -> bytes:
    req = urllib.request.Request(url, headers={'User-Agent': AGENT})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def printings(series: int) -> list[dict]:
    """The series page's covers, each with the text of its printing (publisher, place, year)."""
    page = get(f'{SITE}/book/{series}').decode('utf-8', 'replace')
    found = []
    for m in re.finditer(r'thumbnail\.php\?table=pic&(?:amp;)?full_size=1&(?:amp;)?id=(\d+)', page):
        alt = re.search(r'alt="([^"]*)"', page[m.end():m.end() + 300])
        text = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', page[m.end():m.end() + 1500])))
        found.append({'pic': int(m.group(1)), 'alt': html.unescape(alt.group(1)) if alt else '', 'text': text[:400],
                      'years': re.findall(r'1[234]\d\d', text.translate(FA))})
    return found


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('sources')
    ap.add_argument('mtif')
    ap.add_argument('out')
    args = ap.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    manifest_path = out / 'manifest.json'
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}

    mtif = json.loads(Path(args.mtif).read_text())
    series_of_volume = {v['mtif_id']: v['series_id'] for v in mtif['catalog_volumes']}
    series_by_title = {norm(s['title']): s['id'] for s in mtif['catalog_series']}
    pages: dict[int, list] = {}

    for src in json.loads(Path(args.sources).read_text()):
        code = src['code']
        if code in manifest or code in SKIP:
            continue
        # the series of the source's volumes, then the one of its title; the first whose cover names the source
        candidates = [(s, 'volume') for s in dict.fromkeys(series_of_volume[i] for i in src['mtif'] if i in series_of_volume)]
        if (by_title := series_by_title.get(norm(src['title']))) and by_title not in [c[0] for c in candidates]:
            candidates.append((by_title, 'title'))
        covers, series, how = [], None, None
        for series, how in candidates:
            if series not in pages:
                try:
                    pages[series] = printings(series)
                except Exception as e:  # a page that fails is tried again on the next run
                    print(f'{code}: series {series}: {e}')
                    pages[series] = None
                time.sleep(1)
            covers = [c for c in pages[series] or [] if fits(c['alt'], src['title'])]
            if covers:
                break
        if not candidates:
            continue
        if not covers:
            manifest[code] = {'series': series, 'by': how, 'pic': None}
            continue
        years = {str(y).translate(FA)[:4] for y in src['years']} | set(re.findall(r'1[234]\d\d', (src['edition_label'] or '').translate(FA)))
        chosen = next((c for c in covers if years & set(c['years'])), covers[0])
        try:
            image = Image.open(io.BytesIO(get(f"{SITE}/thumbnail.php?table=pic&full_size=1&id={chosen['pic']}"))).convert('RGB')
        except Exception as e:
            print(f'{code}: picture {chosen["pic"]}: {e}')
            continue
        image.thumbnail((360, 540))
        image.save(out / f'{code}.webp', 'WEBP', quality=82)
        manifest[code] = {'series': series, 'by': how, 'pic': chosen['pic'], 'alt': chosen['alt'], 'printing': chosen['text'][:160],
                          'year_matched': bool(years & set(chosen['years']))}
        print(f"{code}: series {series} ({how}), picture {chosen['pic']}")
        time.sleep(1)
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1))
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1))
    print(f"{sum(1 for m in manifest.values() if m.get('pic'))} covers in {out}")


if __name__ == '__main__':
    main()
