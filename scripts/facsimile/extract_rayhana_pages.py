"""Render Rayhanat al-Adab pages to WebP for the catalog page viewer.

Each printed page in rayhana_page_mapping.json is rendered from its PDF leaf (bilevel CCITT scans,
2475 x 3498 px at 300 dpi) in grayscale to storage/app/public/catalogs/rayhana/v{NN}/p{NNNN}.webp,
named by printed page number.

The book is octavo scanned on A4: the text block fills about 42% of the leaf and sits left or right
on it depending on the page. So each leaf is cut to a fixed-size window centred on the text block
(found from the ink profiles, leaving out scanner edge bars and specks), then halved to the scale of
the uncut 1240 px renders. Pages keep the size of their volume while paging.
Where the text block is larger than the window (a stamp or a wide table), the window grows to fit it,
so those pages are a little larger; they are listed at the end, for checking by eye.

Existing files are skipped, so the run can be resumed; --force renders them again. Set TMPDIR to a
disk directory: each 300 dpi leaf is a 9 MB bitmap.

    python3 extract_rayhana_pages.py [--volumes 1 8] [--pages 54 422] [--workers 6] [--force] [--out DIR]
"""
import argparse
import json
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
# RAYHANA_SOURCES overrides the scan folder, e.g. when the script runs from a worktree.
SOURCES = os.environ.get('RAYHANA_SOURCES') or os.path.abspath(os.path.join(REPO, '..', 'fahares-sources', 'rayhana'))
OUT = os.path.join(REPO, 'storage', 'app', 'public', 'catalogs', 'rayhana')

# Window at 300 dpi: text block (about 1470 x 2455 px; 2325 px high in volumes 3-6) plus about 80 px each side.
WIDTH = 1630
HEIGHT = {'03': 2485, '04': 2485, '05': 2485, '06': 2485}
DEFAULT_HEIGHT = 2615
SCALE = 1240 / 2475
PAD = 60
BAR = 40


def text_span(ink, gap, size):
    """First and last index of the text block in an ink profile (ink per column or per row).

    Narrow runs (up to BAR px) of indices with more than three times the median ink are scanner edge
    bars or rules and are dropped. Wider dense runs are photos, facsimiles or inscriptions and stay:
    dropping them moved the window off the text block on pages with a picture (1/41).
    Indices with under a tenth of the median (after smoothing) are specks. Inked runs closer than
    `gap` are joined. The run with the most ink is the text block; neighbouring runs are added to it,
    the heavier side first, while the whole still fits in `size` less PAD each side. A blank stretch
    inside the block (between entries, above a footnote) otherwise split it and the window kept only
    one part (4/274, 5/398); an edge bar or a stamp beyond the margin is too far to fit.
    """
    inked = ink[ink > 0]
    if not len(inked):
        return None
    median = np.median(inked)
    dense = np.flatnonzero(ink > 3 * median)
    ink = ink.copy()
    if len(dense):
        for run in np.split(dense, np.flatnonzero(np.diff(dense) > 1) + 1):
            if run[-1] - run[0] + 1 <= BAR:
                ink[run[0]:run[-1] + 1] = 0
    smooth = np.convolve(ink, np.ones(25) / 25, 'same')
    on = np.where(smooth > median / 10)[0]
    if not len(on):
        return None
    runs = [[on[0], on[0]]]
    for i in on[1:]:
        if i - runs[-1][1] <= gap:
            runs[-1][1] = i
        else:
            runs.append([i, i])
    weight = [ink[lo:hi + 1].sum() for lo, hi in runs]
    first = last = int(np.argmax(weight))
    while True:
        sides = [(weight[i], i) for i in (first - 1, last + 1) if 0 <= i < len(runs)
                 and max(runs[last][1], runs[i][1]) - min(runs[first][0], runs[i][0]) + 1 <= size - 2 * PAD]
        if not sides:
            return runs[first][0], runs[last][1]
        i = max(sides)[1]
        first, last = min(first, i), max(last, i)


def place(span, size, length):
    """Start and size of a window of at least `size` centred on `span` and kept inside 0..length,
    and how far the span overflowed `size`. An overflowing window grows to the span plus PAD each side."""
    if span is None:
        return (length - size) // 2, size, 0
    lo, hi = span
    over = max(0, hi - lo + 1 - size)
    if over:
        size = min(hi - lo + 1 + 2 * PAD, length)
    start = min(max((lo + hi - size) // 2, 0), length - size)
    return start, size, over


def render(job):
    volume, page, pdf, leaf, out, force = job
    target = os.path.join(out, f'v{volume}', f'p{int(page):04d}.webp')
    if os.path.exists(target) and not force:
        return volume, page, 'skip', 0
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-r', '300', '-gray', '-singlefile',
                        os.path.join(SOURCES, f'{pdf}.pdf'), base], check=True)
        image = Image.open(base + '.pgm')
        image.load()
        ink = np.asarray(image) < 128
        height = HEIGHT.get(volume, DEFAULT_HEIGHT)
        x, width, over_x = place(text_span(ink.sum(0), 60, WIDTH), WIDTH, ink.shape[1])
        y, height, over_y = place(text_span(ink.sum(1), 150, height), height, ink.shape[0])
        lost = max(over_x, over_y)
        cut = image.crop((x, y, x + width, y + height))
        cut = cut.resize((round(cut.width * SCALE), round(cut.height * SCALE)), Image.LANCZOS)
        cut.save(base + '-cut.pgm')
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '-cut.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return volume, page, 'done', int(lost)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--volumes', type=int, nargs='*')
    parser.add_argument('--pages', type=int, nargs='*')
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--force', action='store_true')
    parser.add_argument('--out', default=OUT)
    args = parser.parse_args()

    with open(os.path.join(HERE, 'rayhana_page_mapping.json')) as f:
        mapping = json.load(f)
    jobs = [(v, p, pdf, leaf, args.out, args.force) for v, pages in mapping.items() if not args.volumes or int(v) in args.volumes
            for p, (pdf, leaf) in pages.items() if not args.pages or int(p) in args.pages]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    states = [state for _, _, state, _ in results]
    print(f"{len(jobs)} pages: {states.count('done')} rendered, {states.count('skip')} already there")
    flagged = sorted((lost, v, p) for v, p, state, lost in results if state == 'done' and lost > 0)
    print(f'{len(flagged)} pages whose text block overflowed the window, which was grown to fit (px at 300 dpi):')
    for lost, v, p in reversed(flagged):
        print(f'  v{v} p{p}: {lost}')


if __name__ == '__main__':
    main()
