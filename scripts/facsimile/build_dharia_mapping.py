"""Build the printed page -> PDF leaf table for the al-Dhari'a scans.

Input:  dharia_readings.py (printed page numbers read by eye from the header of sampled leaves)
        dharia_leafcounts.json (leaves per PDF file)
Output: dharia_page_mapping.json  {"03": {"402": ["03", 402], ...}, "09": {...}, ...}

Between two readings with the same offset (printed - leaf) every leaf is mapped with that offset.
Where the offset changes the two readings must be adjacent leaves: the printed pages in between
are missing from the scan. Volume 9 is one printed volume split over four PDF files (09-1 .. 09-4).
"""
import json
import os

from dharia_readings import BOUNDS, R

HERE = os.path.dirname(os.path.abspath(__file__))


def build():
    volumes = {}
    for part in sorted(R):
        readings = R[part]
        leaves = sorted(readings)
        pages = {}
        for a, c in zip(leaves, leaves[1:]):
            if readings[a] - a == readings[c] - c:
                for leaf in range(a, c + 1):
                    pages[leaf + readings[a] - a] = leaf
            else:
                assert c - a == 1, f'unresolved offset change in {part} between leaves {a} and {c}'
                pages[readings[a]] = a
                pages[readings[c]] = c
        # leaves before the first reading keep its offset
        offset = readings[leaves[0]] - leaves[0]
        for leaf in range(1, leaves[0]):
            if leaf + offset >= 1:
                pages.setdefault(leaf + offset, leaf)
        lo, hi = BOUNDS.get(part, (None, None))
        target = volumes.setdefault(f"{int(part.split('-')[0]):02d}", {})
        for page, leaf in sorted(pages.items()):
            if (lo is not None and leaf < lo) or (hi is not None and leaf > hi):
                continue
            assert page not in target, f'page {page} of {part} is already mapped'
            target[page] = [part, leaf]
    return {v: {str(p): m[p] for p in sorted(m)} for v, m in sorted(volumes.items())}


if __name__ == '__main__':
    mapping = build()
    with open(os.path.join(HERE, 'dharia_page_mapping.json'), 'w') as f:
        json.dump(mapping, f, ensure_ascii=False)
    for v, m in mapping.items():
        pages = sorted(map(int, m))
        missing = [p for p in range(1, pages[-1] + 1) if str(p) not in m]
        print(f'v{v}: {len(pages)} pages, 1..{pages[-1]}, missing {len(missing)}: {missing}')
