import json
import re

with open("reports/batch_16_extracted_raw.json", "r", encoding="utf-8") as f:
    pages = json.load(f)

print("=== AUDIT FOR PAGES 407 TO 416 ===")
for p_str in sorted(pages.keys(), key=int):
    p = int(p_str)
    c = pages[p_str]
    lines = [l.strip() for l in c.split("\n") if l.strip()]
    issues = []
    
    # 1. Isolated translit
    for idx, l in enumerate(lines):
        if re.match(r"^[a-zA-Zāīūšžčṭḍṣẓḥ‘'-]+(\s+[a-zA-Zāīūšžčṭḍṣẓḥ‘'-]+)*$", l):
            prev = lines[idx-1] if idx > 0 else ""
            if not (prev.startswith("●") or "قمری" in prev or "–" in prev or "-" in prev):
                issues.append(f"Isolated translit: {l}")
                
    # 2. Numbering sequence
    nums = []
    for l in lines:
        m = re.match(r"^(\d+)\.\s*", l)
        if m:
            nums.append(int(m.group(1)))
    for k in range(len(nums)-1):
        if nums[k+1] < nums[k]:
            issues.append(f"Disordered numbering: {nums[k]} -> {nums[k+1]}")
            
    # 3. Stray slashes / Malformed titles
    for l in lines:
        if l.startswith("●"):
            parts = l.split("/")
            if len(parts) != 3:
                issues.append(f"Malformed title: {l}")
        elif l.startswith("/") or l.endswith("/"):
            issues.append(f"Stray slash: {l}")
            
    # 4. Unbalanced brackets
    if c.count("[") != c.count("]"):
        issues.append(f"Unbalanced brackets: [={c.count('[')}, ]={c.count(']')}")
        
    # 5. Internal word-splits
    splits = re.findall(r"(?:ت\s+هران|مر\s+عشی|گلپای\s+گانی|اخ\s+تیارات)", c)
    if splits:
        issues.append(f"Word splits: {set(splits)}")

    print(f"Page {p}: {len(issues)} issues found. {issues if issues else 'No obvious regex anomaly'}")
