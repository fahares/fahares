import json
import re

with open("reports/batch_17_extracted_raw.json", "r", encoding="utf-8") as f:
    pages = json.load(f)

p417 = pages["417"]
print("=== Page 417 Full Text ===")
print(p417)
