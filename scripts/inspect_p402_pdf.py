import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[402 - 58]

print("=== ALL BLOCKS OF PAGE 402 PDF ===")
for idx, b in enumerate(page.get_text("blocks")):
    txt = b[4].strip()
    if not txt:
        continue
    one_line = " ".join(txt.split())
    print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {one_line[:120]}")
