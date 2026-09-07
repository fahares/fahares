# -*- coding: utf-8 -*-
"""
Blazing fast column transition scan for all 1,015 pages of Volume 2.
Directly maps lines in fahares_vol_02.txt to JSON bounding-box coordinates.
"""
import json, re, sys, time

start_time = time.time()

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    full_text = f.read()

# Map page number to text
page_texts = {}
for m in re.finditer(r'<!--\s*page:\s*(\d+)\s*-->([\s\S]*?)(?=<!--\s*page:|\Z)', full_text):
    page_texts[int(m.group(1))] = m.group(2)

print(f"Loaded {len(page_texts)} pages from text file.", flush=True)

results = {}

# Process each JSON chunk
chunks = [
    ('sources/json/2.json', 7, 57, lambda p: p + 442),
    ('sources/json/3.json', 58, 557, lambda p: p - 58),
    ('sources/json/4.json', 558, 1021, lambda p: p - 558),
]

span_pattern = re.compile(r'<span[^>]*data-bbox=\"(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\"[^>]*>(.*?)</span>', re.DOTALL)
tag_pattern = re.compile(r'<[^>]+>')

for jpath, p_start, p_end, idx_fn in chunks:
    t0 = time.time()
    print(f"\nProcessing {jpath} (pages {p_start}..{p_end})...", flush=True)
    with open(jpath, 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    # Index children by page
    children_by_page = {}
    for c in data['children']:
        p_idx = c.get('page')
        if p_idx is not None:
            children_by_page[p_idx] = c
            
    print(f"  JSON loaded and indexed in {time.time() - t0:.2f}s. Scanning pages...", flush=True)
    
    for p in range(p_start, p_end + 1):
        if p not in page_texts:
            continue
            
        pidx = idx_fn(p)
        child = children_by_page.get(pidx)
        if not child:
            continue
            
        # Extract spans
        spans = []
        for m in span_pattern.finditer(child['html']):
            x0, y0, x1, y1 = map(int, m.groups()[:4])
            t = tag_pattern.sub('', m.group(5)).strip()
            if t and 245 <= y0 <= 2150:
                spans.append(((x0 + x1) / 2, y0, t))
                
        if not spans:
            continue
            
        # For each non-empty line in p_text, find which column it belongs to
        p_text = page_texts[p]
        line_cols = []
        
        for line in p_text.splitlines():
            l = line.strip()
            if len(l) < 6:
                continue
            words = [w for w in re.findall(r'[\u0600-\u06FF]{3,}', l)][:5]
            if not words:
                # Try latin words
                words = [w for w in re.findall(r'[a-zA-Z]{4,}', l)][:4]
            if not words:
                continue
                
            matched_xs = [s[0] for s in spans if any(w in s[2] for w in words)]
            if matched_xs:
                avg_x = sum(matched_xs) / len(matched_xs)
                col = 'R' if avg_x >= 760 else 'L'
                if not line_cols or line_cols[-1] != col:
                    line_cols.append(col)
                    
        transitions = len(line_cols) - 1
        results[p] = (transitions, line_cols)

print("\n" + "=" * 60)
print("AUDIT RESULTS: COLUMN TRANSITIONS ACROSS ALL 1,015 PAGES")
print("=" * 60)

interleaved = {p: info for p, info in results.items() if info[0] > 1}
print(f"Total pages scanned: {len(results)}")
print(f"Total pages with > 1 transition (Interleaved): {len(interleaved)}")

print("\nInterleaved Pages by transition count:")
for p in sorted(interleaved.keys()):
    trans, cols = interleaved[p]
    print(f"  Page {p:4d}: {trans:2d} transitions | sequence: {' -> '.join(cols[:8])}...")

