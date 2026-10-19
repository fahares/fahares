"""Build the printed column -> PDF leaf table for the Kashf al-Zunun scans.

Input:  kashf_readings.py (printed right-column numbers read by eye from the footer of sampled leaves)
Output: kashf_page_mapping.json  {"01": {"500": [290, "L"], ...}, "02": {...}}

Fankha cites Kashf al-Zunun by column ("500/1"), and every leaf carries two columns: the odd one on the
right ("R"), the even one on the left ("L"). Between two readings with the same offset every leaf is mapped
with that offset; where the offset changes the two readings must be adjacent leaves. step is the number of
scan leaves per printed page: 2 where the scan holds every page twice (idah, hadiyya), and then only the
leaves the readings use (same parity) are mapped.
build() and write() also serve the other column-numbered books of the Istanbul set (idah, hadiyya).
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))


def build(R, BOUNDS, step=1):
    volumes = {}
    for volume in sorted(R):
        readings = R[volume]
        leaves = sorted(readings)
        right = {}
        for a, c in zip(leaves, leaves[1:]):
            if readings[a] - 2 * a // step == readings[c] - 2 * c // step:
                for leaf in range(a, c + 1, step):
                    right[leaf] = readings[a] + 2 * (leaf - a) // step
            else:
                assert c - a == step, f'unresolved offset change in {volume} between leaves {a} and {c}'
                right[a], right[c] = readings[a], readings[c]
        lo, hi = BOUNDS[volume]
        columns = {}
        for leaf, number in sorted(right.items()):
            if lo <= leaf <= hi:
                for column, side in ((number, 'R'), (number + 1, 'L')):
                    assert column not in columns, f'column {column} of {volume} is already mapped'
                    columns[column] = [leaf, side]
        volumes[volume] = {str(c): columns[c] for c in sorted(columns)}
    return volumes


def write(book, R, BOUNDS, step=1):
    mapping = build(R, BOUNDS, step)
    with open(os.path.join(HERE, f'{book}_page_mapping.json'), 'w') as f:
        json.dump(mapping, f, ensure_ascii=False)
    for v, m in mapping.items():
        columns = sorted(map(int, m))
        missing = [c for c in range(columns[0], columns[-1] + 1) if str(c) not in m]
        print(f'v{v}: {len(columns)} columns, {columns[0]}..{columns[-1]}, missing {len(missing)}: {missing}')


if __name__ == '__main__':
    from kashf_readings import BOUNDS, R
    write('kashf', R, BOUNDS)
