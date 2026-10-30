#!/usr/bin/env python3
"""Page texts of Fankha («فهرستگان نسخه‌های خطی ایران») from the corpus, for sources:import-pages and the site's page
text (plan docs/plans/source-page-text.md).

    python3 -I scripts/sources/fankha_pages.py CORPUS_TEXT_DIR OUT_DIR [--volumes 1,2]

Reads CORPUS_TEXT_DIR/fankha_vol_{NN}.txt and writes OUT_DIR/{NN}.jsonl.gz with {"page", "text"} per printed page.
The corpus text is the one the site was made from: the OCR of the print, corrected, each page opened by
<!-- page: N -->, its two columns run on (the right one, then the left) and the running head left out. A page whose
marker comes twice keeps both parts in order.
"""
import argparse
import gzip
import json
import re
from pathlib import Path

PAGE = re.compile(r'<!--\s*page:\s*(\d+)\s*-->')


def volume(path: Path) -> list[dict]:
    pages: dict[int, list[str]] = {}
    parts = PAGE.split(path.read_text(encoding='utf-8'))
    # parts: [text before the first marker, page, text, page, text, …]
    for i in range(1, len(parts), 2):
        page, text = int(parts[i]), parts[i + 1].strip()
        pages.setdefault(page, []).append(text)
    return [{'page': p, 'text': '\n\n'.join(t for t in texts if t)} for p, texts in sorted(pages.items())]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('corpus')
    ap.add_argument('out')
    ap.add_argument('--volumes')
    args = ap.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    wanted = {int(v) for v in args.volumes.split(',')} if args.volumes else None
    for path in sorted(Path(args.corpus).glob('fankha_vol_*.txt')):
        number = int(re.search(r'(\d+)', path.stem).group(1))
        if wanted and number not in wanted:
            continue
        pages = volume(path)
        with gzip.open(out / f'{number:02d}.jsonl.gz', 'wt', encoding='utf-8') as f:
            for row in pages:
                f.write(json.dumps(row, ensure_ascii=False) + '\n')
        print(f'v{number}: {len(pages)} pages {pages[0]["page"]}-{pages[-1]["page"]}, '
              f'{sum(len(p["text"]) for p in pages):,} characters, {sum(1 for p in pages if not p["text"])} empty')


if __name__ == '__main__':
    main()
