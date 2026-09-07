import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
out_dir = "artifacts"

# 421 is 363
p421 = doc[363]
r = p421.rect
p421.get_pixmap(clip=fitz.Rect(r.width*0.45, 0, r.width, r.height*0.5), dpi=200).save(f"{out_dir}/p421_top_right.png")
p421.get_pixmap(clip=fitz.Rect(0, r.height*0.25, r.width*0.55, r.height*0.75), dpi=200).save(f"{out_dir}/p421_mid_left.png")

print("Cropped page 421 successfully!")
