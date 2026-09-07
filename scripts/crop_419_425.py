from PIL import Image

# Page 419
img419 = Image.open("artifacts/page_419.png")
w, h = img419.size
# Crop right column and left column of page 419
# In 2-column RTL:
# right col: x from w*0.5 to w, y from top to bottom
# left col: x from 0 to w*0.5, y from top to bottom
# Let's crop middle and bottom where copy 11, 117, 12 appear
# Let's save a crop of bottom right and top/mid left
img419.crop((0, int(h*0.3), int(w*0.55), int(h*0.8))).save("artifacts/p419_crop_left.png")
img419.crop((int(w*0.45), int(h*0.4), w, int(h*0.95))).save("artifacts/p419_crop_right.png")

# Page 425
img425 = Image.open("artifacts/page_425.png")
w25, h25 = img425.size
# Crop left column of 425 where Bakharzi appears
img425.crop((0, int(h25*0.1), int(w25*0.55), int(h25*0.7))).save("artifacts/p425_crop_left.png")
img425.crop((int(w25*0.45), int(h25*0.4), w25, int(h25*0.95))).save("artifacts/p425_crop_right.png")

print("Crops generated successfully!")
