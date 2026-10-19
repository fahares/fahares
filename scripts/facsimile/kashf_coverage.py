"""Share of Fankha citations of Kashf al-Zunun that land on a mapped column.

Reads the bibliography field of ../fahares-corpus/json/fankha/*.json. Fankha writes "column/volume"
("473/1"); a few entries write "volume/column", "volume: column" or "column: volume", which are read too.
Prints the totals and the citations that do not resolve, with their reason.

    python3 kashf_coverage.py [--list]
"""
import argparse
import collections
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
CORPUS = os.path.abspath(os.path.join(HERE, '..', '..', '..', 'fahares-corpus', 'json', 'fankha'))
NAME = r'[كک]شف\s*ال[ظط]نون'
CITE = re.compile(NAME + r'[^0-9]{0,6}(\d+)\s*([/:])\s*(\d+)')


def citations():
    for path in sorted(glob.glob(os.path.join(CORPUS, '*.json'))):
        for work in json.load(open(path))['works']:
            for entry in work.get('bibliography') or []:
                if not re.search(NAME, entry):
                    continue
                found = False
                for m in CITE.finditer(entry):
                    a, sep, b = int(m.group(1)), m.group(2), int(m.group(3))
                    if sep == '/' and b in (1, 2):
                        column, volume = a, b
                    elif a in (1, 2):
                        column, volume = b, a
                    elif b in (1, 2):
                        column, volume = a, b
                    else:
                        column, volume = a, None
                    found = True
                    yield work['primary_title'], m.group(0), column, volume
                if not found:
                    yield work['primary_title'], entry, None, None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--list', action='store_true')
    args = parser.parse_args()
    with open(os.path.join(HERE, 'kashf_page_mapping.json')) as f:
        mapping = json.load(f)
    counts = collections.Counter()
    misses = []
    for title, text, column, volume in citations():
        counts['all'] += 1
        if column is None:
            reason = 'no column/volume'
        elif volume is None:
            reason = 'no volume'
        elif str(column) in mapping[f'{volume:02d}']:
            counts['mapped'] += 1
            continue
        elif any(str(column) in m for m in mapping.values()):
            reason = 'column is in the other volume'
        else:
            reason = 'column outside the edition'
        counts[reason] += 1
        misses.append((reason, title, text))
    with_form = counts['all'] - counts['no column/volume']
    print(f"{counts['all']} citations, {with_form} with column/volume form, {counts['mapped']} mapped "
          f"({100 * counts['mapped'] / with_form:.1f}% of those, {100 * counts['mapped'] / counts['all']:.1f}% of all)")
    for reason in ('no column/volume', 'no volume', 'column is in the other volume', 'column outside the edition'):
        print(f'  {reason}: {counts[reason]}')
    if args.list:
        for reason, title, text in sorted(misses):
            print(f'{reason} | {title} | {text}')


if __name__ == '__main__':
    main()
