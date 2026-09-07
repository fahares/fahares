import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")
out_dir = "artifacts"

p424 = doc[366] # pdf_idx for 424
r = p424.rect
w, h = r.width, r.height

# Right column top & bottom
p424.get_pixmap(clip=fitz.Rect(w*0.48, 0, w, h*0.52), dpi=200).save(f"{out_dir}/p424_rt.png")
p424.get_pixmap(clip=fitz.Rect(w*0.48, h*0.48, w, h), dpi=200).save(f"{out_dir}/p424_rb.png")

# Left column top & bottom
p424.get_pixmap(clip=fitz.Rect(0, 0, w*0.52, h*0.52), dpi=200).save(f"{out_dir}/p424_lt.png")
p424.get_pixmap(clip=fitz.Rect(0, h*0.48, w*0.52, h), dpi=200).save(f"{out_dir}/p424_lb.png")

print("Cropped Page 424 into 4 quadrants!")
