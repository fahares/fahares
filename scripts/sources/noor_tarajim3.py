#!/usr/bin/env python3
"""Page texts and entries of Kahhala's «معجم المؤلفین» (item 86, step 14.31) and Zirikli's «الأعلام» (step 14.48) from
Noor's old «تراجم و کتاب‌شناسی ۳», for
sources:import-pages and sources:segment --entries.

    python3 -I scripts/sources/noor_tarajim3.py TARAJIM3_DB OUT_DIR [--sources kahhala,zirikli]

Writes OUT_DIR/{code}/{volume:02d}.jsonl.gz with {"page", "text"} per printed page and OUT_DIR/{code}/{volume:02d}.entries.jsonl.gz
with the volume's entries. The database is opened read-only.

The old program's text (table pages: book_id, volume, page_no, text_plain, footnotes_plain) was decoded from its own
font, page by page; page_no is the printed page. It marks no heading, but Kahhala prints each life as a paragraph of
one line, the person's short name, «ابراهيم النووي», and the next one opens with his dates, «815- 855 ه 1412- 1480 م
ابراهيم بن ابراهيم …», «...- 1231 ه», «000- 660 ه» or «كان حيا 1319 ه»: that pair begins an entry. Noor's edition is
Dar Ihya' al-Turath's offset of the Damascus print whose scans the site has, but its first volume is two pages of front
matter, so Noor's volume N+1 is the print's part N (parts 1-13; 15-16 are indexes).

Footnotes, «(1) تقريبا», go at the end of their page; the text marks them «[1]», which becomes «( 1 )» as in the other
readers. A Latin passage the decoder could not read is «[…]» (unreadable()). An entry's key is its first page and its place among the entries that start there, «3#2».
"""
import argparse
import gzip
import json
import re
import sqlite3
from pathlib import Path

# code: Noor book id, {the site's volume: Noor's volume}
SOURCES = {
    'kahhala': (271, {n: n + 1 for n in range(1, 14)}),
    'zirikli': (257, {n: n for n in range(1, 9)}),
}

# the line after a name: a life's dates
DATES = re.compile(r'^(?:\.\.\.|0{3}|\d{1,4}|كان حيا|نحو|حوالي|في حدود|بعد|قبل)[^\n]{0,40}? ه')

# Letters the old program's decoder gives a Latin passage it cannot read (Brockelmann's GAL among a life's sources): a
# run of them, «بن في 8 ؤ؛ ءء ااٌّآًّأإ1 بن», stands for that passage, which the page shows as «[…]»
# a token of these alone, or with «؛» or «ءء» in it; a word with a shadda, «بقيّة», has other letters too
ODD = re.compile(r'[ؤ؛ءپآأإا\u064B-\u0652\d]+')


def unreadable(text):
    """The text with each such run as «[…]»: the tokens around a «ءء» or «ااٌّ…» that are odd letters, digits, «بن» or «في»,
    without the numbers that open it, which are the page of the citation it follows."""
    parts = re.split(r'(\s+)', text)
    words = [i for i, p in enumerate(parts) if p and not p.isspace()]
    odd = lambda p: bool(ODD.fullmatch(p)) or '؛' in p or 'ءء' in p or p in ('بن', 'في')
    k = 0
    while k < len(words):
        # the run's anchor: «ءء», or the «ااٌّآًّأإ1» it often ends with
        if 'ءء' not in parts[words[k]] and not re.match(r'اا[\u064B-\u0652]', parts[words[k]]):
            k += 1
            continue
        a = b = k
        while a > 0 and odd(parts[words[a - 1]]):
            a -= 1
        while b + 1 < len(words) and odd(parts[words[b + 1]]):
            b += 1
        while a < k and re.fullmatch(r'\d+', parts[words[a]]):
            a += 1
        parts[words[a]] = '[…]'
        for i in range(words[a] + 1, words[b] + 1):
            parts[i] = ''
        k = b + 1
    return unreadable_lines(''.join(parts))


# a line with no plain word: three letters or more, two of them other than the alefs and hamzas the odd runs are made of
# («جابر», «حيّان»); «ؤؤٌٍّّإ», «إٍّئ؛ : : : ئٌّإپٌّ» and «اب» have none
def plain(line):
    return any(len(w) >= 3 and len(re.findall(r'[\u0628-\u064A]', w)) >= 2
               for w in re.findall(r'[\u0627-\u064A]+', re.sub(r'[\u064B-\u0652]', '', line)))


