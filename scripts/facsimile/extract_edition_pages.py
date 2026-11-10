"""Render the pages of a library catalogue edition (backlog item 51) to WebP for the catalog page viewer.

The PDF is an image scan whose leaves run a fixed offset ahead of the printed pages (Masjid-e A'zam vol. 2,
Ostadi 1365, from Ghaemiyeh: leaf = page + 2, checked on the headers of pp. 100, 415, 700 and 827). Each printed
page is rendered in grayscale at the scan's own width (no upscaling) to
storage/app/public/catalogs/{code}/v{NN}/p{NNNN}.webp, named by printed page number. Existing files are skipped,
so the run can be resumed. --out writes elsewhere (from a worktree, the main checkout's storage, which the local
site serves).

    python3 extract_edition_pages.py PDF --code masjid-azam-ostadi --volume 2 --offset 2 --first 1 --last 827 \
        [--width 899] [--workers 6] [--out DIR]

A scan whose leaves are out of order, or miss pages, or change offset at plates (Majlis, step 14.122) takes
--map instead of --offset/--first/--last: a JSON object of printed page -> PDF leaf, read leaf by leaf.

    python3 extract_edition_pages.py PDF --code majlis --volume 2 --map v02.map.json [--width 1100]

A scan of open spreads, two printed pages per leaf (Majlis short catalogue, step 14.136), names the half in the map:
"LEAF:r" for the right page, "LEAF:l" for the left one (a plain number is still the whole leaf). The leaf is rendered
at twice --width and cut at its gutter, the column of least ink near the middle; the black scanner bed at the outer
edge and at the foot of each half is trimmed.
"""
import argparse
import json
import os
import subprocess
import tempfile

import numpy as np
from PIL import Image
from concurrent.futures import ProcessPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))


def gutter(page):
    """The column to cut a two-page spread at: the middle of the widest run of inkless columns in the central fifth."""
    w = page.shape[1]
    lo, hi = int(w * 0.4), int(w * 0.6)
    body = page[int(page.shape[0] * 0.1):int(page.shape[0] * 0.9), lo:hi]
    ink = (body < 90).sum(axis=0)
    quiet = ink <= max(2, int(np.percentile(ink, 10)))
    best, start = (0, w // 2 - lo), None
    for x, q in enumerate(list(quiet) + [False]):
        if q and start is None:
            start = x
        elif not q and start is not None:
            best = max(best, (x - start, (start + x) // 2))
            start = None
    return lo + best[1]


def outer_trim(half, side):
    """Drop the dark scanner bed at the outer edge of a half page (the scan has a white margin outside it): cut after
    the innermost dark column (mean below 120) of the outer fifth. Text on paper never darkens a column that far."""
    w = half.shape[1]
    mean = half.mean(axis=0)
    band = range(w // 5) if side == 'l' else range(w - 1, w - 1 - w // 5, -1)
    dark = [x for x in band if mean[x] < 120]
    if not dark:
        return half
    return half[:, max(dark) + 1:] if side == 'l' else half[:, :min(dark)]


def bottom_trim(half):
    """Drop the dark scanner bed under a half page (step 14.136: about 9% of the height on every page of the Majlis
    short catalogue): cut at the first row of the bottom fifth whose mean is below 120, moved up past the grey rows of
    the edge (mean 235 or less). Text lines never darken a whole row that far."""
    h = half.shape[0]
    mean = half.mean(axis=1)
    band = [y for y in range(h - h // 5, h) if mean[y] < 120]
    if not band:
        return half
    cut = band[0]
    while cut > h - h // 5 and mean[cut - 1] <= 235:
        cut -= 1
    return half[:cut]


def edge_band(profile):
    """How many lines to drop at one edge (profile runs from the edge inward): a dark band (mean below 120) that
    starts within the outer tenth, after a white margin, and is at least 1% of the length thick without a break, plus
    the grey lines (235 or less) that follow it. A thin rule, such as the line under a running header, is no band."""
    n = len(profile)
    i = 0
    while i < n // 10 and profile[i] > 200:
        i += 1
    j = i
    while j < n // 5 and profile[j] < 120:
        j += 1
    if j - i < max(8, n // 100):
        return 0
    while j < n // 5 and profile[j] <= 235:
        j += 1
    return j


def trim_edges(page):
    """--trim-edges (step 14.138): drop dark scanner bands on the four sides of a whole leaf (edge_band)."""
    rows, cols = page.mean(axis=1), page.mean(axis=0)
    top, bottom = edge_band(rows), edge_band(rows[::-1])
    left, right = edge_band(cols), edge_band(cols[::-1])
    return page[top:page.shape[0] - bottom, left:page.shape[1] - right]


def render(job):
    pdf, leaf, width, target, edges = job
    if os.path.exists(target):
        return 'skip'
    leaf, side = (int(leaf), None) if ':' not in str(leaf) else (int(str(leaf).split(':')[0]), str(leaf).split(':')[1])
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory(dir=os.path.dirname(target)) as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-q', '-f', str(leaf), '-l', str(leaf), '-scale-to-x', str(width * (2 if side else 1)),
                        '-scale-to-y', '-1', '-gray', '-singlefile', pdf, base], check=True)
        if edges and not side:
            page = trim_edges(np.asarray(Image.open(base + '.pgm').convert('L')))
            Image.fromarray(np.ascontiguousarray(page)).save(base + '.pgm')
        if side:
            spread = np.asarray(Image.open(base + '.pgm').convert('L'))
            x = gutter(spread)
            half = bottom_trim(outer_trim(spread[:, :x] if side == 'l' else spread[:, x:], side))
            Image.fromarray(half).save(base + '.pgm')
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('pdf')
    parser.add_argument('--code', required=True, help='facsimile_code of the catalog_volumes row')
    parser.add_argument('--volume', type=int, required=True)
    parser.add_argument('--offset', type=int, help='PDF leaf minus printed page')
    parser.add_argument('--first', type=int)
    parser.add_argument('--last', type=int)
    parser.add_argument('--map', help='JSON object of printed page -> PDF leaf (or "LEAF:r"/"LEAF:l", a half of a two-page leaf), in place of --offset/--first/--last')
    parser.add_argument('--width', type=int, default=899, help="the scan's own width in px (pdfimages -list)")
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--trim-edges', action='store_true', help='drop dark scanner bands at the edges of whole leaves')
    parser.add_argument('--out', default=os.path.join(REPO, 'storage', 'app', 'public', 'catalogs'))
    args = parser.parse_args()

    folder = os.path.join(args.out, args.code, f'v{args.volume:02d}')
    if args.map:
        with open(args.map) as f:
            leaves = {int(page): leaf for page, leaf in json.load(f).items()}
    elif None in (args.offset, args.first, args.last):
        parser.error('give --map, or --offset, --first and --last')
    else:
        leaves = {page: page + args.offset for page in range(args.first, args.last + 1)}
    jobs = [(args.pdf, leaf, args.width, os.path.join(folder, f'p{page:04d}.webp'), args.trim_edges) for page, leaf in sorted(leaves.items())]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    print(f"{len(jobs)} pages: {results.count('done')} rendered, {results.count('skip')} already there, in {folder}")


if __name__ == '__main__':
    main()
