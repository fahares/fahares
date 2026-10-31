#!/usr/bin/env python3
"""Page texts and entries of Monzavi's «فهرستواره کتاب‌های فارسی» from Noor's «کتب مرجع ۲» database (item 86).

    python3 -I scripts/sources/noor_monzavi.py KOTOBMARJA2_DB OUT_DIR [--volumes 1,2]

Writes, for each volume, OUT_DIR/{volume:02d}.jsonl.gz with {"page", "text"} per printed page, for
sources:import-pages, and OUT_DIR/{volume:02d}.entries.jsonl.gz with the volume's printed entries, for
sources:segment --entries. The database is opened read-only.

Noor's edition is the Center for the Great Islamic Encyclopaedia's (series 11578, ten volumes). Its pages run on
across volumes 1-5 (1-4173) and 7-8 (1-1498); volumes 6, 9 and 10 start again at 1. Each volume opens with its own
front matter (title page, prefaces and the list of abbreviations, numbered apart from the body) and ends with
indexes; only the parts («بخش») in between hold entries. Noor marks each entry's heading (level 4; level 5 for the
few sub-entries of volume 8 and the end of volume 1), and a page's start with <page number="N"/>.

An entry is printed as a heading, «*یادداشت‌ها.» or «سفرنامۀ مکه. امین الدوله،», then its paragraphs: the work, its
author, date and parts, its incipit, and the bibliographies and catalogues it is found in. The entry's title is the
heading up to its first «.», «:» or «،», without the asterisk. Noor writes Arabic ي, ى and ك; the print is Persian,
so they become ی and ک.

An entry's key is its first page and its place among the entries that start there, «3366#2».
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
# the parts that hold entries: «بخش يكم سفرنامه», «[بخش ششم: تاريخ ايران ]», «عرفان - بخش دوّم»
BODY = re.compile(r'بخش')
FRONT_OR_BACK = re.compile(r'شناسنامه|مقدمات|اختصارات|نمايه|نمایه|نام سرايندگان|نام سرایندگان|سخن مؤلف')
LINE = '\ue010'  # a line break the text keeps: before and after a verse that opens a line


def verse(m):
    """Noor's <poem>: a verse on a line of its own, its hemistichs apart by a tab (Noor's <gap/>, the « - » printed
    between them dropped; one hemistich keeps the tab after it), when it opens a line; a verse quoted inside a sentence
    stays in it."""
    before = re.sub(r'(?:<(?!br|/?p\b|/poem)[^>]*>|\s)+$', '', m.string[:m.start()])
    hemistichs = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', h)).split()).strip(' -–') for h in m.group(1).split('<gap/>')]
    if before and not re.search(r'(<br/?>|<p[^>]*>|</poem>)$', before):
        return ' ' + ' '.join(hemistichs) + ' '
    return LINE + '\t'.join(hemistichs) + ('\t' if len(hemistichs) == 1 else '') + LINE


def text_of(xml):
    """The text on one line, a verse of poetry that opens a line (<poem>) on a line of its own."""
    xml = re.sub(r'<poem>(.*?)</poem>', verse, xml, flags=re.S)
    lines = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', l)).split()) if '\t' not in l else l for l in xml.split(LINE)]
    return '\n'.join(l for l in lines if l.strip()).translate(PERSIAN)


def title_of(heading):
    """«*يادداشت‌ها.» → «یادداشت‌ها»; «مقداريه: رسالۀ...، لطف الله» → «مقداریه»."""
    h = heading.lstrip('* ').strip()
    return re.split(r'\s*[.:،,]\s|\s*[.:،,]$|\s←', h + ' ', maxsplit=1)[0].strip(' .:،,')


def volume(db, number):
    doc = f'11578_{number:03d}'
    rows = db.execute('SELECT story_id, text_xml FROM book_texts WHERE document_id = ? ORDER BY item_id', (doc,)).fetchall()
    pages, order = {}, []
    notes = {}
    entries = []
    label = None
    in_body = False
    for story, xml in rows:
        if story != 1:
            # a footnote, at the end of its page
            m = PAGE.search(xml)
            t = text_of(PAGE.sub('', xml))
            if m and t:
                notes.setdefault(m.group(1), []).append(t)
            continue
        for i, part in enumerate(PAGE.split(xml)):
            # a paragraph that runs on over a page turn goes on in the same line of its entry
            runs_on = i > 0
            if i % 2:
                label = part
                if label not in pages:
                    pages[label] = []
                    order.append(label)
                continue
            if label is None:
                continue
            t = text_of(part)
            if not t:
                continue
            head = HEADING.search(part)
            level = int(head.group(1)) if head else None
            if level is not None and level <= 2:
                in_body = bool(BODY.search(t)) and not FRONT_OR_BACK.search(t)
            if level in (4, 5) and in_body:
                entries.append({'level': level, 'heading': t, 'page': label, 'lines': [t], 'breaks': []})
                pages[label].append('')  # a blank line before each entry
            elif level is not None and level <= 3:
                entries.append(None)  # a part or letter heading ends the entry before it
                pages[label].append('')
            elif entries and entries[-1] is not None:
                e = entries[-1]
                if label != (e['breaks'][-1][1] if e['breaks'] else e['page']):
                    # where the entry's text turns to the next page, as an offset into its text
                    e['breaks'].append((len('\n'.join(e['lines'])) + 1, label))
                if runs_on and len(e['lines']) > 1:
                    e['lines'][-1] += ' ' + t
                else:
                    e['lines'].append(t)
            pages[label].append(t)
    out_pages = []
    for label in order:
        if not label.lstrip('-').isdigit() or int(label) < 1:
            continue
        lines = pages[label] + [''] + notes.get(label, []) if notes.get(label) else pages[label]
        text = re.sub(r'\n{3,}', '\n\n', '\n'.join(lines)).strip()
        out_pages.append({'page': int(label), 'text': text})

    out_entries, per_page, parent = [], {}, None
    for e in entries:
        if e is None:
            parent = None
            continue
        page = int(e['page'])
        per_page[page] = per_page.get(page, 0) + 1
        key = f'{page}#{per_page[page]}'
        last = int(e['breaks'][-1][1]) if e['breaks'] else page
        sub = e['level'] == 5 and parent is not None
        if not sub:
            parent = key
        out_entries.append({
            'key': key, 'kind': 'sub_entry' if sub else 'entry', 'title': title_of(e['heading']), 'heading': e['heading'],
            'page_start': page, 'page_end': last, 'text': '\n'.join(e['lines']),
            'page_breaks': [[at, int(p)] for at, p in e['breaks']], 'parent_key': parent if sub else None,
        })
    return out_pages, out_entries


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('db')
    ap.add_argument('out')
    ap.add_argument('--volumes', default=','.join(map(str, range(1, 11))))
    args = ap.parse_args()
    db = sqlite3.connect(f'file:{args.db}?mode=ro', uri=True)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for number in map(int, args.volumes.split(',')):
        pages, entries = volume(db, number)
        for name, rows in ((f'{number:02d}.jsonl.gz', pages), (f'{number:02d}.entries.jsonl.gz', entries)):
            with gzip.open(out / name, 'wt', encoding='utf-8') as f:
                for r in rows:
                    f.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'v{number}: {len(pages)} pages {pages[0]["page"]}-{pages[-1]["page"]}, {len(entries)} entries '
              f'({sum(e["kind"] == "sub_entry" for e in entries)} sub), '
              f'{sum(1 for e in entries if e["page_end"] > e["page_start"])} over a page turn')


if __name__ == '__main__':
    main()
