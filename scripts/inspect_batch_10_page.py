import json
import sys

with open("reports/batch_10_extracted_raw.json") as f:
    data = json.load(f)

page_num = sys.argv[1]
p_data = data[page_num]

print(f"=== PAGE {page_num} RIGHT COLUMN ===")
for idx, b in enumerate(p_data["right"]):
    cleaned = " ".join(b.split())
    print(f"R{idx:02d}: {cleaned}")

print(f"\n=== PAGE {page_num} LEFT COLUMN ===")
for idx, b in enumerate(p_data["left"]):
    cleaned = " ".join(b.split())
    print(f"L{idx:02d}: {cleaned}")
