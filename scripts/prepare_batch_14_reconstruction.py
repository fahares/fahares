import pymupdf
import json
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')

def get_page_blocks(p_num):
    page = doc[p_num - 58]
    blocks = page.get_text("blocks")
    # Separate right column (x0 >= 280) and left column (x0 < 280)
    # Header block is usually at top (y0 < 95)
    body_blocks = [b for b in blocks if b[1] >= 95 and b[3] <= 760 and b[4].strip()]
    
    right_col = [b for b in body_blocks if b[0] >= 275]
    left_col = [b for b in body_blocks if b[0] < 275]
    
    # Sort top to bottom
    right_col.sort(key=lambda b: b[1])
    left_col.sort(key=lambda b: b[1])
    
    return right_col, left_col

for p in [390, 393, 394, 395, 396]:
    r, l = get_page_blocks(p)
    print(f"=== Page {p}: Right col blocks={len(r)}, Left col blocks={len(l)} ===")
