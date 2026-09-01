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
LATIN_LETTERS = set("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ")
def is_persian_char(c): return '\u0600' <= c <= '\u06FF'

for m in items:
    idx = int(m.group(1))
    vol = int(m.group(2))
    line_num = int(m.group(3))
    current_text = m.group(5).strip()
    proposed_text = m.group(6).strip()
    
    prop_lines = [l.strip() for l in proposed_text.split('\n') if l.strip()]
    for l_str in prop_lines:
        if l_str.startswith('<!--') or l_str.startswith('●'):
            continue
        has_latin = any(c in LATIN_LETTERS for c in l_str)
        if has_latin:
            if l_str.count('(') != l_str.count(')'):
                print(f"[UNBALANCED] Item {idx} (Vol {vol:02d}, Line {line_num}): {l_str}")
            if any(kw in l_str for kw in ['خط:', 'کاغذ:', 'شماره نسخه:', 'کا:', 'تا:']):
                print(f"[SHELFMARK] Item {idx} (Vol {vol:02d}, Line {line_num}): {l_str}")
            persian_words = [w for w in l_str.split() if any(is_persian_char(c) for c in w)]
            latin_words = [w for w in l_str.split() if any(c in LATIN_LETTERS for c in w)]
            if len(persian_words) > 6 and len(latin_words) < len(persian_words):
                print(f"[PERSIAN_IN_TRANSLIT] Item {idx} (Vol {vol:02d}, Line {line_num}): {l_str}")
            cleaned_folio = re.sub(r'\b\d+\s*[abrvpqCc]\b', '', l_str)
            latin_chars = sum(1 for c in cleaned_folio if c in LATIN_LETTERS)
            if latin_chars < 2:
                print(f"[TOO_SHORT] Item {idx} (Vol {vol:02d}, Line {line_num}): {l_str}")
