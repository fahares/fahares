import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[397 - 58]
for idx, b in enumerate(page.get_text("blocks")):
    txt = b[4].strip()
    if '•' in txt or 'ﺍﺧﺘﻴﺎﺭﺍﺕ' in txt or 'ext' in txt:
        one_line = " ".join(txt.split())
        print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {one_line[:120]}")
