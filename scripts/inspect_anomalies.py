import re

with open('reports/candidate_isolated_transliterations.md', 'r', encoding='utf-8') as f:
    content = f.read()

item_pattern = re.compile(
    r'### شماره (\d+) \(جلد (\d+) - سطر (\d+) \| تعداد قطعات تفکیک‌شده: (\d+)\)\n\n'
    r'🔴 \*\*وضعیت فعلی:\*\*\n```text\n(.*?)\n```\n\n'
    r'🟢 \*\*اصلاح پیشنهادی:\*\*\n```text\n(.*?)\n```',
    re.DOTALL
)

items = list(item_pattern.finditer(content))

LATIN_LETTERS = set(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
)

def is_persian_char(c):
    return '\u0600' <= c <= '\u06FF'

print("=== ALL POTENTIAL ANOMALIES TO FIX ===")
for m in items:
    idx = int(m.group(1))
    vol = int(m.group(2))
    line_num = int(m.group(3))
    current_text = m.group(5).strip()
    proposed_text = m.group(6).strip()

    # Check 1: No Latin at all in proposed
    if not any(c in LATIN_LETTERS for c in current_text):
        print(f"[NO LATIN] Item {idx} (Vol {vol:02d}, Line {line_num}):\n  Current: {current_text}\n  Proposed: {proposed_text}\n")
        continue

    # Check 2: Persian author glued to translit in a single proposed block
    prop_lines = [l.strip() for l in proposed_text.split('\n') if l.strip()]
    for l in prop_lines:
        has_latin = any(c in LATIN_LETTERS for c in l)
        has_persian = any(is_persian_char(c) for c in l)
        if has_latin and has_persian and not l.startswith('●'):
            persian_words = [w for w in l.split() if any(is_persian_char(c) for c in w)]
            latin_words = [w for w in l.split() if any(c in LATIN_LETTERS for c in w)]
            if len(persian_words) >= 3 and len(latin_words) >= 2:
                print(f"[MIXED PERSIAN-LATIN LINE] Item {idx} (Vol {vol:02d}, Line {line_num}):\n  Line: {l}\n")
