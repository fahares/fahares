import pymupdf
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

pages_raw = re.split(r'(<!-- page: \d+ -->)', text)
text_pages = {}
for i in range(1, len(pages_raw), 2):
    p_num = int(re.search(r'\d+', pages_raw[i]).group(0))
    text_pages[p_num] = pages_raw[i+1]

for p in range(387, 399):
    pdf_idx = p - 58
    page = doc[pdf_idx]
    pdf_text = page.get_text()
    txt = text_pages.get(p, '')
    
    # Check shelfmarks in txt
    txt_shelfs = [int(m) for m in re.findall(r'^(\d+)\.', txt, re.M)]
    
    # Check bullets
    txt_bullets = re.findall(r'^[ \t]*●[ \t]*(.*)', txt, re.M)
    
    # Check for isolated transliterations: lines with [a-z] that don't follow a title or author
    lines = [l.strip() for l in txt.split('\n') if l.strip()]
    isolated_trans = []
    for idx, l in enumerate(lines):
        if re.search(r'^[a-zāīūḍṣṭẓḥšžč\'-]+', l) and not l.startswith('http'):
            # check previous line
            prev = lines[idx-1] if idx > 0 else ''
            if not (prev.startswith('●') or re.search(r'[\u0600-\u06FF]', prev)):
                isolated_trans.append((idx, prev, l))
    
    # Check broken shelfmarks or sequence gaps
    gaps = []
    if txt_shelfs:
        for i in range(len(txt_shelfs)-1):
            if txt_shelfs[i+1] != txt_shelfs[i] + 1 and txt_shelfs[i+1] != 1:
                gaps.append(f"{txt_shelfs[i]} -> {txt_shelfs[i+1]}")
                
    print(f"Page {p}: len={len(txt)}, shelfs={len(txt_shelfs)} (min={min(txt_shelfs) if txt_shelfs else 0}, max={max(txt_shelfs) if txt_shelfs else 0}), gaps={gaps}, bullets={len(txt_bullets)}")
    if txt_bullets:
        for b in txt_bullets:
            print(f"   ● {b}")
    if isolated_trans:
        print(f"   Isolated transliterations: {isolated_trans}")
    if 'موعشی' in txt:
        print("   Found موعشی!")
    if 'قمر؛' in txt:
        print("   Found قمر؛!")
    if 'غوب' in txt:
        print("   Found غوب!")
