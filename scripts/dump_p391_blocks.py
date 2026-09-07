import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[391 - 58] # 333

blocks = page.get_text("blocks")

print("=== ALL BLOCKS OF PAGE 391 WITH POSITIONS ===")
for idx, b in enumerate(blocks):
    txt = b[4].strip()
    if not txt:
        continue
    # print bbox and text
    print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]:")
    print(txt)
    print("="*40)
