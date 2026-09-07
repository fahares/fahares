# -*- coding: utf-8 -*-
"""
Scan all 1,015 pages of Volume 2 for Column Transitions.
A clean page has transitions <= 1 (R... -> L...).
Any page with transitions > 1 is interleaved.
"""

import json, re, sys, time

start_time = time.time()

def get_json_and_idx(p):
    if 7 <= p <= 57:
        return 'sources/json/2.json', p + 442
    elif 58 <= p <= 557:
        return 'sources/json/3.json', p - 58
    else:
        return 'sources/json/4.json', p - 558

print("Loading sources/text/fahares_vol_02.txt...")
with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

# Parse all pages
page_matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->([\s\S]*?)(?=<!--\s*page:|\Z)', text))
print(f"Total pages in text: {len(page_matches)}")

# Load JSONs on demand with cache
json_cache = {}
def get_page_spans(p):
    jpath, pidx = get_json_and_idx(p)
    if jpath not in json_cache:
        print(f"Loading {jpath} into memory...")
        with open(jpath, 'r', encoding='utf-8') as f:
            json_cache[jpath] = json.load(f)
    d = json_cache[jpath]
    elems = [c for c in d['children'] if c.get('page') == pidx]
    if not elems:
        return []
    elem = elems[0]
    pattern = re.compile(r'<span[^>]*data-bbox=\"(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\"[^>]*>(.*?)</span>', re.DOTALL)
    spans = []
    for m in pattern.finditer(elem['html']):
        x0, y0, x1, y1 = map(int, m.groups()[:4])
        t = re.sub(r'<[^>]+>', '', m.group(5)).strip()
        if t and 245 <= y0 <= 2150:
            spans.append((x0, y0, x1, y1, t))
    return spans

interleaved_pages = []

print("Scanning all pages for column transitions...")
for idx, match in enumerate(page_matches):
    p = int(match.group(1))
    p_text = match.group(2)
    
    spans = get_page_spans(p)
    if not spans:
        continue
        
    line_cols = []
    for line in p_text.splitlines():
        l = line.strip()
        if len(l) < 8:
            continue
        words = [w for w in re.findall(r'[\u0600-\u06FF]{3,}', l)][:6]
        if not words:
            continue
        matched = [s for s in spans if any(w in s[4] for w in words)]
        if matched:
            avg_x = sum((s[0]+s[2])/2 for s in matched) / len(matched)
            col = 'R' if avg_x >= 760 else 'L'
            if not line_cols or line_cols[-1] != col:
                line_cols.append(col)
                
    # In line_cols, transitions is len(line_cols) - 1
    transitions = len(line_cols) - 1
    if transitions > 1:
        interleaved_pages.append((p, transitions, line_cols))
        print(f"  FOUND INTERLEAVED PAGE {p}: {transitions} transitions ({' -> '.join(line_cols[:8])}...)")

print("=" * 60)
print(f"Scan complete in {time.time() - start_time:.2f}s")
print(f"Total interleaved pages detected: {len(interleaved_pages)}")
print("List of pages:")
print([p for p, t, cols in interleaved_pages])
