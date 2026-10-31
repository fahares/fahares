#!/usr/bin/env python3
"""Page texts of al-Dhari'a from Noor's «آثار آقابزرگ تهرانی» database (item 81), for sources:import-pages.

    python3 -I scripts/sources/noor_aqabozorg.py AQABOZORG_DB OUT_DIR [--ablibrary DIR] [--volumes 3,9,25]

Writes OUT_DIR/{volume:02d}.jsonl.gz with {"page", "label", "text", "origin"} per printed page, in the layout the
ablibrary pages have, so sources:segment reads both alike: paragraphs apart by a blank line, an entry heading as
«( 1442 : التحفة )», and the page's footnotes as closing paragraphs «( 1 ) …».

Noor's text is edited, not a copy of the page: it adds headings of its own in brackets («[بيان]», «[تحفه الأبرار]»)
and page ranges of a section («(البائية الحميرية ص 3 - …)»); both are left out (see heading()). Noor has the end indexes (vol 9 from
p. 1323, the addenda of vol 25 from p. 312, …) only as scans, so those pages get no text. A page Noor has without
text while ablibrary has one (vol 25 pp. 309-310) takes the ablibrary text when --ablibrary is given, with origin
"ablibrary". The database is opened read-only.

Noor shortens «عليه السلام» and «صلى الله عليه وآله» to «ع» and «ص». The print writes them out in some volumes
(1-5, 13, 14) and has «ع» in others, and «ص» is also «page», «ع 1» a month; so no rule can tell. With --ablibrary,
a lone «ع» or «ص» is written out only where the two texts, aligned word by word, have that one abbreviation against
that one phrase in ablibrary (see honorifics()).
"""
import argparse
import difflib
import gzip
import html
import json
import re
import sqlite3
from pathlib import Path

PAGE = re.compile(r'<page number="([^"]*)"/>')
HEADING = re.compile(r'<heading level="\d">(.*?)</heading>', re.S)
NUMBERED = re.compile(r'^(\d{1,5})\s*:\s*(.+)$', re.S)


def verse(m):
    """Noor's <poem>: a verse on a line of its own, its hemistichs apart by a tab (Noor's <gap/>; one hemistich keeps
    the tab after it), when it opens a line; a verse quoted inside a sentence, «(… أفقه... إلخ)», stays in it."""
    before = re.sub(r'(?:<(?!br|/?p\b|/poem)[^>]*>|\s)+$', '', m.string[:m.start()])
    hemistichs = [' '.join(h.split()).strip(' -–') for h in m.group(1).split('<gap/>')]
    if before and not re.search(r'(<br/?>|<p[^>]*>|</poem>)$', before):
        return ' '.join(hemistichs)
    return '\n' + '\t'.join(hemistichs) + ('\t' if len(hemistichs) == 1 else '') + '\n'


def strip(xml):
    xml = re.sub(r'<lfootnote[^>]*>\s*(\d+)\s*</lfootnote>', r' ( \1 ) ', xml)
    xml = re.sub(r'<mnote>\s*\((\d+)\)\s*</mnote>', r'( \1 ) ', xml)
    xml = re.sub(r'<poem>(.*?)</poem>', verse, xml, flags=re.S)
    xml = re.sub(r'<br/?>', '\n', xml)
    return html.unescape(re.sub(r'<(?!page )[^>]+>', '', xml))


# headings Noor gives a passage of its own, never printed
NOOR_LABELS = {'بيان', 'مقدمة', 'المقدمة', 'تمهيد', 'تنبيه', 'فائدة', 'خاتمة', 'تذييل', 'تكملة'}
JOIN = '\ue000'  # after a heading: it runs on with the paragraph that follows, as printed
CLOSED = '\ue001'  # after a heading whose «)» Noor left in the paragraph that follows


def plain(title):
    return ' '.join(strip(title).split())


def bare(title):
    m = NUMBERED.match(title)
    return re.sub(r'[\s"()\[\]]', '', m.group(2) if m else title).replace('ة', 'ه').replace('أ', 'ا').replace('إ', 'ا')


def heading(title, following):
    """Noor's heading in the printed form, or nothing for a heading Noor added: a section's page range, a generic
    label in brackets («[بيان]»), or a bracketed name over the entry of that name right after it («[تحفه الأبرار]»
    before «1450: تحفه الأبرار»). Other bracketed headings are printed ones («( بحر الأنساب )»)."""
    if not title or re.search(r'ص \d+\s*-', title):
        return ''
    m = re.match(r'^\[\s*(\d+)\s*\]\s*\((.*)\)$', title, re.S)
    if m:  # vol 26 numbers an entry on a line of its own: «[ 469 ]», then «( بزم آرا ) …»
        return f'\n[ {m.group(1)} ]\n\n( {m.group(2).strip()} )' + JOIN
    if title.startswith('['):
        inner = title.strip('[] ')
        if inner in NOOR_LABELS or (following is not None and bare(following).startswith(bare(inner))):
            return ''
        title = inner
    # «749: نسخه الرضا (ع» with «)» opening the next paragraph: the parenthesis is closed here
    closed = title.count('(') > title.count(')')
    title += ')' * (title.count('(') - title.count(')')) if closed else ''
    # the print quotes a title inside a title («" صنائع الأسحار "»), where Noor has parentheses
    title = re.sub(r'\(([^\d()][^()]*)\)', r'" \1 "', title)
    m = NUMBERED.match(title)
    return '\n' + (f'( {m.group(1)} : {m.group(2)} )' if m else f'( {title} )') + (CLOSED if closed else '') + JOIN


