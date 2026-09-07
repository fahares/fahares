import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
p426 = doc[368]
r = p426.rect
# crop top of page 426
p426.get_pixmap(clip=fitz.Rect(0, 0, r.width, r.height*0.35), dpi=200).save(
    "artifacts/p426_top.png"
)
