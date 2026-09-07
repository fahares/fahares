import fitz

for part in [3, 4]:
    doc = fitz.open(f"sources/pdf/parts/{part}.pdf")
    print(f"part {part}.pdf has {len(doc)} pages")
