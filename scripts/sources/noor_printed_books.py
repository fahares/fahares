#!/usr/bin/env python3
"""Page (column) texts and entries of the catalogues of printed books in Noor's «کتب مرجع ۲» database (item 86): Mushar's
«فهرست کتابهای چاپی فارسی» and «فهرست کتابهای چاپی عربی», and Sarkis's «معجم المطبوعات العربیة و المعربة», for
sources:import-pages and sources:segment --entries.

    python3 -I scripts/sources/noor_printed_books.py KOTOBMARJA2_DB OUT_DIR [--sources mushar-fa,mushar-ar,sarkis]

Writes OUT_DIR/{code}/{volume:02d}.jsonl.gz with {"page", "text"} per printed column and
OUT_DIR/{code}/{volume:02d}.entries.jsonl.gz with the volume's entries. The database is opened read-only.

Noor's editions are the ones Fankha cites (step 14.18): Mushar's Persian catalogue in five volumes whose columns run on
(1-5614), his Arabic one in one volume, and Sarkis's two volumes, Cairo 1928 in the Marashi offset, whose columns also
run on (1-2024). <page number="N"/> starts column N. A document also holds front matter or indexes numbered apart, so
only the longest run of rising column numbers is read; the few columns a volume repeats from the one before it are its
title pages and stay empty, and so are left out.

Mushar heads each book (level 3 in the Persian catalogue, level 2 in the Arabic one) with its title and a colon,
«آثار الاحزان (رياض البكاء): », and its letters one level up. Sarkis heads each author (level 3), «ابراهيم (بك) مرزوق
(1233-1283)», with his life after it, and each of his books (level 4, a part of one level 5), «1 - الدر البهي
المنسوق بديوان …», with its printings after it: the author is the entry and his books its sub-entries, titled
without their number and the printing after « - ». Mushar's catalogues are Persian, so Noor's Arabic ي and ك become
ی and ک; Sarkis is Arabic and keeps them.

Footnotes go at the end of their column as «( 1 ) …». An entry's key is its first column and its place among the
entries that start there, «500#2».
"""
import argparse
import gzip
import html
import json
import re
import sqlite3
from pathlib import Path

PAGE = re.compile(r'<page number="([^"]*)"/>')
HEADING = re.compile(r'<heading level="(\d)">(.*?)</heading>', re.S)
PERSIAN = str.maketrans({'ي': 'ی', 'ى': 'ی', 'ك': 'ک'})

# code: series, volumes, heading levels that are entries, levels that are sub-entries, whether the text is Persian
SOURCES = {
    'mushar-fa': ('03292', 5, {3}, set(), True),
    'mushar-ar': ('03291', 1, {2}, set(), True),
    'sarkis': ('03307', 2, {3, 4, 5}, {4, 5}, False),
}

LINE = ''  # a line break the text keeps: before and after a verse that opens a line

# Noor's marks kept in the page text (step 13.95), each span as OPEN + kind + SEP … CLOSE: a Quran verse
# («ayah 18:46», «ayah 2:1-5»), a hadith («hadith»), a passage in Latin letters («latin»). A heading keeps none,
# so an entry is still found by its first line. App\Support\PrintedPage::text() reads them.
OPEN, SEP, CLOSE = '', '', ''
FORMAT = re.compile(r'<format\b([^>]*?)(/?)>|</format>')


def kind_of(attrs):
    cls = (re.search(r'class="([^"]*)"', attrs) or [None, ''])[1]
    if cls == 'ayah':
        surah = (re.search(r'surah="(\d+)"', attrs) or [None, ''])[1]
        ayah = (re.search(r'\bayah="(\d+)"', attrs) or [None, ''])[1]
        to = (re.search(r'toAyah="(\d+)"', attrs) or [None, ''])[1]
        return f'ayah {surah}:{ayah}' + (f'-{to}' if to and to != ayah else '') if surah and ayah else 'ayah'
    if cls == 'hadith':
        return 'hadith'
    if cls == 'changeLang':
        return 'latin'
    return None


