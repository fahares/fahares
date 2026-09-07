import fitz

doc = fitz.open("sources/pdf/parts/3.pdf")

out_dir = "artifacts"

# Page 419 is pdf_idx 361
p419 = doc[361]
r419 = p419.rect
w, h = r419.width, r419.height

# Right column mid-bottom
clip_419_r = fitz.Rect(w*0.45, h*0.35, w, h*0.95)
p419.get_pixmap(clip=clip_419_r, dpi=200).save(f"{out_dir}/p419_right_crop.png")

# Left column top-mid
clip_419_l = fitz.Rect(0, h*0.1, w*0.55, h*0.7)
p419.get_pixmap(clip=clip_419_l, dpi=200).save(f"{out_dir}/p419_left_crop.png")

# Page 425 is pdf_idx 367
p425 = doc[367]
r425 = p425.rect
w25, h25 = r425.width, r425.height

# Right column mid-bottom
clip_425_r = fitz.Rect(w25*0.45, h25*0.4, w25, h25*0.95)
p425.get_pixmap(clip=clip_425_r, dpi=200).save(f"{out_dir}/p425_right_crop.png")

# Left column top-mid
clip_425_l = fitz.Rect(0, h25*0.1, w25*0.55, h25*0.8)
p425.get_pixmap(clip=clip_425_l, dpi=200).save(f"{out_dir}/p425_left_crop.png")

print("PyMuPDF cropping complete!")
