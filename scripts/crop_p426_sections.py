import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
out_dir = "artifacts"

p426 = doc[368] # 426
r = p426.rect
w, h = r.width, r.height

# Right column mid and bottom
p426.get_pixmap(clip=fitz.Rect(w*0.48, h*0.3, w, h*0.7), dpi=200).save(f"{out_dir}/p426_rm.png")
p426.get_pixmap(clip=fitz.Rect(w*0.48, h*0.65, w, h), dpi=200).save(f"{out_dir}/p426_rb.png")

# Left column mid and bottom
p426.get_pixmap(clip=fitz.Rect(0, h*0.3, w*0.52, h*0.7), dpi=200).save(f"{out_dir}/p426_lm.png")
p426.get_pixmap(clip=fitz.Rect(0, h*0.65, w*0.52, h), dpi=200).save(f"{out_dir}/p426_lb.png")

print("Cropped Page 426 successfully!")
