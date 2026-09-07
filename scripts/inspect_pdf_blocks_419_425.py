import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")

# Page 419 is pdf_idx 361
p419 = doc[361]
# Let's inspect text blocks on page 419
blocks = p419.get_text("blocks")
print(f"=== Page 419 Text Blocks ({len(blocks)}) ===")
for b in blocks:
    # (x0, y0, x1, y1, text, block_no, block_type)
    txt = b[4].strip().replace('\n', ' ')
    if any(k in txt for k in ['11', '12', '13', '117', 'مرعشی']):
        print(f"  bbox=({b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}) -> {txt[:100]}")

# Page 425 is pdf_idx 367
p425 = doc[367]
blocks25 = p425.get_text("blocks")
print(f"\n=== Page 425 Text Blocks ({len(blocks25)}) ===")
for b in blocks25:
    txt = b[4].strip().replace('\n', ' ')
    if any(k in txt for k in ['باخرزی', 'بدیعی', 'مفتاح', 'سنجر']):
        print(f"  bbox=({b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}) -> {txt[:100]}")
