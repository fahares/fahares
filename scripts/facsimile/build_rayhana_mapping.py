"""Build the printed page -> PDF leaf table for the Rayhanat al-Adab scans.

Input:  rayhana_readings.py (PARTS: where each volume sits in its PDF; R: printed page numbers read by eye)
Output: rayhana_page_mapping.json  {"01": {"54": ["2-1", 54], ...}, ...}

Between two readings with the same offset (printed - leaf) every leaf is mapped with that offset.
Where the offset changes the two readings must be adjacent leaves: the printed pages in between
are missing from the scan. Leaves of the volume before the first and after the last reading keep
the offset of that reading.
"""
import json
import os

from rayhana_readings import PARTS, R

HERE = os.path.dirname(os.path.abspath(__file__))


def build():
    mapping = {}
    for volume in sorted(R):
        pdf, first, last = PARTS[volume]
        readings = R[volume]
        leaves = sorted(readings)
        assert first <= leaves[0] and leaves[-1] <= last, f'reading outside volume {volume}'
        pages = {}
        for a, c in zip(leaves, leaves[1:]):
            if readings[a] - a == readings[c] - c:
                for leaf in range(a, c + 1):
                    pages[leaf + readings[a] - a] = leaf
            else:
                assert c - a == 1, f'unresolved offset change in {volume} between leaves {a} and {c}'
                pages[readings[a]] = a
                pages[readings[c]] = c
        for leaf in range(first, leaves[0]):
            pages[leaf + readings[leaves[0]] - leaves[0]] = leaf
        for leaf in range(leaves[-1] + 1, last + 1):
            pages[leaf + readings[leaves[-1]] - leaves[-1]] = leaf
        mapping[volume] = {str(p): [pdf, pages[p]] for p in sorted(pages) if p >= 1}
    return mapping


if __name__ == '__main__':
    mapping = build()
    with open(os.path.join(HERE, 'rayhana_page_mapping.json'), 'w') as f:
        json.dump(mapping, f, ensure_ascii=False)
    for v, m in mapping.items():
        pages = sorted(map(int, m))
        missing = [p for p in range(1, pages[-1] + 1) if str(p) not in m]
        print(f'v{v}: {len(pages)} pages, {pages[0]}..{pages[-1]}, missing {len(missing)}: {missing}')
