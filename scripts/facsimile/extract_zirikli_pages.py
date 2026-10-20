"""Render al-A'lam pages to WebP for the catalog page viewer.

Each printed page in zirikli_page_mapping.json is rendered from its PDF leaf (bilevel JBIG2 scans, about
2550 x 3350 px at 300 dpi, already trimmed close to the text block) in grayscale, 1240 px wide like the Fankha
pages, to storage/app/public/catalogs/zirikli/v{NN}/p{NNNN}.webp, named by printed page number.
Existing files are skipped, so the run can be resumed. --out writes elsewhere (from a worktree, the main
checkout's storage, which the local site serves).

    python3 extract_zirikli_pages.py [--volumes 1 8] [--workers 6] [--out DIR]
"""
import argparse
import json
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
# beside the checkout (or beside the main checkout, from a worktree in .claude/worktrees)
SOURCES = next(p for p in (os.path.abspath(os.path.join(REPO, *['..'] * n, 'fahares-sources', 'zirikli')) for n in (1, 4))
               if os.path.isdir(p))
OUT = os.path.join(REPO, 'storage', 'app', 'public', 'catalogs', 'zirikli')


def render(job):
    volume, page, part, leaf, out = job
    target = os.path.join(out, f'v{volume}', f'p{int(page):04d}.webp')
    if os.path.exists(target):
        return 'skip'
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-scale-to-x', '1240', '-scale-to-y', '-1', '-gray', '-singlefile',
                        os.path.join(SOURCES, f'{part}.pdf'), base], check=True)
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--volumes', type=int, nargs='*')
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--out', default=OUT)
    args = parser.parse_args()

    with open(os.path.join(HERE, 'zirikli_page_mapping.json')) as f:
        mapping = json.load(f)
    jobs = [(v, p, part, leaf, args.out) for v, pages in mapping.items() if not args.volumes or int(v) in args.volumes
            for p, (part, leaf) in pages.items()]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    print(f"{len(jobs)} pages: {results.count('done')} rendered, {results.count('skip')} already there")


if __name__ == '__main__':
    main()
