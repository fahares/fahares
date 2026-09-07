import pymupdf
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

for p in range(401, 407):
    print(f"==================== PAGE {p} ====================")
    pdf_p = doc[p - 58]
    txt_c = text.split(f'<!-- page: {p} -->')[1].split(f'<!-- page: {p+1} -->')[0]
    
    txt_shelfs = re.findall(r'^(\d+)\.', txt_c, re.M)
    txt_bullets = re.findall(r'^● (.*)', txt_c, re.M)
    
    # Check PDF blocks
    blocks = pdf_p.get_text("blocks")
    body = [b for b in blocks if b[1] >= 95 and b[3] <= 760 and b[4].strip()]
    pdf_shelfs = []
    for b in body:
        for l in b[4].split('\n'):
            m = re.match(r'^(\d+|[۰-۹]+)\.\s*', l.strip())
            if m:
                pdf_shelfs.append(m.group(1))
                
    print(f"TXT shelfs ({len(txt_shelfs)}): {txt_shelfs}")
    print(f"PDF shelfs ({len(pdf_shelfs)}): {pdf_shelfs}")
    print(f"TXT bullets: {txt_bullets}")
