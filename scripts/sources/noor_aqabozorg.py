#!/usr/bin/env python3
"""Page texts of al-Dhari'a from Noor's «آثار آقابزرگ تهرانی» database (item 81), for sources:import-pages.

    python3 -I scripts/sources/noor_aqabozorg.py AQABOZORG_DB OUT_DIR [--ablibrary DIR] [--volumes 3,9,25]

Writes OUT_DIR/{volume:02d}.jsonl.gz with {"page", "label", "text", "origin"} per printed page, in the layout the
ablibrary pages have, so sources:segment reads both alike: paragraphs apart by a blank line, an entry heading as
«( 1442 : التحفة )», and the page's footnotes as closing paragraphs «( 1 ) …».

Noor's text is edited, not a copy of the page: it adds headings of its own in brackets («[بيان]», «[تحفه الأبرار]»)
and page ranges of a section («(البائية الحميرية ص 3 - …)»); both are left out. Noor has the end indexes (vol 9 from
p. 1323, the addenda of vol 25 from p. 312, …) only as scans, so those pages get no text. A page Noor has without
text while ablibrary has one (vol 25 pp. 309-310) takes the ablibrary text when --ablibrary is given, with origin
"ablibrary". The database is opened read-only.
"""
import argparse
import gzip
import html
import json
import re
import sqlite3
from pathlib import Path

PAGE = re.compile(r'<page number="([^"]*)"/>')
HEADING = re.compile(r'<heading level="\d">(.*?)</heading>', re.S)
NUMBERED = re.compile(r'^(\d{1,5})\s*:\s*(.+)$', re.S)


def strip(xml):
    xml = re.sub(r'<lfootnote[^>]*>\s*(\d+)\s*</lfootnote>', r' ( \1 ) ', xml)
    xml = re.sub(r'<mnote>\s*\((\d+)\)\s*</mnote>', r'( \1 ) ', xml)
    xml = re.sub(r'<br/?>|</poem>', '\n', xml)
    return html.unescape(re.sub(r'<(?!page )[^>]+>', '', xml))


def heading(title):
    """Noor's heading in the printed form, or nothing for a heading Noor added."""
    title = ' '.join(strip(title).split())
    if not title or title.startswith('[') or re.search(r'ص \d+\s*-', title):
        return ''
    # the print quotes a title inside a title («" صنائع الأسحار "»), where Noor has parentheses
    title = re.sub(r'\(([^\d()][^()]*)\)', r'" \1 "', title)
    m = NUMBERED.match(title)
    return (f'( {m.group(1)} : {m.group(2)} )' if m else f'( {title} )') + '\n'


def tidy(text):
    paragraphs = []
    for p in re.split(r'\n\s*\n', text):
        lines = [' '.join(line.split()) for line in p.split('\n')]
        p = '\n'.join(line for line in lines if line)
        if p:
            paragraphs.append(p)
    return '\n\n'.join(paragraphs)


def volume_pages(db, volume):
    doc = f'03264_{volume:03d}'
    rows = db.execute('SELECT story_id, text_xml FROM book_texts WHERE document_id = ? ORDER BY item_id', (doc,)).fetchall()
    main = ''.join(x for story, x in rows if story == 1)
    order, body, notes = [], {}, {}
    label = None
    for i, part in enumerate(PAGE.split(main.replace('</p>', '\n\n'))):
        if i % 2:
            label = part
            if label not in body:
                order.append(label)
                body[label] = ''
            continue
        if label is None:
            continue
        body[label] += HEADING.sub(lambda m: '\n' + heading(m.group(1)), part)
    for story, x in rows:
        if story == 1:
            continue
        m = PAGE.search(x)
        if m:
            notes.setdefault(m.group(1), []).append(strip(PAGE.sub('', x)))
    pages = []
    for label in order:
        text = strip(body[label]) + ''.join('\n\n' + n for n in notes.get(label, []))
        pages.append((label, tidy(text)))
    return pages


def ablibrary(directory, volume):
    path = Path(directory) / f'{volume:02d}.jsonl.gz'
    pages = {}
    if path.exists():
        for line in gzip.open(path, 'rt', encoding='utf-8'):
            r = json.loads(line)
            if r.get('page') is not None:
                pages[str(r['page'])] = r['text']
    return pages


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('db')
    ap.add_argument('out')
    ap.add_argument('--ablibrary')
    ap.add_argument('--volumes', default=','.join(map(str, range(1, 27))))
    args = ap.parse_args()
    db = sqlite3.connect(f'file:{args.db}?mode=ro', uri=True)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for volume in map(int, args.volumes.split(',')):
        pages = volume_pages(db, volume)
        ab = ablibrary(args.ablibrary, volume) if args.ablibrary else {}
        rows, seen, filled = [], set(), []
        for label, text in pages:
            if not label.isdigit() or int(label) < 1 or label in seen:
                continue
            seen.add(label)
            origin = 'noor'
            if len(text) < 50 and len(ab.get(label, '')) > 200:
                text, origin = ab[label], 'ablibrary'
                filled.append(label)
            if text:
                rows.append({'page': int(label), 'label': label, 'text': text, 'origin': origin})
        with gzip.open(out / f'{volume:02d}.jsonl.gz', 'wt', encoding='utf-8') as f:
            for r in rows:
                f.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'v{volume}: {len(rows)} pages, {rows[0]["page"]}–{rows[-1]["page"]}; '
              f'from ablibrary: {",".join(filled) or "-"}')


if __name__ == '__main__':
    main()
