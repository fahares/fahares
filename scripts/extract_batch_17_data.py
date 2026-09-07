import re
import json

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

# Let's extract each page 417..426
batch_17_raw = {}
for p in range(417, 427):
    # Find tag p and next tag p+1
    tag_curr = f"<!-- page: {p} -->"
    tag_next = f"<!-- page: {p+1} -->"
    
    pos_curr = text.find(tag_curr)
    pos_next = text.find(tag_next)
    
    if pos_curr == -1 or pos_next == -1:
        print(f"Error finding tags for page {p}")
        continue
    
    # Text belonging to page p starts right after tag_curr and goes until tag_next
    p_content = text[pos_curr + len(tag_curr):pos_next]
    batch_17_raw[str(p)] = p_content

with open("reports/batch_17_extracted_raw.json", "w", encoding="utf-8") as f:
    json.dump(batch_17_raw, f, ensure_ascii=False, indent=2)

print("Batch 17 raw extracted successfully!")
for p in range(417, 427):
    print(f"Page {p} length: {len(batch_17_raw[str(p)])} chars")
