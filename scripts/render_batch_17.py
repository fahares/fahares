import fitz
import os

pdf_path = "sources/pdf/parts/3.pdf"
doc = fitz.open(pdf_path)

output_dir = "artifacts"

# Pages 417 to 426
# formula: pdf_idx = book_page - 58
for p in range(417, 427):
    pdf_idx = p - 58
    page = doc[pdf_idx]
    pix = page.get_pixmap(dpi=200)
    out_path = os.path.join(output_dir, f"page_{p}.png")
    pix.save(out_path)
    print(f"Rendered book page {p} (pdf index {pdf_idx}) -> {out_path}")

print("Batch 17 rendering complete!")
