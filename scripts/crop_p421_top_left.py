import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
p421 = doc[363]
r = p421.rect
p421.get_pixmap(clip=fitz.Rect(0, 0, r.width*0.55, r.height*0.35), dpi=200).save(
    "artifacts/p421_top_left.png"
)
print("Cropped page 421 top left!")
