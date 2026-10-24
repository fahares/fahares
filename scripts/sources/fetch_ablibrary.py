"""Fetch the page texts of a book from ablibrary.net (Ahlulbayt Library), politely and resumably.

ablibrary numbers a book's pages by leaf (the url index), and shows each page's printed number in its title
(«الذريعة — صفحة 402»); the two differ by the front matter, so pages are fetched by url index until the book
ends and the printed number is read from each page afterwards (extract).

    fetch    one request every --delay seconds (default 3); a page already saved is skipped, so a stopped run
             resumes where it left off. Raw pages go to OUT/raw/{book}/{index:04d}.html.gz
    extract  OUT/{volume:02d}.jsonl.gz, one {"page", "label", "book", "index", "text"} per printed page, for
             `sources:import-pages`. The reader's paragraphs (an entry begins one) are kept apart by a blank line.
             Front matter keeps its label and has page null: a label met again later in the volume (the cover leaves
             of a book are labelled 1 and 2 like its first pages) belongs to the later leaf

Usage (al-Dhari'a, plan docs/plans/dharia-text.md):
    python3 fetch_ablibrary.py fetch   OUT [--delay 3] [--books 2149,2150]
    python3 fetch_ablibrary.py extract OUT
"""
import argparse
import gzip
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

# al-Dhari'a: book id => (volume, first printed page of the book); volume 9 is printed in four parts paged on
DHARIA = {
    2130: (1, 1), 2141: (2, 1), 2149: (3, 1), 2150: (4, 1), 2151: (5, 1), 2152: (6, 1), 2153: (7, 1), 2154: (8, 1),
    2155: (9, 1), 2156: (9, 335), 2157: (9, 687), 2158: (9, 1143),
    2131: (10, 1), 2132: (11, 1), 2133: (12, 1), 2134: (13, 1), 2135: (14, 1), 2136: (15, 1), 2137: (16, 1),
    2138: (17, 1), 2139: (18, 1), 2140: (19, 1), 2142: (20, 1), 2143: (21, 1), 2144: (22, 1), 2145: (23, 1),
    2146: (24, 1), 2147: (25, 1), 2148: (26, 1),
}
URL = 'https://ablibrary.net/book_content/b/{book}/{index}'
AGENT = 'fahares-source-fetch/1.0 (+https://fahares.net)'
NOT_FOUND = 'الصفحة غير موجودة'


def log(msg):
    print(time.strftime('%Y-%m-%d %H:%M:%S'), msg, flush=True)


def get(url, tries=5):
    """The page's html, or None when the site says the page does not exist."""
    for attempt in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': AGENT, 'Accept-Language': 'ar'})
            with urllib.request.urlopen(req, timeout=60) as r:
                body = r.read().decode('utf-8', 'replace')
            return None if NOT_FOUND in body[:20000] else body
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None
            wait = 60 * (attempt + 1) if e.code in (429, 503) else 10 * (attempt + 1)
            log(f'HTTP {e.code} {url}, waiting {wait}s')
            time.sleep(wait)
        except Exception as e:  # network errors: back off and retry
            log(f'error {e!r} {url}, waiting {10 * (attempt + 1)}s')
            time.sleep(10 * (attempt + 1))
    raise RuntimeError(f'giving up on {url}')


def fetch(out, books, delay, limit=None):
    for book in books:
        folder = os.path.join(out, 'raw', str(book))
        os.makedirs(folder, exist_ok=True)
        index, misses, saved, last = 1, 0, 0, 0
        # two missing pages in a row end the book (a single gap is retried past)
        while misses < 2 and not (limit and index > limit):
            path = os.path.join(folder, f'{index:04d}.html.gz')
            if os.path.exists(path):
                last, index, misses = index, index + 1, 0
                continue
            body = get(URL.format(book=book, index=index))
            time.sleep(delay)
            if body is None:
                misses += 1
                index += 1
                continue
            misses, last = 0, index
            with gzip.open(path + '.part', 'wt', encoding='utf-8') as f:
                f.write(body)
            os.replace(path + '.part', path)
            saved += 1
            if saved % 50 == 0:
                log(f'book {book}: page {index}')
            index += 1
        log(f'book {book} done: {last} pages')
    log('ALL DONE')


def page_text(body):
    """The reader's text: one block per printed paragraph, line breaks as printed."""
    i = body.find('itemProp="articleBody"')
    if i < 0:
        return ''
    seg = body[i:]
    end = seg.find('</article>')
    seg = seg[:end] if end > 0 else seg
    parts = []
    for p in re.findall(r'<div class="mb-2"[^>]*>(.*?)</div>', seg, re.S):
        p = html.unescape(re.sub(r'<[^>]+>', '', re.sub(r'<br\s*/?>', '\n', p))).strip()
        if p:
            parts.append(p)
    return '\n\n'.join(parts)


def extract(out):
    by_volume = {}
    for book, (volume, _first) in sorted(DHARIA.items()):
        folder = os.path.join(out, 'raw', str(book))
        if not os.path.isdir(folder):
            continue
        for name in sorted(os.listdir(folder)):
            if not name.endswith('.html.gz'):
                continue
            with gzip.open(os.path.join(folder, name), 'rt', encoding='utf-8') as f:
                body = f.read()
            title = re.search(r'<title>(.*?)</title>', body, re.S)
            label = re.search(r'صفحة\s*(.*?)\s*\|', html.unescape(title.group(1))) if title else None
            label = label.group(1).strip() if label else ''
            page = int(label) if label.isdigit() else None
            by_volume.setdefault(volume, []).append(
                {'page': page, 'label': label, 'book': book, 'index': int(name[:4]), 'text': page_text(body)})
    for volume, rows in sorted(by_volume.items()):
        last = {}
        for i, r in enumerate(rows):
            if r['page'] is not None:
                last[r['page']] = i
        dupes = sorted(p for p in last if sum(1 for r in rows if r['page'] == p) > 1)
        for i, r in enumerate(rows):
            if r['page'] is not None and last[r['page']] != i:
                r['page'] = None
        pages = [r['page'] for r in rows if r['page'] is not None]
        with gzip.open(os.path.join(out, f'{volume:02d}.jsonl.gz'), 'wt', encoding='utf-8') as f:
            for r in rows:
                f.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'volume {volume}: {len(rows)} pages, printed {min(pages, default=0)}-{max(pages, default=0)}'
              f', front matter {len(rows) - len(pages)}, labels met twice {dupes[:10]}')


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('command', choices=['fetch', 'extract'])
    ap.add_argument('out')
    ap.add_argument('--delay', type=float, default=3.0)
    ap.add_argument('--limit', type=int, help='stop each book after this url index (for testing)')
    ap.add_argument('--books', help='comma-separated book ids (default: all of al-Dhari\'a)')
    a = ap.parse_args()
    books = [int(b) for b in a.books.split(',')] if a.books else sorted(DHARIA)
    if a.command == 'fetch':
        fetch(a.out, books, a.delay, a.limit)
    else:
        extract(a.out)
    sys.exit(0)
