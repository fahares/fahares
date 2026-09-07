import json

with open('reports/batch_15_extracted_raw.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

for p in range(397, 407):
    p_str = str(p)
    p_data = data[p_str]
    curr = p_data['current_text'].strip()
    blocks = p_data['pdf_blocks']
    
    print(f"==================================================")
    print(f"PAGE {p}")
    print(f"==================================================")
    print("--- CURRENT TEXT (First 500 chars) ---")
    print(curr[:500])
    print("--- CURRENT TEXT (Last 500 chars) ---")
    print(curr[-500:])
    print(f"--- TOTAL CURRENT TEXT LINES: {len(curr.splitlines())} ---")
