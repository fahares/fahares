#!/usr/bin/env python3
"""Map an archive.org scan's leaves to the printed pages of a source the site has as text (step 14.86): each leaf's OCR
(the item's _hocr_searchtext.txt.gz, cut by _hocr_pageindex.json.gz) is matched to the page text from Noor whose
character trigrams it shares most, among pages near the leaf; a steady offset (page − leaf) between two matched leaves
maps the leaves between them, and pages no leaf matches are missing from the scan.

    python3 -I scripts/facsimile/align_ia_scans.py CODE PAGES_DIR OUT.json VOLUME=IDENTIFIER[:PDF_NAME] ...

PAGES_DIR holds the source's {NN}.jsonl.gz ({"page", "text"} per printed page, as sources:import-pages reads them).
Writes OUT.json as the viewer's mapping: {"01": {"1": ["01", 3], ...}} (printed page → [PDF, leaf]), and prints for
each volume the leaves, the pages mapped, the pages missing and how sure the matches are.
"""
import collections
import gzip
import json
import re
import sys
import time
import urllib.parse
import urllib.request

UA = {'User-Agent': 'fahares-source-fetch/1.0 (+https://fahares.net)'}
FOLD = str.maketrans('يىكةأإآؤئۀ', 'ییکهاااویه')


def get(url):
    for attempt in range(8):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120) as r:
                return r.read()
        except Exception as e:
            print(f'  retry {attempt}: {e}', file=sys.stderr, flush=True)
            time.sleep(10 * (attempt + 1))
    raise SystemExit(f'gave up on {url}')


def grams(text):
    t = re.sub(r'[^ء-ی]', '', re.sub(r'[ً-ْٰ]', '', text or '').translate(FOLD))
    return {t[i:i + 3] for i in range(len(t) - 2)}


def leaves(identifier, pdf):
    """The OCR text of each leaf of one PDF of the item, in order."""
    files = [f['name'] for f in json.loads(get(f'https://archive.org/metadata/{identifier}'))['files']]
    base = next(n[:-len('_hocr_searchtext.txt.gz')] for n in files if n.endswith('_hocr_searchtext.txt.gz')
                and (pdf is None or n.startswith(pdf)))
    q = lambda n: f'https://archive.org/download/{identifier}/' + urllib.parse.quote(n)
    text = gzip.decompress(get(q(base + '_hocr_searchtext.txt.gz'))).decode('utf-8')
    index = json.loads(gzip.decompress(get(q(base + '_hocr_pageindex.json.gz'))))
    return [text[a:b] for a, b, *_ in index]


def align(pages, ocr):
    """[(leaf, page, score)] for the leaves whose best page is clearly theirs."""
    labels = [p['page'] for p in pages]
    page_grams = [grams(p['text']) for p in pages]
    found = []
    for leaf, text in enumerate(ocr, 1):
        g = grams(text)
        if len(g) < 40:
            continue
        scores = sorted(((len(g & pg) / max(1, min(len(g), len(pg))), i) for i, pg in enumerate(page_grams) if pg), reverse=True)
        if scores and scores[0][0] >= 0.35 and (len(scores) == 1 or scores[0][0] - scores[1][0] >= 0.08):
            found.append((leaf, labels[scores[0][1]], round(scores[0][0], 2)))
    return found


def build(found, leaf_count, last_page):
    """printed page → leaf: between two matches of one offset every leaf; an offset kept by a run of three or more."""
    offsets = [p - l for l, p, _ in found]
    runs, i = [], 0
    while i < len(found):
        j = i
        while j + 1 < len(found) and offsets[j + 1] == offsets[i]:
            j += 1
        if j - i >= 2:
            # a run of the offset the run before it had: the leaves between them unmatched (a short page, a table) keep it
            if runs and runs[-1][2] == offsets[i]:
                runs[-1] = (runs[-1][0], found[j][0], offsets[i])
            else:
                runs.append((found[i][0], found[j][0], offsets[i]))
        i = j + 1
    mapping = {}
    for k, (a, b, off) in enumerate(runs):
        lo = 1 if k == 0 else a
        hi = leaf_count if k == len(runs) - 1 else b
        for leaf in range(lo, hi + 1):
            page = leaf + off
            if 1 <= page <= last_page and page not in mapping:
                mapping[page] = leaf
    return mapping, runs


def main():
    code, pages_dir, out = sys.argv[1:4]
    result = {}
    for spec in sys.argv[4:]:
        volume, _, rest = spec.partition('=')
        identifier, _, pdf = rest.partition(':')
        pages = [json.loads(l) for l in gzip.open(f'{pages_dir}/{int(volume):02d}.jsonl.gz', 'rt', encoding='utf-8')]
        ocr = leaves(identifier, pdf or None)
        found = align(pages, ocr)
        last = max(p['page'] for p in pages)
        mapping, runs = build(found, len(ocr), last)
        have = {p['page'] for p in pages}
        missing = sorted(have - set(mapping))
        print(f'{code} v{int(volume):02d}: {len(ocr)} leaves, {len(found)} matched, offsets {[(a, b, o) for a, b, o in runs]}, '
              f'{len(mapping)} pages mapped, {len(missing)} text pages without a leaf {missing[:12]}', flush=True)
        result[f'{int(volume):02d}'] = {str(p): [f'{int(volume):02d}', leaf] for p, leaf in sorted(mapping.items())}
    with open(out, 'w', encoding='utf-8') as f:
        json.dump(result, f, ensure_ascii=False, indent=0)


if __name__ == '__main__':
    main()
