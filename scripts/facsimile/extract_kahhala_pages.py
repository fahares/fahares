"""Render Mu'jam al-Mu'allifin pages to WebP for the catalog page viewer.

Each printed page in kahhala_page_mapping.json is rendered from its PDF leaf (scanned at about 312 dpi,
1964 px wide) in grayscale, 1240 px wide like the Fankha pages, to
storage/app/public/catalogs/kahhala/v{NN}/p{NNNN}.webp, named by printed page number.
Existing files are skipped, so the run can be resumed.

    python3 extract_kahhala_pages.py [--volumes 1 13] [--workers 6]
"""
import argparse
import json
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
SOURCES = os.path.abspath(os.path.join(REPO, '..', 'fahares-sources', 'kahhala'))
OUT = os.path.join(REPO, 'storage', 'app', 'public', 'catalogs', 'kahhala')


def render(job):
    volume, page, part, leaf = job
    target = os.path.join(OUT, f'v{volume}', f'p{int(page):04d}.webp')
    if os.path.exists(target):
        return 'skip'
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-scale-to-x', '1240', '-scale-to-y', '-1', '-gray', '-singlefile',
                        os.path.join(SOURCES, f'{part}_text.pdf'), base], check=True)
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--volumes', type=int, nargs='*')
    parser.add_argument('--workers', type=int, default=6)
    args = parser.parse_args()

    with open(os.path.join(HERE, 'kahhala_page_mapping.json')) as f:
        mapping = json.load(f)
    jobs = [(v, p, part, leaf) for v, pages in mapping.items() if not args.volumes or int(v) in args.volumes
            for p, (part, leaf) in pages.items()]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    print(f"{len(jobs)} pages: {results.count('done')} rendered, {results.count('skip')} already there")


if __name__ == '__main__':
    main()
