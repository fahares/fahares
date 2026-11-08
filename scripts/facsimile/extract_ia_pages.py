#!/usr/bin/env python3
"""Render a source's printed pages from its scan PDFs to WebP for the page viewer (step 14.86), by the mapping
align_ia_scans.py made: storage/app/public/catalogs/{code}/v{NN}/p{NNNN}.webp, grayscale, 1240 px wide like the
other sources, named by printed page. The PDFs are ../fahares-sources/{code}/scans/{NN}.pdf beside the checkout (or
beside the main checkout, from a worktree). Existing files are skipped, so a run resumes; --out writes elsewhere.

    python3 -I scripts/facsimile/extract_ia_pages.py CODE MAPPING.json [--volumes 1 2] [--workers 6] [--out DIR]
"""
import argparse
import json
import os
import subprocess
import tempfile
from concurrent.futures import ProcessPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))


def sources_dir(code):
    return next(p for p in (os.path.abspath(os.path.join(REPO, *['..'] * n, 'fahares-sources', code, 'scans')) for n in (1, 4))
                if os.path.isdir(p))


def render(job):
    pdf, leaf, target = job
    if os.path.exists(target):
        return 'skip'
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        base = os.path.join(tmp, 'page')
        subprocess.run(['pdftoppm', '-f', str(leaf), '-l', str(leaf), '-scale-to-x', '1240', '-scale-to-y', '-1', '-gray', '-singlefile',
                        pdf, base], check=True)
        subprocess.run(['cwebp', '-quiet', '-q', '75', base + '.pgm', '-o', target + '.tmp'], check=True)
    os.replace(target + '.tmp', target)
    return 'done'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('code')
    parser.add_argument('mapping')
    parser.add_argument('--volumes', type=int, nargs='*')
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--out')
    args = parser.parse_args()
    scans = sources_dir(args.code)
    out = args.out or os.path.join(REPO, 'storage', 'app', 'public', 'catalogs', args.code)
    with open(args.mapping) as f:
        mapping = json.load(f)
    jobs = [(os.path.join(scans, f'{pdf}.pdf'), leaf, os.path.join(out, f'v{v}', f'p{int(p):04d}.webp'))
            for v, pages in mapping.items() if not args.volumes or int(v) in args.volumes for p, (pdf, leaf) in pages.items()]
    with ProcessPoolExecutor(args.workers) as pool:
        results = list(pool.map(render, jobs, chunksize=8))
    print(f"{args.code}: {len(jobs)} pages, {results.count('done')} rendered, {results.count('skip')} already there")


if __name__ == '__main__':
    main()
