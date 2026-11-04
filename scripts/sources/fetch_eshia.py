#!/usr/bin/env python3
"""Fetch the page texts of a book from lib.eshia.ir (کتابخانه مدرسه فقاهت), politely and resumably, and turn them into
page texts and entries for sources:import-pages and sources:segment --entries (item 86).

eshia numbers a book's pages by the printed page, «/10984/{volume}/{page}», the address the site's citations of
Rayhanat al-Adab already link to; its Rayhana is the Khayyam print whose scans the site has. The page's text is in
<td id="book-page-content">: paragraphs, poems in tables (a hemistich a cell), and each entry's heading marked
<span class="KalamateKhas">اديبى احمد بن ابراهيم خوارزمى-</span>.

    fetch    one request every --delay seconds (default 2); a page already saved is skipped, so a stopped run resumes
             where it left off, and a failed request is tried again after a pause. Each volume's last page is read from
             its first page («صفحه‌آخر»). Raw pages go to OUT/raw/{book}/{volume:02d}/{page:04d}.html.gz
    extract  OUT/{volume:02d}.jsonl.gz with {"page", "text"} per printed page and OUT/{volume:02d}.entries.jsonl.gz
             with the volume's entries, a heading each; an entry's key is its first page and its place among the
             entries that start there, «100#2». The text is Persian: Arabic ي, ى and ك become ی and ک.

Usage (Rayhanat al-Adab, the default book):
    python3 fetch_eshia.py fetch   OUT [--book 10984] [--volumes 1-8] [--delay 2]
    python3 fetch_eshia.py extract OUT [--book 10984] [--volumes 1-8]
"""
import argparse
import gzip
import html
import json
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

URL = 'https://lib.eshia.ir/{book}/{volume}/{page}'
AGENT = 'fahares-source-fetch/1.0 (+https://fahares.net)'
PERSIAN = str.maketrans({'ي': 'ی', 'ى': 'ی', 'ك': 'ک'})
HEADING = re.compile(r'<span class="KalamateKhas">(.*?)</span>', re.S)


def volumes(spec):
    a, _, b = spec.partition('-')
    return range(int(a), int(b or a) + 1)


def get(url, tries=5):
    """The page's HTML, or None when it does not exist; a failure is tried again after a longer pause each time."""
    for attempt in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': AGENT})
            with urllib.request.urlopen(req, timeout=120) as r:
                return r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None
            wait = 30 * (attempt + 1)
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            wait = 30 * (attempt + 1)
        print(f'  {url}: failed, again in {wait}s', file=sys.stderr, flush=True)
        time.sleep(wait)
    raise SystemExit(f'giving up on {url}; run again to resume')


def raw_path(out, book, volume, page):
    return Path(out) / 'raw' / str(book) / f'{volume:02d}' / f'{page:04d}.html.gz'


def last_page(text, book, volume):
    pages = [int(p) for p in re.findall(rf'href="/{book}/{volume}/(\d+)"', text)]
    return max(pages) if pages else None


def fetch(args):
    for volume in volumes(args.volumes):
        first = raw_path(args.out, args.book, volume, 1)
        if first.exists():
            text = gzip.open(first, 'rt', encoding='utf-8').read()
        else:
            text = get(URL.format(book=args.book, volume=volume, page=1))
            if text is None:
                print(f'volume {volume}: no first page', flush=True)
                continue
            first.parent.mkdir(parents=True, exist_ok=True)
            with gzip.open(first, 'wt', encoding='utf-8') as f:
                f.write(text)
            time.sleep(args.delay)
        last = last_page(text, args.book, volume)
        print(f'volume {volume}: pages 1-{last}', flush=True)
        done = 0
        for page in range(2, (last or 1) + 1):
            path = raw_path(args.out, args.book, volume, page)
            if path.exists():
                continue
            text = get(URL.format(book=args.book, volume=volume, page=page))
            if text is not None:
                path.parent.mkdir(parents=True, exist_ok=True)
                with gzip.open(path, 'wt', encoding='utf-8') as f:
                    f.write(text)
            done += 1
            if done % 25 == 0:
                print(f'  volume {volume}: page {page} of {last}', flush=True)
            time.sleep(args.delay)


