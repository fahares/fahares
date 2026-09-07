import json
import re

with open("reports/batch_17_reconstructed.json", "r", encoding="utf-8") as f:
    d = json.load(f)

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

p417_pos = text.find("<!-- page: 417 -->")
p427_pos = text.find("<!-- page: 427 -->")

print(f"p417_pos: {p417_pos}, p427_pos: {p427_pos}")

# Assemble block
# 417 starts after <!-- page: 417 -->
block = "<!-- page: 417 --> " + d["417"].strip()

# 417 -> 418 (inline)
block += " <!-- page: 418 --> " + d["418"].strip()

# 418 -> 419 (standalone)
block += "\n\n<!-- page: 419 -->\n\n" + d["419"].strip()

# 419 -> 420 (inline)
block += " <!-- page: 420 --> " + d["420"].strip()

# 420 -> 421 (standalone)
block += "\n\n<!-- page: 421 -->\n\n" + d["421"].strip()

# 421 -> 422 (inline)
block += " <!-- page: 422 --> " + d["422"].strip()

# 422 -> 423 (inline)
block += " <!-- page: 423 --> " + d["423"].strip()

# 423 -> 424 (inline)
block += " <!-- page: 424 --> " + d["424"].strip()

# 424 -> 425 (inline header to body)
block += " <!-- page: 425 -->\n" + d["425"].strip()

# 425 -> 426 (inline transition)
block += "\n<!-- page: 426 -->\n" + d["426"].strip()

# After 426, it connects to <!-- page: 427 -->
# text[:p417_pos] already ends with '... جلد: حنایی '
# text[p427_pos:] starts with '<!-- page: 427 --> معادل آنها...'
# So block should end with a space before <!-- page: 427 -->
block += " "

new_text = text[:p417_pos] + block + text[p427_pos:]

# Verify tags
tags = re.findall(r'<!-- page: (\d+) -->', new_text)
print(f"Total tags: {len(tags)}")
nums = [int(t) for t in tags]
expected = list(range(7, 1022))
if nums == expected:
    print("SUCCESS: Exact 1,015 continuous pages from 7 to 1021!")
else:
    print("Mismatch in tags!")

print("\n--- Inspecting boundaries in new text ---")
for p in range(417, 428):
    tag = f"<!-- page: {p} -->"
    idx = new_text.find(tag)
    before = new_text[max(0, idx-40):idx]
    after = new_text[idx+len(tag):idx+len(tag)+40]
    print(f"Tag {p}: {repr(before)} + TAG + {repr(after)}")
