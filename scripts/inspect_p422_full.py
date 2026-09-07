import json

with open("reports/batch_17_extracted_raw.json", "r", encoding="utf-8") as f:
    pages = json.load(f)

print("=== Page 422 Full Text ===")
print(pages["422"])
