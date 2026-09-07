import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')

for p_num in [390, 393, 394, 395, 396]:
    page = doc[p_num - 58]
    blocks = page.get_text("blocks")
    print(f"==================== PAGE {p_num} (PDF idx {p_num-58}) ====================")
    for idx, b in enumerate(blocks):
        # b: (x0, y0, x1, y1, text, block_no, block_type)
        txt = b[4].strip()
        if not txt:
            continue
        # Print first 2 lines of block
        sample = " // ".join(txt.split('\n')[:3])
        print(f"Block {idx} [bbox: {b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {sample[:120]}")
