"""Build the printed page -> PDF leaf table for the al-A'lam scans.

Input:  zirikli_readings.py (LEAVES: leaves per volume PDF; R: printed page numbers read by eye)
Output: zirikli_page_mapping.json  {"01": {"1": ["v01", 1], ...}, ...}

Between two readings with the same offset (printed - leaf) every leaf is mapped with that offset.
Where the offset changes the two readings must be adjacent leaves: the printed pages in between
are missing from the scan. Leaves before the first and after the last reading keep the offset of
that reading; leaves before printed page 1 are dropped.
"""
import json
import os

from zirikli_readings import LEAVES, R

HERE = os.path.dirname(os.path.abspath(__file__))


def build():
    mapping = {}
    for volume in sorted(R):
        readings = R[volume]
        leaves = sorted(readings)
        assert 1 <= leaves[0] and leaves[-1] <= LEAVES[volume], f'reading outside volume {volume}'
        pages = {}
        for a, c in zip(leaves, leaves[1:]):
            if readings[a] - a == readings[c] - c:
                for leaf in range(a, c + 1):
                    pages[leaf + readings[a] - a] = leaf
            else:
                assert c - a == 1, f'unresolved offset change in {volume} between leaves {a} and {c}'
                pages[readings[a]] = a
                pages[readings[c]] = c
        for leaf in range(1, leaves[0]):
            pages[leaf + readings[leaves[0]] - leaves[0]] = leaf
        for leaf in range(leaves[-1] + 1, LEAVES[volume] + 1):
            pages[leaf + readings[leaves[-1]] - leaves[-1]] = leaf
        mapping[volume] = {str(p): [f'v{volume}', pages[p]] for p in sorted(pages) if p >= 1}
    return mapping


if __name__ == '__main__':
    mapping = build()
    with open(os.path.join(HERE, 'zirikli_page_mapping.json'), 'w') as f:
        json.dump(mapping, f, ensure_ascii=False)
    for v, m in mapping.items():
        pages = sorted(map(int, m))
        missing = [p for p in range(1, pages[-1] + 1) if str(p) not in m]
        print(f'v{v}: {len(pages)} pages, {pages[0]}..{pages[-1]}, missing {len(missing)}: {missing}')