def headings(xml):
    found = list(HEADING.finditer(xml))
    titles = [plain(m.group(1)) for m in found]
    out, at = [], 0
    for i, m in enumerate(found):
        out.append(xml[at:m.start()])
        out.append(heading(titles[i], titles[i + 1] if i + 1 < len(found) else None))
        at = m.end()
    return ''.join(out) + xml[at:]


def tidy(text):
    # a section's page range Noor adds under its heading: «(البائية الحميرية ص 3 - البيوع و المزارعات ص 196)»
    text = re.sub(r'\([^()\n]*ص \d+\s*-[^()\n]*ص \d+\s*\)', '', text)
    # Noor's «\» between words, where the print has a space
    text = text.replace('\\', ' ')
    text = re.sub(CLOSED + JOIN + r'(\s*)\)', JOIN + r'\1', text).replace(CLOSED, '')
    # a heading and the first line of its paragraph make one line, unless an entry heading comes next
    text = re.sub(JOIN + r'\s*(?!\(\s*\d+\s*:)(?=[^\s' + JOIN + '])', ' ', text).replace(JOIN, '\n')
    paragraphs = []
    for p in re.split(r'\n\s*\n', text):
        # a verse keeps the tab between its hemistichs
        lines = ['\t'.join(' '.join(h.split()) for h in line.split('\t')) if '\t' in line else ' '.join(line.split()) for line in p.split('\n')]
        p = '\n'.join(line for line in lines if line)
        if p:
            paragraphs.append(p)
    return '\n\n'.join(paragraphs)


def volume_pages(db, volume):
    doc = f'03264_{volume:03d}'
    rows = db.execute('SELECT story_id, text_xml FROM book_texts WHERE document_id = ? ORDER BY item_id', (doc,)).fetchall()
    main = headings(''.join(x for story, x in rows if story == 1))
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
        body[label] += part
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


TOKEN = re.compile(r'[^\s()«»"،.:؛\[\]]+')
HONORIFIC = re.compile(r'^(عليه السلام|عليهم السلام|عليهما السلام|عليها السلام|صلى الله عليه وآله(?: وسلم)?)$')
MARKS = re.compile(r'[\u064B-\u0652]')


def honorifics(text, printed):
    """Noor's text with each «ع» / «ص» that ablibrary's page has written out at the same place written out the same
    way; and how many were."""
    ours = [(m.start(), m.end(), MARKS.sub('', m.group())) for m in TOKEN.finditer(text)]
    theirs = [MARKS.sub('', t) for t in TOKEN.findall(printed)]
    changes = []
    matcher = difflib.SequenceMatcher(None, [t for _, _, t in ours], theirs, autojunk=False)
    for op, i1, i2, j1, j2 in matcher.get_opcodes():
        if op != 'replace':
            continue
        short = [i for i in range(i1, i2) if ours[i][2] in ('ع', 'ص')]
        if len(short) != 1:
            continue
        i = short[0]
        # the words around the abbreviation must stand unchanged on both sides of it
        before, after = ours[i1:i][::-1], ours[i + 1:i2]
        words = theirs[j1:j2]
        if len(words) < len(before) + len(after) + 2:
            continue
        if any(w[2] != words[k] for k, w in enumerate(ours[i1:i])) or \
                any(w[2] != words[len(words) - len(after) + k] for k, w in enumerate(after)):
            continue
        phrase = ' '.join(words[i - i1:len(words) - len(after)])
        m = HONORIFIC.match(phrase)
        if not m or (ours[i][2] == 'ص') != m.group(1).startswith('صلى'):
            continue
        changes.append((ours[i][0], ours[i][1], m.group(1)))
    for start, end, phrase in reversed(changes):
        text = text[:start] + phrase + text[end:]
    return text, len(changes)


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
        rows, seen, filled, written = [], set(), [], 0
        for label, text in pages:
            if not label.isdigit() or int(label) < 1 or label in seen:
                continue
            seen.add(label)
            origin = 'noor'
            if len(text) < 50 and len(ab.get(label, '')) > 200:
                text, origin = ab[label], 'ablibrary'
                filled.append(label)
            elif label in ab:
                text, n = honorifics(text, ab[label])
                written += n
            if text:
                rows.append({'page': int(label), 'label': label, 'text': text, 'origin': origin})
        with gzip.open(out / f'{volume:02d}.jsonl.gz', 'wt', encoding='utf-8') as f:
            for r in rows:
                f.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'v{volume}: {len(rows)} pages, {rows[0]["page"]}–{rows[-1]["page"]}; '
              f'from ablibrary: {",".join(filled) or "-"}; honorifics written out: {written}')


if __name__ == '__main__':
    main()
