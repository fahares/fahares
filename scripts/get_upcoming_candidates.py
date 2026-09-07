import re

with open("reports/vol_02_comprehensive_audit.md", "r", encoding="utf-8") as f:
    audit_text = f.read()

# Extract pages in Section 2 (interleaved)
sec2_match = re.search(r"## ۲[^\n]*\n\nتعداد کل صفحات: \*\*(\d+)\*\*[^\n]*\n\n`([^`]+)`", audit_text)
if sec2_match:
    sec2_pages = [int(p.strip()) for p in sec2_match.group(2).split(",") if p.strip().isdigit()]
else:
    sec2_pages = []

# Extract pages in Section 3 (isolated transliteration)
sec3_match = re.search(r"## ۳[^\n]*\n\nتعداد صفحات: \*\*(\d+)\*\*[^\n]*\n\n(.*?)(?=\n---|\Z)", audit_text, re.DOTALL)
sec3_pages = []
if sec3_match:
    for m in re.finditer(r"\*\s*\*\*صفحه\s*(\d+)\*\*", sec3_match.group(2)):
        sec3_pages.append(int(m.group(1)))

next_candidates = sorted(list(set([p for p in sec2_pages + sec3_pages if p > 406])))
print(f"Upcoming flagged problematic pages after page 406 (total {len(next_candidates)}):")
print(next_candidates[:20])
