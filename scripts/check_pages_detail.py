import pymupdf
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')
with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    full_text = f.read()

pages_raw = re.split(r'(<!-- page: \d+ -->)', full_text)
text_pages = {}
for i in range(1, len(pages_raw), 2):
    p_num = int(re.search(r'\d+', pages_raw[i]).group(0))
    text_pages[p_num] = pages_raw[i+1]

for p in [391, 392, 397, 398, 399, 400]:
    txt = text_pages.get(p, '')
    lines = [l for l in txt.split('\n') if l.strip()]
    
    # Check shelfmarks
    shelfs = re.findall(r'^(\d+)\.', txt, re.M)
    
    # Check if any lines have severe issues (like broken words, OCR artifacts)
    print(f"=== Page {p} ===")
    print(f"Lines count: {len(lines)}, Shelfs count: {len(shelfs)}")
    if shelfs:
        print(f"Shelfs: {shelfs[0]} .. {shelfs[-1]}")
    bullets = [l for l in lines if l.startswith('●')]
    print(f"Bullets: {len(bullets)}")
    for b in bullets:
        print(f"  {b}")
    # Print sample start and end
    print("START:", lines[:2])
    print("END:", lines[-2:])
    print()
