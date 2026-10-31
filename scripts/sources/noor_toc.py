#!/usr/bin/env python3
"""The tables of contents of the sources the site has from Noor, for a source's page (step 13.84).

    python3 -I scripts/sources/noor_toc.py KOTOBMARJA2_DB AQABOZORG_DB OUT_JSON

Writes {code: {volume: [{"title", "page", "level"}]}} for Monzavi (series 11578), Kashf (03298), Idah (03289),
Hadiyya (03309) and al-Dhari'a (03264). Both databases are opened read-only.

Noor's book_toc holds each document's headings: level 1 the volume, level 2 its parts (a «بخش», a letter's «باب», the
prefaces and indexes), level 3 what is under them, down to the entries. A part's own headings are kept only when they
are its letters («آ», «ب» under a «بخش» of Monzavi), not its entries (a «باب» of Kashf) or the list of abbreviations;
a heading that is an entry's number (al-Dhari'a's volume 26, «1] (آب حيات…)») is left out. A heading's page is the
printed page (book_pages.page_number) whose break comes last at or before it (book_page_breaks).
"""
import argparse
import json
import re
import sqlite3
from pathlib import Path

SOURCES = {
    'monzavi': ('kotobmarja2', '11578', 10),
    'kashf': ('kotobmarja2', '03298', 2),
    'idah': ('kotobmarja2', '03289', 2),
    'hadiyya': ('kotobmarja2', '03309', 2),
    'dharia': ('aqabozorg', '03264', 26),
}
# a part with more headings under it than this, or longer ones, lists its entries, not its letters
MAX_CHILDREN = 40
LETTER = re.compile(r'^\W*«?[^\W\d_]{1,3}»?\W*$')
AR = str.maketrans({'ي': 'ی', 'ى': 'ی', 'ك': 'ک'})


def clean(title: str, persian: bool) -> str:
    t = re.sub(r'^[\s\[\-–]+|[\s\]\-–:]+$', '', title)
    t = re.sub(r'\s+', ' ', t)
    return t.translate(AR) if persian else t


def volume(db: sqlite3.Connection, doc: str, persian: bool) -> list[dict]:
    pages = dict(db.execute('SELECT page_id, page_number FROM book_pages WHERE document_id = ?', (doc,)).fetchall())
    breaks = db.execute('SELECT doc_item_id, start_offset, page_id FROM book_page_breaks WHERE document_id = ? ORDER BY doc_item_id, start_offset',
                        (doc,)).fetchall()
    rows = db.execute('SELECT toc_item_id, title, parent_id, level_no, doc_item_id, start_offset FROM book_toc WHERE document_id = ? AND level_no >= 2 '
                      'ORDER BY toc_item_id', (doc,)).fetchall()
    children: dict[int, list[str]] = {}
    for _, title, parent, level, _, _ in rows:
        if level == 3:
            children.setdefault(parent, []).append(title)
    letters = {p for p, titles in children.items() if len(titles) <= MAX_CHILDREN and all(LETTER.match(t.strip()) for t in titles)}
    level2 = {r[0] for r in rows if r[3] == 2}

    out, i, page_id = [], 0, None
    for toc_id, title, parent, level, item, offset in rows:
        if level > 3 or (level == 3 and (parent not in level2 or parent not in letters)) or item is None or re.match(r'^\W*\d', title):
            continue
        # the last page break at or before the heading
        while i < len(breaks) and (breaks[i][0], breaks[i][1]) <= (item, offset or 0):
            page_id = breaks[i][2]
            i += 1
        label = str(pages.get(page_id, '')).strip()
        if not label.isdigit():
            continue
        out.append({'title': clean(title, persian), 'page': int(label), 'level': level - 1})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('kotobmarja2')
    ap.add_argument('aqabozorg')
    ap.add_argument('out')
    args = ap.parse_args()
    dbs = {name: sqlite3.connect(f'file:{getattr(args, name)}?mode=ro', uri=True) for name in ('kotobmarja2', 'aqabozorg')}
    result = {}
    for code, (db, series, count) in SOURCES.items():
        result[code] = {}
        for v in range(1, count + 1):
            result[code][v] = volume(dbs[db], f'{series}_{v:03d}', persian=code == 'monzavi')
        print(code, {v: len(t) for v, t in result[code].items()})
    Path(args.out).write_text(json.dumps(result, ensure_ascii=False, indent=1))


if __name__ == '__main__':
    main()
