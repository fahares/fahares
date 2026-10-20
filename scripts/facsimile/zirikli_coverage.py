"""Share of Fankha citations of Zirikli's al-A'lam that land on a mapped page.

Reads the bibliography field of ../fahares-corpus/json/fankha/*.json. Fankha names the book «الاعلام»,
«الاعلام زرکلی», «اعلام زرکلی», «زرکلی», «زرکلی اعلام», «الاعلام، زرکلی», «الاعلام للزرکلی» (and Arabic spellings)
and mostly writes "page/volume" ("80/6"), sometimes "volume: page" or "ج 6، ص 41".
Left out: «اعلام» alone, without the article, which in Fankha is another (Persian) book numbered past page 1,000
(«اعلام 1884/3»; its volume 1 has Jabir b. Hayyan at page 410, al-A'lam's volume 1 ends at 336), and the titles that
contain the word: اعلام الشیعة, طبقات اعلام الشیعة, قاموس الاعلام, سیر اعلام النبلاء, اعلام المؤلفین الزیدیة ...
Citations of volume 9 come from the ten-volume second edition (1954-59) and do not resolve.
Prints the totals and the citations that do not resolve, with their reason.

    python3 zirikli_coverage.py [--list] [--excluded]
"""
import argparse
import collections
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
# the sister repository beside the checkout (or beside the main checkout, from a worktree in .claude/worktrees)
CORPUS = next(p for p in (os.path.abspath(os.path.join(HERE, *['..'] * n, 'fahares-corpus', 'json', 'fankha')) for n in (3, 6))
              if os.path.isdir(p))
VOLUMES = range(1, 9)
W = r'[\w‌]'
# «اعلام»/«الاعلام» with Zirikli's name before or after it, or Zirikli's name alone, or «الاعلام» alone
NAME = re.compile(rf'(?<!{W})(?:(?:ال)?اعلام[\s،,]*\(?(?:لل)?زرکلی\)?|زرکلی(?:[\s،,]*(?:ال)?اعلام)?|للزرکلی|(?P<bare>(?:ال)?اعلام))(?!{W})')
# the words that make «اعلام» part of another title
OTHER_BEFORE = re.compile(r'(?:سیر|سير|قاموس|طبقات|موسوعه|معجم|تراجم|نظرات|اتمام|تتمه|ذیل|مستدرک)\s*$')
OTHER_AFTER = re.compile(r'^\s*[^\s\d،,:;؛.()\-/]')
JILD = re.compile(r'^[\s،,:]*ج\s*(\d+)\s*[،,]?\s*ص\s*(\d+)')
PAIR = re.compile(r'^[\s،,:]*(\d+)\s*([/:])\s*(\d+)')


def normalize(s):
    return re.sub(r'[ً-ْ]', '', s.translate(str.maketrans(
        {'ي': 'ی', 'ى': 'ی', 'ك': 'ک', 'ة': 'ه', 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ـ': ''})))


def parse(entry):
    """(page, volume, text) of each citation of the book in one bibliography entry; page None if not one of it."""
    entry = normalize(entry)
    for m in NAME.finditer(entry):
        tail = entry[m.end():m.end() + 30]
        if m.group('bare'):
            if OTHER_BEFORE.search(entry[:m.start()]) or OTHER_AFTER.match(tail):
                continue                                     # «اعلام الشیعه», «قاموس الاعلام»
            if not m.group('bare').startswith('ال') and not re.search(r'زرکلی[\s،,]*$', entry[:m.start()]):
                yield None, 'bare اعلام (another book)', m.group(0) + tail
                continue
        j = JILD.match(tail)
        if j:
            yield int(j.group(2)), int(j.group(1)), m.group(0) + j.group(0)
            continue
        p = PAIR.match(tail)
        if not p:
            yield None, 'no page/volume', (m.group(0) + tail).strip()
            continue
        a, sep, b = int(p.group(1)), p.group(2), int(p.group(3))
        if sep == '/' and b in VOLUMES or sep == ':' and a not in VOLUMES and b in VOLUMES:
            page, volume = a, b                              # «80/6», «135: 8»
        elif a in VOLUMES:
            page, volume = b, a                              # «6: 279», «2/ 103»
        else:
            page, volume = a, b
        yield page, volume, m.group(0) + p.group(0)


def citations():
    for path in sorted(glob.glob(os.path.join(CORPUS, '*.json'))):
        for work in json.load(open(path))['works']:
            for entry in work.get('bibliography') or []:
                for page, volume, text in parse(entry):
                    yield work['primary_title'], work.get('author_name'), text, page, volume


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--list', action='store_true')
    parser.add_argument('--excluded', action='store_true', help='also list the bare «اعلام» citations')
    args = parser.parse_args()
    with open(os.path.join(HERE, 'zirikli_page_mapping.json')) as f:
        mapping = json.load(f)
    counts = collections.Counter()
    misses = []
    for title, author, text, page, volume in citations():
        counts['all'] += 1
        if page is None:
            reason = volume
        elif volume not in VOLUMES:
            reason = 'volume 9-10 (second edition)' if volume in (9, 10) else 'no volume'
        elif str(page) in mapping[f'{volume:02d}']:
            counts['mapped'] += 1
            continue
        else:
            reason = 'page outside the edition'
        counts[reason] += 1
        misses.append((reason, title, author, text))
    other = counts['bare اعلام (another book)']
    ours = counts['all'] - other
    with_form = ours - counts['no page/volume']
    print(f"{ours} citations of al-A'lam ({other} of the other «اعلام» left out), {with_form} with page/volume, "
          f"{counts['mapped']} mapped ({100 * counts['mapped'] / with_form:.1f}% of those, "
          f"{100 * counts['mapped'] / ours:.1f}% of all)")
    for reason in ('no page/volume', 'volume 9-10 (second edition)', 'no volume', 'page outside the edition'):
        print(f'  {reason}: {counts[reason]}')
    if args.list:
        for reason, title, author, text in sorted(misses):
            if args.excluded or not reason.startswith('bare'):
                print(f'{reason} | {title} | {author} | {text}')


if __name__ == '__main__':
    main()
