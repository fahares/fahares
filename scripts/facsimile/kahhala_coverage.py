"""Share of Fankha citations of Kahhala's Mu'jam al-Mu'allifin that land on a mapped page.

Reads the bibliography field of ../fahares-corpus/json/fankha/*.json. Fankha names the book «معجم المؤلفین»,
«کحاله», «معجم المؤلفین، کحاله» or «معجم المؤلفین عمر رضا کحاله» (and Arabic spellings), and mostly writes
"page/volume" ("128/5"); some entries write "volume: page" or "page: volume", "volume-page" ("11-314") or
"ج 12، ص 218". Citations of the four-volume Risala edition («کحاله (چاپ جدید)») and of other books whose title
begins the same way (معجم المؤلفین العراقیین، المعاصرین، تکملة/مستدرک ... معجم المؤلفین) are left out.
Prints the totals and the citations that do not resolve, with their reason.

    python3 kahhala_coverage.py [--list]
"""
import argparse
import collections
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
CORPUS = os.path.abspath(os.path.join(HERE, '..', '..', '..', 'fahares-corpus', 'json', 'fankha'))
NAME = re.compile(r'(?<!علی )(?<!على )(?<!تكملة )(?<!تکملة )(?:معجم[\s‌]*ال?م[ؤو]لف[یي]ن(?![\s‌]*ال(?!ف))'
                  r'(?:[\s،,]*(?:عمر\s*رضا\s*)?[کك]حال[هة])?|(?:عمر\s*رضا\s*)?[کك]حال[هة])')
VOLUMES = range(1, 14)
NEW_EDITION = re.compile(r'^\s*\(?\s*چاپ\s*جدید')
JILD = re.compile(r'^[\s،,:.]*ج\s*(\d+)\s*[،,]?\s*ص\s*(\d+)')
PAIR = re.compile(r'^[\s،,:]*(\d+)\s*([/:\-])\s*(\d+)')
RANGE = re.compile(r'^[\s،,:]*(\d+)\s*-\s*\d+\s*/\s*(\d+)')  # «129-130/8»: pages 129-130 of volume 8


def parse(entry):
    """(page, volume, text) of each citation of the book in one bibliography entry; page None if unreadable."""
    for m in NAME.finditer(entry):
        tail = entry[m.end():m.end() + 30]
        if NEW_EDITION.match(tail):
            yield None, 'new edition', tail.strip()
            continue
        j = JILD.match(tail)
        if j:
            yield int(j.group(2)), int(j.group(1)), j.group(0).strip()
            continue
        r = RANGE.match(tail)
        if r:
            yield int(r.group(1)), int(r.group(2)), r.group(0).strip()
            continue
        p = PAIR.match(tail)
        if not p:
            yield None, 'no page/volume', tail.strip()
            continue
        a, sep, b = int(p.group(1)), p.group(2), int(p.group(3))
        if sep == '-':
            page, volume = b, a              # «11-314»: volume-page, as the site reads it
        elif b in VOLUMES:
            page, volume = a, b              # «128/5», «108: 7»
        elif a in VOLUMES:
            page, volume = b, a              # «13/ 304», «9: 15»
        else:
            page, volume = a, None
        yield page, volume, p.group(0).strip()


def citations():
    for path in sorted(glob.glob(os.path.join(CORPUS, '*.json'))):
        for work in json.load(open(path))['works']:
            for entry in work.get('bibliography') or []:
                for page, volume, text in parse(entry):
                    yield work['primary_title'], work.get('author_name'), text, page, volume


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--list', action='store_true')
    args = parser.parse_args()
    with open(os.path.join(HERE, 'kahhala_page_mapping.json')) as f:
        mapping = json.load(f)
    counts = collections.Counter()
    misses = []
    for title, author, text, page, volume in citations():
        counts['all'] += 1
        if page is None:
            reason = volume
        elif volume not in VOLUMES:
            reason = 'no volume'
        elif str(page) in mapping[f'{volume:02d}']:
            counts['mapped'] += 1
            continue
        else:
            reason = 'page outside the edition'
        counts[reason] += 1
        misses.append((reason, title, author, text))
    with_form = counts['all'] - counts['no page/volume'] - counts['new edition']
    print(f"{counts['all']} citations, {with_form} of this edition with page/volume form, {counts['mapped']} mapped "
          f"({100 * counts['mapped'] / with_form:.1f}% of those, {100 * counts['mapped'] / counts['all']:.1f}% of all)")
    for reason in ('new edition', 'no page/volume', 'no volume', 'page outside the edition'):
        print(f'  {reason}: {counts[reason]}')
    if args.list:
        for reason, title, author, text in sorted(misses):
            print(f'{reason} | {title} | {author} | {text}')


if __name__ == '__main__':
    main()
