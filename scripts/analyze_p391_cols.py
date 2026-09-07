import pymupdf
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')
page = doc[391 - 58]

blocks = page.get_text("blocks")

# Separate columns
# Right column: x0 >= 275
# Left column: x0 < 275
# Exclude page header y < 95
right_blocks = [b for b in blocks if b[1] >= 95 and b[0] >= 275 and b[4].strip()]
left_blocks = [b for b in blocks if b[1] >= 95 and b[0] < 275 and b[4].strip()]

right_blocks.sort(key=lambda b: b[1])
left_blocks.sort(key=lambda b: b[1])

print("=== RIGHT COLUMN BLOCKS ===")
for idx, b in enumerate(right_blocks):
    one_line = " ".join(b[4].split())
    print(f"R{idx:02d} [y={b[1]:.1f}..{b[3]:.1f}]: {one_line}")

print("\n=== LEFT COLUMN BLOCKS ===")
for idx, b in enumerate(left_blocks):
    one_line = " ".join(b[4].split())
    print(f"L{idx:02d} [y={b[1]:.1f}..{b[3]:.1f}]: {one_line}")
