"""Render Kashf al-Zunun columns to WebP for the catalog page viewer.

Fankha cites Kashf al-Zunun by column, so every leaf in kashf_page_mapping.json is cut in two at the
rule between its columns: the right half becomes the odd column, the left half the even one. Each half
keeps its running head. Leaves are rendered at the scan's native 300 dpi in grayscale (halves are about
1100 px wide) and written to storage/app/public/catalogs/kashf/v{NN}/p{NNNN}.webp, named by printed column.
Existing files are skipped, so the run can be resumed.

    python3 extract_kashf_pages.py [--volumes 1] [--columns 499 500] [--workers 6]
"""
import argparse
import json
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
SOURCES = os.path.abspath(os.path.join(REPO, '..', 'fahares-sources', 'kashf'))
OUT = os.path.join(REPO, 'storage', 'app', 'public', 'catalogs', 'kashf')


def split_x(image):
    """x of the column rule: the darkest pixel column in the middle fifth of the body, else the emptiest."""
    w, h = image.size
    body = image.crop((int(w * .4), int(h * .15), int(w * .6), int(h * .85))).point(lambda v: 255 if v < 128 else 0)
    dark = [sum(body.crop((x, 0, x + 1, body.size[1])).getdata()) // 255 for x in range(body.size[0])]
    best = max(range(len(dark)), key=dark.__getitem__)
    if dark[best] < body.size[1] * .4:
        smooth = [sum(dark[max(0, x - 6):x + 7]) for x in range(len(dark))]
        best = min(range(len(smooth)), key=smooth.__getitem__)
    return int(w * .4) + best


def render(job):
    volume, leaf, columns = job
    targets = {side: os.path.join(OUT, f'v{volume}', f'p{int(column):04d}.webp') for column, side in columns}
    if all(os.path.exists(t) for t in targets.values()):
        return 'skip'
    os.makedirs(os.path.join(OUT, f'v{volume}'), exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        base = os.path.join(tmp, 'leaf')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-r', '300', '-gray', '-singlefile',
                        os.path.join(SOURCES, f'v{int(volume)}_text.pdf'), base], check=True)
        image = Image.open(base + '.pgm')
        x = split_x(image)
        halves = {'R': image.crop((x, 0, image.size[0], image.size[1])), 'L': image.crop((0, 0, x, image.size[1]))}
        for side, target in targets.items():
            half = os.path.join(tmp, side + '.pgm')
            halves[side].save(half)
            subprocess.run(['cwebp', '-quiet', '-q', '75', half, '-o', target + '.tmp'], check=True)
            os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--volumes', type=int, nargs='*')
    parser.add_argument('--columns', type=int, nargs='*')
    parser.add_argument('--workers', type=int, default=6)
    args = parser.parse_args()

    with open(os.path.join(HERE, 'kashf_page_mapping.json')) as f:
        mapping = json.load(f)
    leaves = {}
    for v, columns in mapping.items():
        if args.volumes and int(v) not in args.volumes:
            continue
        for column, (leaf, side) in columns.items():
            if not args.columns or int(column) in args.columns:
                leaves.setdefault((v, leaf), []).append((column, side))
    jobs = [(v, leaf, columns) for (v, leaf), columns in sorted(leaves.items())]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=4))
    print(f"{len(jobs)} leaves: {results.count('done')} rendered, {results.count('skip')} already there")


if __name__ == '__main__':
    main()
