import json
import re

with open("reports/batch_16_extracted_raw.json", "r", encoding="utf-8") as f:
    raw_pages = json.load(f)

# Let's inspect squashed lines in each page of batch 16
for p_str in sorted(raw_pages.keys(), key=int):
    p = int(p_str)
    c = raw_pages[p_str]
    # Check lines that contain "شماره نسخه:" and "آغاز" or "خط:" on the same line
    squashed = []
    for line in c.split("\n"):
        if "شماره نسخه:" in line and any(k in line for k in ["آغاز", "خط:", "انجام:"]):
            squashed.append(line[:80])
    if squashed:
        print(f"Page {p} has {len(squashed)} squashed lines! e.g.: {squashed[0]}")
    else:
        print(f"Page {p}: clean line formatting")