def marks(xml):
    """Noor's <format> spans as the marks above; the rest of them go."""
    xml = re.sub(r'<heading[^>]*>.*?</heading>', lambda m: re.sub(r'</?format\b[^>]*>', '', m.group(0)), xml, flags=re.S)
    out, stack, at = [], [], 0
    for m in FORMAT.finditer(xml):
        out.append(xml[at:m.start()])
        at = m.end()
        if m.group(0) == '</format>':
            if stack and stack.pop():
                out.append(CLOSE)
        elif not m.group(2):
            kind = kind_of(m.group(1))
            stack.append(kind)
            if kind:
                out.append(OPEN + kind + SEP)
    out.append(xml[at:])
    # a span Noor left open closes at the end
    return ''.join(out) + CLOSE * sum(1 for k in stack if k)


def verse(m):
    """Noor's <poem>: a verse on a line of its own, its hemistichs apart by a tab, when it opens a line; a verse quoted
    inside a sentence stays in it."""
    before = re.sub(r'(?:<(?!br|/?p\b|/poem)[^>]*>|\s)+$', '', m.string[:m.start()])
    hemistichs = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', h)).split()).strip(' -–') for h in m.group(1).split('<gap/>')]
    if before and not re.search(r'(<br/?>|<p[^>]*>|</poem>)$', before):
        return ' ' + ' '.join(hemistichs) + ' '
    return LINE + '\t'.join(hemistichs) + ('\t' if len(hemistichs) == 1 else '') + LINE


def text_of(xml, keep=True):
    """The text on one line, a verse of poetry that opens a line on a line of its own; with keep, Noor's marks too."""
    if keep:
        xml = marks(xml)
    xml = re.sub(r'<lfootnote[^>]*>\s*(\d+)\s*</lfootnote>', r' ( \1 ) ', xml)
    xml = re.sub(r'<mnote>\s*\((\d+)\)\s*</mnote>', r'( \1 ) ', xml)
    xml = re.sub(r'<poem>(.*?)</poem>', verse, xml, flags=re.S)
    lines = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', l)).split()) if '\t' not in l else l for l in xml.split(LINE)]
    return '\n'.join(l for l in lines if l.strip())


def title_of(heading, sub):
    """Mushar's «آثار الاحزان (رياض البكاء): » → «آثار الاحزان (رياض البكاء)»; Sarkis's book «1 - خلاصة القول في تحليل
    البول - بولاق 1312 ص 6-26» → «خلاصة القول في تحليل البول», and his author «ابراهيم (بك) مرزوق (1233-1283) ( 1 )»
    without the footnote mark."""
    heading = re.sub(r'\s*\( \d+ \)', '', heading).strip()
    if sub:
        heading = re.sub(r'^\d+\s*-\s*', '', heading)
        heading = re.split(r'\s-\s', heading, maxsplit=1)[0]
    return heading.strip(' .:،-')


def body_items(db, doc):
    """The main-text paragraphs of the longest run of rising column numbers, each with its column, and the footnotes
    of those columns."""
    rows = db.execute('SELECT story_id, text_xml FROM book_texts WHERE document_id = ? ORDER BY item_id', (doc,)).fetchall()
    runs, run, previous = [], [], None
    notes, note_page = {}, None
    for story, xml in rows:
        if story != 1:
            m = PAGE.search(xml)
            note_page = m.group(1) if m else note_page
            t = text_of(PAGE.sub('', xml))
            if note_page and t:
                notes.setdefault(note_page, []).append(t)
            continue
        for i, part in enumerate(PAGE.split(xml)):
            if i % 2:
                n = int(part) if part.lstrip('-').isdigit() else None
                if n is not None and previous is not None and n <= previous:
                    runs.append(run)
                    run = []
                previous = n if n is not None else previous
                run.append(('page', part, False))
            else:
                # a part after a column number within one paragraph runs on from the column before
                run.append(('text', part, i > 0))
    runs.append(run)
    best = max(runs, key=lambda r: sum(1 for kind, _, _ in r if kind == 'page'))
    label, out = None, []
    for kind, value, continued in best:
        if kind == 'page':
            label = int(value) if value.lstrip('-').isdigit() and int(value) > 0 else None
            continue
        if label is not None:
            out.append((label, value, continued))
    return out, {int(p): t for p, t in notes.items() if p.isdigit()}