def unreadable_lines(text):
    """A longer passage the decoder could not read is lines of odd letters, with short and blank lines among them: from
    the first such line to the last, one line «[…]»; the blank line after it stays, before the next life's name."""
    lines = text.split('\n')
    bad = [bool(re.search(r'[ؤ؛ءپً-ْ]', l)) and not plain(l) and '[…]' not in l for l in lines]
    filler = lambda l: len(l.strip()) <= 3 or not plain(l)
    out, i = [], 0
    while i < len(lines):
        j = i
        while j + 1 < len(lines) and bad[i] and (bad[j + 1] or filler(lines[j + 1])):
            j += 1
        while j > i and not bad[j]:
            j -= 1
        if bad[i] and j > i:
            out.append('[…]')
            i = j + 1
        else:
            out.append(lines[i])
            i += 1
    return '\n'.join(out)


def page_lines(db, book, volume):
    """(page, lines, footnotes) of the volume in page order."""
    out = []
    for page, text, notes in db.execute('SELECT page_no, group_concat(text_plain, "\n"), group_concat(footnotes_plain, "\n") '
                                        'FROM (SELECT * FROM pages WHERE book_id = ? AND volume = ? ORDER BY page_no, seq) '
                                        'GROUP BY page_no ORDER BY page_no', (book, volume)):
        text = re.sub(r'\[(\d+)\]', r' ( \1 )', unreadable(text or ''))
        out.append((page, [' '.join(l.split()) for l in text.split('\n')], unreadable(notes or '').strip()))
    return out


def volume(db, book, number):
    pages, entries = [], []
    for page, lines, notes in page_lines(db, book, number):
        pages.append({'page': page, 'text': '\n'.join(lines).strip() + ('\n\n' + notes if notes else '')})
        # a paragraph of one line whose next line holds dates begins a life
        for i, line in enumerate(lines):
            opens = (i == 0 or lines[i - 1] == '') and line and 1 < len(line) <= 80 and i + 1 < len(lines) and DATES.match(lines[i + 1])
            if opens:
                entries.append({'heading': line, 'page': page, 'lines': [line], 'breaks': []})
            elif entries and line:
                e = entries[-1]
                if page != (e['breaks'][-1][1] if e['breaks'] else e['page']):
                    # where the entry's text turns to the next page, as an offset into its text
                    e['breaks'].append((len('\n'.join(e['lines'])) + 1, page))
                e['lines'].append(line)

    out_entries, per_page = [], {}
    for e in entries:
        page = e['page']
        per_page[page] = per_page.get(page, 0) + 1
        out_entries.append({
            'key': f'{page}#{per_page[page]}', 'kind': 'entry', 'title': e['heading'].strip(' .:،'),
            'page_start': page, 'page_end': e['breaks'][-1][1] if e['breaks'] else page, 'text': '\n'.join(e['lines']),
            'page_breaks': [[at, p] for at, p in e['breaks']], 'parent_key': None,
        })
    return pages, out_entries


# Zirikli (step 14.48): a life opens with its subject's short name and his dates, Hijri then Common Era, and his full
# name to a colon: «الغَرْناطي 495- 579 ه 1102- 1183 م إبراهيم بن أحمد بن عبد الرحمن …، أبو إسحاق: قاض أندلسي»,
# «الإيجي ...- نحو 700 ه ...- نحو 1300 م …», «السَّمَوْأَل ...- نحو 65 ق ه ...- نحو 560 م …», and ends with the mark of
# its footnote, the list of its sources, on a line of its own and a full stop after it. Between two lives, on the
# line of the next one, come references to lives elsewhere, a name and a year each, «السِّمْنَاني- محمد بن أحمد 444»:
# the short name starts after the last of them. Noor's volume N is the print's (Dar al-'Ilm li-l-Malayin).
YEAR = r'(?:\.\.\.|(?:نحو|بعد|قبل|حوالي)?\s*\d{1,4})'
PAIR = re.compile(rf'{YEAR}\s*-\s*{YEAR}\s*(?:ق\s*)?ه\s+{YEAR}\s*-\s*{YEAR}\s*(?:ق\s*)?م(?=\s|$)'
                  rf'|كان حيا\s*(?:نحو\s*)?\d{{1,4}}\s*(?:ق\s*)?ه\s+كان حيا\s*(?:نحو\s*)?\d{{1,4}}\s*م(?=\s|$)')
