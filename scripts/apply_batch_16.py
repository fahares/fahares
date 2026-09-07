import json
import re

with open("reports/batch_16_reconstructed.json", "r", encoding="utf-8") as f:
    batch_16_dict = json.load(f)

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

p407_pos = text.find("<!-- page: 407 -->")
p417_pos = text.find("<!-- page: 417 -->")

if p407_pos == -1 or p417_pos == -1:
    print(f"ERROR: Could not locate page tags (407: {p407_pos}, 417: {p417_pos})")
    exit(1)

# Build reconstructed block for 407..416
reconstructed_pages = []
for p in range(407, 417):
    p_content = batch_16_dict[str(p)]
    reconstructed_pages.append(p_content.strip())

replacement_block = "\n\n".join(reconstructed_pages) + "\n"

new_text = text[:p407_pos] + replacement_block + text[p417_pos:]

with open("sources/text/fahares_vol_02.txt", "w", encoding="utf-8") as f:
    f.write(new_text)

print("Successfully applied Batch 16 reconstruction to fahares_vol_02.txt")