def volume(db, series, number, entry_levels, sub_levels, persian):
    fix = (lambda s: s.translate(PERSIAN)) if persian else (lambda s: s)
    items, notes = body_items(db, f'{series}_{number:03d}')
    pages, order = {}, []
    entries = []
    for label, part, runs_on in items:
        if label not in pages:
            pages[label] = []
            order.append(label)
        # a fragment may hold several paragraphs; each heading opens its own line
        for chunk in re.split(r'(?=<heading )|</p>\s*<p>', part):
            t = fix(text_of(chunk))
            if not t:
                continue
            head = HEADING.search(chunk)
            level = int(head.group(1)) if head else None
            heading = fix(text_of(head.group(2), keep=False)) if head else None
            # the entry's own text without Noor's marks, which only the page keeps
            plain = fix(text_of(chunk, keep=False))
            if level in entry_levels:
                entries.append({'level': level, 'heading': heading, 'page': label, 'lines': [plain], 'breaks': []})
                pages[label].append('')  # a blank line before each entry
                pages[label].append(t)
                continue
            if level is not None and level < min(entry_levels):
                entries.append(None)  # a letter's heading ends the entry before it
                pages[label].append('')
            elif entries and entries[-1] is not None:
                e = entries[-1]
                if label != (e['breaks'][-1][1] if e['breaks'] else e['page']):
                    # where the entry's text turns to the next column, as an offset into its text
                    e['breaks'].append((len('\n'.join(e['lines'])) + 1, label))
                if runs_on and len(e['lines']) > 1:
                    # a paragraph that runs on over a column turn goes on in the same line
                    e['lines'][-1] += ' ' + plain
                else:
                    e['lines'].append(plain)
            pages[label].append(t)
            runs_on = False

    # the body: from the first column an entry starts on to the last one an entry reaches, without the title pages
    # a volume repeats from the end of the one before
    first = min((e['page'] for e in entries if e), default=0)
    last = max((int(e['breaks'][-1][1]) if e['breaks'] else e['page'] for e in entries if e), default=0)
    out_pages = []
    for label in order:
        if not first <= label <= last:
            continue
        lines = pages[label] + [''] + [fix(n) for n in notes[label]] if notes.get(label) else pages[label]
        text = re.sub(r'\n{3,}', '\n\n', '\n'.join(lines)).strip()
        if text:
            out_pages.append({'page': label, 'text': text})

    out_entries, per_page, parent = [], {}, None
    for e in entries:
        if e is None:
            parent = None
            continue
        page = e['page']
        per_page[page] = per_page.get(page, 0) + 1
        key = f'{page}#{per_page[page]}'
        sub = e['level'] in sub_levels and parent is not None
        if not sub:
            parent = key
        out_entries.append({
            'key': key, 'kind': 'sub_entry' if sub else 'entry', 'title': title_of(e['heading'], sub),
            'page_start': page, 'page_end': int(e['breaks'][-1][1]) if e['breaks'] else page, 'text': '\n'.join(e['lines']),
            'page_breaks': [[at, int(p)] for at, p in e['breaks']], 'parent_key': parent if sub else None,
        })
    return out_pages, out_entries


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('db')
    ap.add_argument('out')
    ap.add_argument('--sources', default=','.join(SOURCES))
    args = ap.parse_args()
    db = sqlite3.connect(f'file:{args.db}?mode=ro', uri=True)
    for code in args.sources.split(','):
        series, volumes, entry_levels, sub_levels, persian = SOURCES[code]
        out = Path(args.out) / code
        out.mkdir(parents=True, exist_ok=True)
        for number in range(1, volumes + 1):
            pages, entries = volume(db, series, number, entry_levels, sub_levels, persian)
            for name, rows in ((f'{number:02d}.jsonl.gz', pages), (f'{number:02d}.entries.jsonl.gz', entries)):
                with gzip.open(out / name, 'wt', encoding='utf-8') as f:
                    for r in rows:
                        f.write(json.dumps(r, ensure_ascii=False) + '\n')
            first, last = pages[0]['page'], pages[-1]['page']
            have = {p['page'] for p in pages}
            missing = [n for n in range(first, last + 1) if n not in have]
            print(f'{code} v{number}: {len(pages)} columns {first}-{last}, {len(missing)} without text {missing[:20]}, '
                  f'{len(entries)} entries ({sum(e["kind"] == "sub_entry" for e in entries)} sub), '
                  f'{sum(1 for e in entries if e["page_end"] > e["page_start"])} over a column turn')


if __name__ == '__main__':
    main()