SHORT = re.compile(r'(?:^|\d\s)\s*([^\d\n]{2,80}?)\s*$')
MARK = re.compile(r'^\((\d+)\)$')


def zirikli_volume(db, book, number):
    rows = db.execute('SELECT page_no, group_concat(text_plain, "\n"), group_concat(footnotes_plain, "\n") '
                      'FROM (SELECT * FROM pages WHERE book_id = ? AND volume = ? ORDER BY page_no, seq) '
                      'GROUP BY page_no ORDER BY page_no', (book, number)).fetchall()
    pages, entries, open_ = [], [], False
    for page, text, notes in rows:
        raw = [' '.join(l.split()) for l in unreadable(text or '').split('\n')]
        # «… الشواهق\n(2)\n.» → «… الشواهق ( 2 ).»: the life's footnote mark, and its end
        lines, i = [], 0
        while i < len(raw):
            m = MARK.match(raw[i])
            if m and lines and i + 1 < len(raw) and raw[i + 1] == '.':
                lines[-1] += f' ( {m.group(1)} ).'
                lines.append(None)  # the life ends here
                i += 2
                continue
            lines.append(raw[i])
            i += 1
        notes = re.sub(r'^\((\d+)\)\n', r'( \1 ) ', unreadable(notes or '').strip(), flags=re.M)
        pages.append({'page': page, 'text': '\n'.join(l for l in lines if l is not None).strip() + ('\n\n' + notes if notes else '')})
        for line in lines:
            if line is None:
                open_ = False
                continue
            m = PAIR.search(line)
            short = SHORT.search(line[:m.start()]) if m else None
            if m and short:
                after = line[m.end():].strip()
                full = after.split(':', 1)[0].strip() if ':' in after[:250] else after.split('،', 1)[0].strip()
                title = (full if 3 <= len(full) <= 150 else short.group(1)).strip(' .،')
                entries.append({'heading': title, 'page': page, 'lines': [line[short.start(1):]], 'breaks': []})
                open_ = True
            elif open_ and line:
                e = entries[-1]
                if page != (e['breaks'][-1][1] if e['breaks'] else e['page']):
                    e['breaks'].append((len('\n'.join(e['lines'])) + 1, page))
                e['lines'].append(line)

    out, per_page = [], {}
    for e in entries:
        page = e['page']
        per_page[page] = per_page.get(page, 0) + 1
        out.append({
            'key': f'{page}#{per_page[page]}', 'kind': 'entry', 'title': e['heading'],
            'page_start': page, 'page_end': e['breaks'][-1][1] if e['breaks'] else page, 'text': '\n'.join(e['lines']),
            'page_breaks': [[at, p] for at, p in e['breaks']], 'parent_key': None,
        })
    return pages, out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('db')
    ap.add_argument('out')
    ap.add_argument('--sources', default=','.join(SOURCES))
    args = ap.parse_args()
    db = sqlite3.connect(f'file:{args.db}?mode=ro', uri=True)
    for code in args.sources.split(','):
        book, volumes = SOURCES[code]
        out = Path(args.out) / code
        out.mkdir(parents=True, exist_ok=True)
        for number, noor in volumes.items():
            pages, entries = (zirikli_volume if code == 'zirikli' else volume)(db, book, noor)
            for name, rows in ((f'{number:02d}.jsonl.gz', pages), (f'{number:02d}.entries.jsonl.gz', entries)):
                with gzip.open(out / name, 'wt', encoding='utf-8') as f:
                    for r in rows:
                        f.write(json.dumps(r, ensure_ascii=False) + '\n')
            have = {p['page'] for p in pages}
            missing = [n for n in range(pages[0]['page'], pages[-1]['page'] + 1) if n not in have]
            print(f'{code} v{number} (noor {noor}): {len(pages)} pages {pages[0]["page"]}-{pages[-1]["page"]}, '
                  f'{len(missing)} without text {missing[:10]}, {len(entries)} entries, '
                  f'{sum(1 for e in entries if e["page_end"] > e["page_start"])} over a page turn')


if __name__ == '__main__':
    main()
