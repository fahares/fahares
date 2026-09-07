import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')

for p_num in [399, 400]:
    page = doc[p_num - 58]
    print(f"============================== PDF PAGE {p_num} ==============================")
    blocks = page.get_text("blocks")
    for idx, b in enumerate(blocks):
        txt = b[4].strip()
        if not txt:
            continue
        one_line = " ".join(txt.split())
        print(f"B{idx:02d} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {one_line[:120]}")
