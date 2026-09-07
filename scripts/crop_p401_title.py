import pymupdf

doc = pymupdf.open('sources/pdf/parts/3.pdf')
# Page 401 -> pdf_idx = 401 - 58 = 343
page = doc[343]

# Let's crop around B15/B16: y from 260 to 380, x from 250 to 550
rect = pymupdf.Rect(250, 260, 550, 380)
pix = page.get_pixmap(clip=rect, dpi=200)
pix.save('reports/p401_b15_crop.png')
print("Saved reports/p401_b15_crop.png")
