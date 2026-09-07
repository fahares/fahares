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

for p in range(387, 401):
    pdf_idx = p - 58
    page = doc[pdf_idx]
    blocks = page.get_text("blocks")
    txt = text_pages.get(p, '')
    
    print(f"=== PAGE {p} (PDF {pdf_idx}) ===")
    print(f"Text chars: {len(txt)}, PDF blocks: {len(blocks)}")
    
    # Check PDF headers (top block)
    header_block = blocks[0] if blocks else None
    
    # Check titles in PDF blocks
    print("--- PDF Titles / Main Headings ---")
    for b in blocks:
        text_b = b[4].strip()
        lines = [l.strip() for l in text_b.split('\n') if l.strip()]
        for l in lines:
            if ' / ' in l or ' = ' in l or l.startswith('●') or '←' in l:
                print("  PDF:", l)
    
    print("--- TXT Titles / Referrals ---")
    for l in txt.split('\n'):
        l_s = l.strip()
        if l_s.startswith('●') or '←' in l_s:
            print("  TXT:", l_s)
            
    print()
