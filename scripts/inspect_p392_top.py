import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[392 - 58]

print("=== TOP OF PAGE 392 PDF ===")
for idx, b in enumerate(page.get_text("blocks")):
    if b[1] < 150:
        print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {' '.join(b[4].split())}")
