#!/usr/bin/env python3
"""Column texts and entries of Kashf al-Zunun, Idah al-Maknun and Hadiyyat al-Arifin from Noor's «کتب مرجع ۲»
database (item 86), for sources:import-pages and sources:segment --entries.

    python3 -I scripts/sources/noor_istanbul.py KOTOBMARJA2_DB OUT_DIR [--sources kashf,idah,hadiyya]

Writes OUT_DIR/{code}/{volume:02d}.jsonl.gz with {"page", "text"} per printed column and
OUT_DIR/{code}/{volume:02d}.entries.jsonl.gz with the volume's entries. The database is opened read-only.

Noor's edition is Dar Ihya' al-Turath's offset of the Istanbul print (series 03298, 03289 and 03309), the one whose
column scans the site has; <page number="N"/> starts column N. A document also holds front matter (the publisher's
preface, the life of Katib Chelebi) or an appendix numbered apart, so only the longest run of rising column numbers
is read, within the volume's columns (Kashf runs on from 1-940 to 945-2056).

Noor marks each entry's heading (level 3), a letter's chapter (level 2), and in Kashf's second volume the works
under a heading (level 5) and some sub-headings (level 4). A heading in brackets or between dashes («- الكتب
المصنفة فيه -», «[باب التاء]») is a section label, not an entry. A heading «و حاشية» or «و شرح» is a gloss on the
entry it is printed under, and is titled after it, «حاشية انوار التنزيل». In Hadiyya an entry is an author, headed by his
nisba («الطبرى»), with his works after «له»; its level 4 headings group Ibn Sina's works and stay in his entry.

Footnotes go at the end of their column as «( 1 ) …». The Turkish notes of the Istanbul editors are in Latin letters
Noor's OCR broke («Bu eser basmalacda sehiv eserl olarak»), so a footnote with any of them is left out.

An entry's key is its first column and its place among the entries that start there, «500#2».
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
LATIN = re.compile(r'class="changeLang"')

# code: (series, {volume: (first column, last column)}, heading levels that are entries, levels that are sub-entries)
SOURCES = {
    'kashf': ('03298', {1: (1, 940), 2: (945, 2056)}, {3, 4, 5}, {5}),
    'idah': ('03289', {1: (1, 622), 2: (1, 732)}, {3}, set()),
    'hadiyya': ('03309', {1: (1, 842), 2: (1, 574)}, {3}, set()),
}


LINE = '\ue010'  # a line break the text keeps: before and after a verse that opens a line


# Noor's marks kept in the page text (step 13.92), each span as OPEN + kind + SEP … CLOSE: a Quran verse
# («ayah 18:46», «ayah 2:1-5»), a hadith («hadith»), a passage in Latin letters («latin»). A heading keeps none,
# so an entry is still found by its first line. App\Support\PrintedPage::text() reads them.
OPEN, SEP, CLOSE = '', '', ''
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
    """Noor's <poem>: a verse on a line of its own, its hemistichs apart by a tab (Noor's <gap/>, the « - » printed
    between them dropped; one hemistich keeps the tab after it), when it opens a line; a verse quoted inside a sentence
    stays in it."""
    before = re.sub(r'(?:<(?!br|/?p\b|/poem)[^>]*>|\s)+$', '', m.string[:m.start()])
    hemistichs = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', h)).split()).strip(' -–') for h in m.group(1).split('<gap/>')]
    if before and not re.search(r'(<br/?>|<p[^>]*>|</poem>)$', before):
        return ' ' + ' '.join(hemistichs) + ' '
    return LINE + '\t'.join(hemistichs) + ('\t' if len(hemistichs) == 1 else '') + LINE


def text_of(xml, keep=True):
    """The text on one line, a verse of poetry that opens a line (<poem>) on a line of its own; with keep, Noor's marks
    too (marks()), which the page keeps and an entry does not."""
    if keep:
        xml = marks(xml)
    xml = re.sub(r'<lfootnote[^>]*>\s*(\d+)\s*</lfootnote>', r' ( \1 ) ', xml)
    xml = re.sub(r'<mnote>\s*\((\d+)\)\s*</mnote>', r'( \1 ) ', xml)
    xml = re.sub(r'<poem>(.*?)</poem>', verse, xml, flags=re.S)
    lines = [' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', l)).split()) if '\t' not in l else l for l in xml.split(LINE)]
    return '\n'.join(l for l in lines if l.strip())


def is_label(heading):
    """«- الكتب المصنفة فيه -», «[باب التاء]», ««طبعاته»»: a section label, not a work."""
    return bool(re.match(r'^[-\[«(]', heading)) or heading.startswith('باب ') or heading in ('و اما', 'و من الكتب المؤلفة') or bool(re.match(r'^الكتب (المؤلفة|المصنفة) فيه', heading))


# «و حاشية», «و شرح»: a commentary on the book whose entry it is in, printed as a heading of its own
GLOSS = re.compile(r'^و? ?(حاشية|شرح|تعليقة|تنبيهات)$')


def title_of(heading):
    """«عشق نامه - لبلاطى افندى.» → «عشق نامه»; «عقود الجمان ( 1 ) فى تجويد القرآن» without the footnote mark."""
    heading = re.sub(r'\s*\( \d+ \)', '', heading)
    return re.split(r'\s-\s', heading, maxsplit=1)[0].strip(' .:،-')


def body_items(db, doc, first, last):
    """The main-text paragraphs of the longest run of rising column numbers, each with its column, and the
    footnotes of those columns."""
    rows = db.execute('SELECT story_id, text_xml FROM book_texts WHERE document_id = ? ORDER BY item_id', (doc,)).fetchall()
    runs, run, previous = [], [], None
    notes, note_page = {}, None
    for story, xml in rows:
        if story != 1:
            if story == 2:
                m = PAGE.search(xml)
                note_page = m.group(1) if m else note_page
                if note_page and not LATIN.search(xml):
                    t = text_of(PAGE.sub('', xml))
                    if t:
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
            label = int(value) if value.lstrip('-').isdigit() else None
            continue
        if label is not None and first <= label <= last:
            out.append((label, value, continued))
    return out, {int(p): t for p, t in notes.items() if p.isdigit() and first <= int(p) <= last}


def volume(db, series, number, first, last, entry_levels, sub_levels):
    items, notes = body_items(db, f'{series}_{number:03d}', first, last)
    pages, order = {}, []
    entries = []
    for label, part, runs_on in items:
        if label not in pages:
            pages[label] = []
            order.append(label)
        # a fragment may hold several paragraphs; each heading opens its own line
        for chunk in re.split(r'(?=<heading )|</p>\s*<p>', part):
            t = text_of(chunk)
            if not t:
                continue
            head = HEADING.search(chunk)
            level = int(head.group(1)) if head else None
            heading = text_of(head.group(2), keep=False) if head else None
            # the entry's own text without Noor's marks, which only the page keeps
            plain = text_of(chunk, keep=False)
            if level in entry_levels and not is_label(heading):
                entries.append({'level': level, 'heading': heading, 'page': label, 'lines': [heading], 'breaks': []})
                pages[label].append('')  # a blank line before each entry
                pages[label].append(t)
                runs_on = False
                continue
            if level is not None and level <= 2:
                entries.append(None)  # a chapter heading ends the entry before it
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

    out_pages = []
    for label in order:
        lines = pages[label] + [''] + notes[label] if notes.get(label) else pages[label]
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
        gloss = GLOSS.match(title_of(e['heading']))
        sub = (e['level'] in sub_levels or gloss) and parent is not None
        if not sub:
            parent, parent_title = key, title_of(e['heading'])
        out_entries.append({
            'key': key, 'kind': 'sub_entry' if sub else 'entry',
            'title': f'{gloss.group(1)} {parent_title}' if gloss and sub else title_of(e['heading']),
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
        series, volumes, entry_levels, sub_levels = SOURCES[code]
        out = Path(args.out) / code
        out.mkdir(parents=True, exist_ok=True)
        for number, (first, last) in volumes.items():
            pages, entries = volume(db, series, number, first, last, entry_levels, sub_levels)
            for name, rows in ((f'{number:02d}.jsonl.gz', pages), (f'{number:02d}.entries.jsonl.gz', entries)):
                with gzip.open(out / name, 'wt', encoding='utf-8') as f:
                    for r in rows:
                        f.write(json.dumps(r, ensure_ascii=False) + '\n')
            have = {p['page'] for p in pages}
            missing = [n for n in range(first, last + 1) if n not in have]
            print(f'{code} v{number}: {len(pages)} columns {pages[0]["page"]}-{pages[-1]["page"]}, '
                  f'{len(missing)} without text {missing[:20]}, {len(entries)} entries '
                  f'({sum(e["kind"] == "sub_entry" for e in entries)} sub), '
                  f'{sum(1 for e in entries if e["page_end"] > e["page_start"])} over a column turn')


if __name__ == '__main__':
    main()