def blocks(page_html):
    """The page's text as (kind, text) in order: 'heading' for an entry's heading, 'line' for a paragraph or a verse
    (its hemistichs apart by a tab)."""
    # from the text cell to the navigation panel under it; the poems' tables have cells of their own
    m = re.search(r'id="book-page-content"[^>]*>(.*?)<table align="center" class="BookAccessPanel"', page_html, re.S)
    if not m:
        return []
    body = re.sub(r'<div class="sticky-menue">.*?</div>', '', m.group(1), flags=re.S)
    out = []
    # a poem: a table whose cells are its hemistichs, a row a verse
    for part in re.split(r'(<table.*?</table>)', body, flags=re.S):
        if part.startswith('<table'):
            for row in re.findall(r'<tr[^>]*>(.*?)</tr>', part, re.S):
                cells = [clean(c) for c in re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)]
                cells = [c for c in cells if c]
                if cells:
                    out.append(('line', '\t'.join(cells)))
            continue
        for p in re.findall(r'<p[^>]*>(.*?)</p>', part, re.S):
            head = HEADING.search(p)
            if head:
                out.append(('heading', clean(head.group(1))))
                rest = clean(HEADING.sub('', p))
                if rest:
                    out.append(('line', rest))
            elif clean(p):
                out.append(('line', clean(p)))
    return out


def clean(fragment):
    text = html.unescape(re.sub(r'<[^>]+>', ' ', fragment.replace('&zwnj;', '‌')))
    return ' '.join(text.split()).translate(PERSIAN)


def extract(args):
    out = Path(args.out)
    for volume in volumes(args.volumes):
        folder = raw_path(args.out, args.book, volume, 1).parent
        files = sorted(folder.glob('*.html.gz'))
        if not files:
            continue
        pages, entries = [], []
        for path in files:
            page = int(path.name.split('.')[0])
            lines = []
            for kind, text in blocks(gzip.open(path, 'rt', encoding='utf-8').read()):
                if kind == 'heading':
                    title = text.rstrip(' -–:\u200c')
                    entries.append({'heading': title, 'page': page, 'lines': [title], 'breaks': []})
                    lines += ['', title]
                    continue
                lines.append(text)
                if entries:
                    e = entries[-1]
                    if page != (e['breaks'][-1][1] if e['breaks'] else e['page']):
                        # where the entry's text turns to the next page, as an offset into its text
                        e['breaks'].append((len('\n'.join(e['lines'])) + 1, page))
                    e['lines'].append(text)
            text = '\n'.join(lines).strip()
            if text:
                pages.append({'page': page, 'text': text})
        rows, per_page = [], {}
        for e in entries:
            per_page[e['page']] = per_page.get(e['page'], 0) + 1
            rows.append({
                'key': f"{e['page']}#{per_page[e['page']]}", 'kind': 'entry', 'title': e['heading'],
                'page_start': e['page'], 'page_end': e['breaks'][-1][1] if e['breaks'] else e['page'],
                'text': '\n'.join(e['lines']), 'page_breaks': [[at, p] for at, p in e['breaks']], 'parent_key': None,
            })
        for name, data in ((f'{volume:02d}.jsonl.gz', pages), (f'{volume:02d}.entries.jsonl.gz', rows)):
            with gzip.open(out / name, 'wt', encoding='utf-8') as f:
                for r in data:
                    f.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'volume {volume}: {len(pages)} pages {pages[0]["page"] if pages else "-"}-{pages[-1]["page"] if pages else "-"}, '
              f'{len(rows)} entries', flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('command', choices=['fetch', 'extract'])
    ap.add_argument('out')
    ap.add_argument('--book', type=int, default=10984)
    ap.add_argument('--volumes', default='1-8')
    ap.add_argument('--delay', type=float, default=2)
    args = ap.parse_args()
    Path(args.out).mkdir(parents=True, exist_ok=True)
    (fetch if args.command == 'fetch' else extract)(args)


if __name__ == '__main__':
    main()
