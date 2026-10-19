"""Share of Fankha citations of Rayhanat al-Adab that land on a mapped page.

Reads the bibliography field of ../fahares-corpus/json/fankha/*.json. Fankha writes "page/volume" ("369/4");
some entries write "volume: page", "ج 4 ص 326" or "ج 3 - 497", which are read too.
Prints the totals and the citations that do not resolve, with their reason.

    python3 rayhana_coverage.py [--list]
"""
import argparse
import collections
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
CORPUS = os.path.abspath(os.path.join(HERE, '..', '..', '..', 'fahares-corpus', 'json', 'fankha'))
NAME = r'ریحان[ةه]\s*ال[اأ]دب'
VOLUMES = range(1, 9)
# "ج 4 ص 326", "ج 3 - 497", "ج 1: 39", "ج3، ص50"
JILD = re.compile(r'(?:ج\s*)?(\d+)\s*[،,]?\s*(?:ص\s*|(?<=\d)\s*[-:]\s*)(\d+)')
PAIR = re.compile(r'(\d+)\s*([/:،,-])\s*(\d+)')


def parse(entry):
    """(page, volume) pairs cited in one bibliography entry, after the book's name."""
    for m in re.finditer(NAME, entry):
        tail = entry[m.end():m.end() + 40]
        tail = re.split(r'[A-Za-zء-غف-يپچژکگی]{3,}', tail)[0]
        found = False
        for j in JILD.finditer(tail):
            if 'ج' not in j.group(0) and 'ص' not in j.group(0):
                continue
            volume, page = int(j.group(1)), int(j.group(2))
            if volume not in VOLUMES and page in VOLUMES:
                volume, page = page, volume
            found = True
            yield page, volume, j.group(0)
        if found:
            continue
        for p in PAIR.finditer(tail):
            a, sep, b = int(p.group(1)), p.group(2), int(p.group(3))
            if sep == '/' and b in VOLUMES:
                page, volume = a, b
            elif a in VOLUMES and b not in VOLUMES:
                page, volume = b, a
            elif b in VOLUMES:
                page, volume = a, b
            else:
                page, volume = a, None
            found = True
            yield page, volume, p.group(0)
            break
        if not found:
            yield None, None, tail.strip()


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
    with open(os.path.join(HERE, 'rayhana_page_mapping.json')) as f:
        mapping = json.load(f)
    counts = collections.Counter()
    misses = []
    for title, author, text, page, volume in citations():
        counts['all'] += 1
        if page is None:
            reason = 'no page/volume'
        elif volume is None:
            reason = 'no volume'
        elif str(page) in mapping[f'{volume:02d}']:
            counts['mapped'] += 1
            continue
        else:
            reason = 'page outside the edition'
        counts[reason] += 1
        misses.append((reason, title, author, text))
    with_form = counts['all'] - counts['no page/volume']
    print(f"{counts['all']} citations, {with_form} with page/volume form, {counts['mapped']} mapped "
          f"({100 * counts['mapped'] / with_form:.1f}% of those, {100 * counts['mapped'] / counts['all']:.1f}% of all)")
    for reason in ('no page/volume', 'no volume', 'page outside the edition'):
        print(f'  {reason}: {counts[reason]}')
    if args.list:
        for reason, title, author, text in sorted(misses):
            print(f'{reason} | {title} | {author} | {text}')


if __name__ == '__main__':
    main()
