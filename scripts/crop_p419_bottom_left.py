import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
p419 = doc[361]
r = p419.rect
# crop bottom half of left column
p419.get_pixmap(clip=fitz.Rect(0, r.height*0.5, r.width*0.55, r.height), dpi=200).save(
    "artifacts/p419_bottom_left.png"
)
