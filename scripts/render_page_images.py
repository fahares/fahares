import fitz
import os

doc = fitz.open("sources/pdf/parts/3.pdf")
artifact_dir = "artifacts"

pages_to_render = [397, 398, 399, 400, 401]

for page_num in pages_to_render:
    pdf_idx = page_num - 58
    page = doc[pdf_idx]
    pix = page.get_pixmap(dpi=200)
    out_path = os.path.join(artifact_dir, f"page_{page_num}.png")
    pix.save(out_path)
    print(f"Rendered page {page_num} (idx {pdf_idx}) -> {out_path}")
