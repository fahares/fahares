import fitz
import os

doc = fitz.open("sources/pdf/parts/3.pdf")
artifact_dir = "artifacts"

for page_num in range(397, 407):
    pdf_idx = page_num - 58
    page = doc[pdf_idx]
    pix = page.get_pixmap(dpi=200)
    out_path = os.path.join(artifact_dir, f"page_{page_num}.png")
    pix.save(out_path)
    print(f"Saved page_{page_num}.png")
