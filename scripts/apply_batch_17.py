import json
import re
import subprocess

with open("reports/batch_17_reconstructed.json", "r", encoding="utf-8") as f:
    d = json.load(f)

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

p417_pos = text.find("<!-- page: 417 -->")
p427_pos = text.find("<!-- page: 427 -->")

if p417_pos == -1 or p427_pos == -1:
    raise ValueError("Could not find p417 or p427 tag positions!")

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
block += " "

new_text = text[:p417_pos] + block + text[p427_pos:]

# Verify tags
tags = re.findall(r'<!-- page: (\d+) -->', new_text)
assert len(tags) == 1015, f"Expected 1015 tags, found {len(tags)}"
nums = [int(t) for t in tags]
expected = list(range(7, 1022))
assert nums == expected, "Tag continuity error!"

# Write new text
with open("sources/text/fahares_vol_02.txt", "w", encoding="utf-8") as f:
    f.write(new_text)

print("Successfully wrote Batch 17 updates to sources/text/fahares_vol_02.txt")
