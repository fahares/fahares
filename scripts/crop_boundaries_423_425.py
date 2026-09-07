import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
out_dir = "artifacts"

# 423 is 365
p423 = doc[365]
r = p423.rect
p423.get_pixmap(clip=fitz.Rect(0, r.height*0.75, r.width*0.55, r.height), dpi=200).save(f"{out_dir}/p423_bottom_left.png")

# 424 is 366
p424 = doc[366]
r4 = p424.rect
p424.get_pixmap(clip=fitz.Rect(r4.width*0.45, 0, r4.width, r4.height*0.35), dpi=200).save(f"{out_dir}/p424_top_right.png")
p424.get_pixmap(clip=fitz.Rect(0, r4.height*0.75, r4.width*0.55, r4.height), dpi=200).save(f"{out_dir}/p424_bottom_left.png")

# 425 is 367
p425 = doc[367]
r5 = p425.rect
p425.get_pixmap(clip=fitz.Rect(r5.width*0.45, 0, r5.width, r5.height*0.35), dpi=200).save(f"{out_dir}/p425_top_right.png")

print("Cropped boundary checks successfully!")
