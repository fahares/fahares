import fitz
import os

doc = fitz.open("sources/pdf/parts/3.pdf")
artifact_dir = "artifacts"

for p in range(407, 417):
    pdf_idx = p - 58
    page = doc[pdf_idx]
    pix = page.get_pixmap(dpi=200)
    out_path = os.path.join(artifact_dir, f"page_{p}.png")
    pix.save(out_path)
    print(f"Rendered page {p} (idx {pdf_idx}) -> {out_path}")
