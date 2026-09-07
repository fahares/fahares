import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[390 - 58] # 332

print("=== ALL BLOCKS OF PAGE 390 ===")
for idx, b in enumerate(page.get_text("blocks")):
    if b[1] < 150: # top of page
        print(f"Block {idx} bbox={b[:4]}:")
        print(b[4])
        print('-'*30)
