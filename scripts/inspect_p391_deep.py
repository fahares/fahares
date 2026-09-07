import pymupdf
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[391 - 58] # 333

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

p391_txt = text.split('<!-- page: 391 -->')[1].split('<!-- page: 392 -->')[0]

print("=== CURRENT TEXT ON PAGE 391 ===")
for idx, l in enumerate(p391_txt.strip().split('\n')):
    if l.strip():
        print(f"{idx+1:02d}: {l.strip()}")

print("\n=== PDF BLOCKS ON PAGE 391 ===")
for idx, b in enumerate(page.get_text("blocks")):
    txt = b[4].strip()
    if txt:
        one_line = " ".join(txt.split())
        print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {one_line[:120]}")
