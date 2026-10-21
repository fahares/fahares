"""Render the pages of a library catalogue edition (backlog item 51) to WebP for the catalog page viewer.

The PDF is an image scan whose leaves run a fixed offset ahead of the printed pages (Masjid-e A'zam vol. 2,
Ostadi 1365, from Ghaemiyeh: leaf = page + 2, checked on the headers of pp. 100, 415, 700 and 827). Each printed
page is rendered in grayscale at the scan's own width (no upscaling) to
storage/app/public/catalogs/{code}/v{NN}/p{NNNN}.webp, named by printed page number. Existing files are skipped,
so the run can be resumed. --out writes elsewhere (from a worktree, the main checkout's storage, which the local
site serves).

    python3 extract_edition_pages.py PDF --code masjid-azam-ostadi --volume 2 --offset 2 --first 1 --last 827 \
        [--width 899] [--workers 6] [--out DIR]
"""
import argparse
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))


def render(job):
    pdf, leaf, width, target = job
    if os.path.exists(target):
        return 'skip'
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory(dir=os.path.dirname(target)) as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-scale-to-x', str(width), '-scale-to-y', '-1', '-gray',
                        '-singlefile', pdf, base], check=True)
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('pdf')
    parser.add_argument('--code', required=True, help='facsimile_code of the catalog_volumes row')
    parser.add_argument('--volume', type=int, required=True)
    parser.add_argument('--offset', type=int, required=True, help='PDF leaf minus printed page')
    parser.add_argument('--first', type=int, required=True)
    parser.add_argument('--last', type=int, required=True)
    parser.add_argument('--width', type=int, default=899, help="the scan's own width in px (pdfimages -list)")
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--out', default=os.path.join(REPO, 'storage', 'app', 'public', 'catalogs'))
    args = parser.parse_args()

    folder = os.path.join(args.out, args.code, f'v{args.volume:02d}')
    jobs = [(args.pdf, page + args.offset, args.width, os.path.join(folder, f'p{page:04d}.webp'))
            for page in range(args.first, args.last + 1)]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    print(f"{len(jobs)} pages: {results.count('done')} rendered, {results.count('skip')} already there, in {folder}")


if __name__ == '__main__':
    main()
