import re
import os
from collections import Counter

with open('reports/candidate_isolated_transliterations.md', 'r', encoding='utf-8') as f:
    content = f.read()

item_pattern = re.compile(
    r'### شماره (\d+) \(جلد (\d+) - سطر (\d+) \| تعداد قطعات تفکیک‌شده: (\d+)\)\n\n'
    r'🔴 \*\*وضعیت فعلی:\*\*\n```text\n(.*?)\n```\n\n'
    r'🟢 \*\*اصلاح پیشنهادی:\*\*\n```text\n(.*?)\n```',
    re.DOTALL
)

items = list(item_pattern.finditer(content))
print(f'Total parsed candidate items: {len(items)}')

LATIN_LETTERS = set(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
)

def is_persian_char(c):
    return '\u0600' <= c <= '\u06FF'

def is_persian_author_line(line):
    clean = line.strip()
    if not clean or len(clean) > 160 or not is_persian_char(clean[0]):
        return False
    if clean.startswith('●') or clean.startswith('[') or clean.startswith('('):
        return False
    if '،' in clean and any(k in clean for k in [' بن ', ' ابن ', ' ق ', ' قرن ', ' قمری', ' شمسی']):
        return True
    return False

anomalies = {
    'content_loss': [],
    'page_tag_mismatch': [],
    'unbalanced_parentheses': [],
    'unrepaired_bidi': [],
    'shelfmark_leak': [],
    'persian_in_translit': [],
    'too_short_translit': [],
    'extra_blank_lines_in_title_translit': []
}

ALLOWED_STRIPPED_CHARS = set("-–=.,;:'\"()[]«» ‘ʻʼʿʾ`’")

for m in items:
    idx = int(m.group(1))
    vol = int(m.group(2))
    line_num = int(m.group(3))
    blocks_count = int(m.group(4))
    current_text = m.group(5).strip()
    proposed_text = m.group(6).strip()

    # 1. Page tag conservation
    current_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', current_text)
    proposed_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', proposed_text)
    if current_pages != proposed_pages:
        anomalies['page_tag_mismatch'].append((idx, vol, line_num, current_pages, proposed_pages))

    # 2. Content conservation
    c_clean = re.sub(r'\s+', '', current_text)
    p_clean = re.sub(r'\s+', '', proposed_text)
    c_clean_norm = re.sub(r'\([عصس]\)|\(عج\)', '', c_clean)
    p_clean_norm = re.sub(r'\([عصس]\)|\(عج\)', '', p_clean)
    
    c_cnt = Counter(c_clean_norm)
    p_cnt = Counter(p_clean_norm)
    diff = c_cnt - p_cnt
    if diff:
        sig_diff = {k: v for k, v in diff.items() if k not in ALLOWED_STRIPPED_CHARS}
        if sig_diff:
            anomalies['content_loss'].append((idx, vol, line_num, sig_diff, current_text, proposed_text))

    # 3. Transliteration lines checks
    prop_lines = [l.strip() for l in proposed_text.split('\n') if l.strip()]
    
    for l_str in prop_lines:
        if l_str.startswith('<!--') or l_str.startswith('●') or is_persian_author_line(l_str):
            continue
        
        # Remove page tag from line to test the text content
        l_no_tag = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', l_str).strip()
        has_latin = any(c in LATIN_LETTERS for c in l_no_tag)

        if has_latin:
            # 3a. Parentheses balance
            if l_str.count('(') != l_str.count(')'):
                anomalies['unbalanced_parentheses'].append((idx, vol, line_num, l_str))

            # 3b. Unrepaired BiDi distortion: '(1600 - ' or '(- author 17c)'
            if re.match(r'^\(\s*\d+\s*[\-\–]\s+[a-zA-Zāīū]', l_str) or re.match(r'^\(\s*[\-\–]\s+[a-zA-Zāīū].*?\d+\)', l_str):
                anomalies['unrepaired_bidi'].append((idx, vol, line_num, l_str))

            # 3c. Shelfmark keywords in transliteration
            if any(kw in l_str for kw in ['خط:', 'کاغذ:', 'شماره نسخه:', 'کا:', 'تا:']):
                anomalies['shelfmark_leak'].append((idx, vol, line_num, l_str))

            # 3d. Transliteration line with excessive Persian (e.g. description merged into translit)
            persian_words = [w for w in l_no_tag.split() if any(is_persian_char(c) for c in w)]
            latin_words = [w for w in l_no_tag.split() if any(c in LATIN_LETTERS for c in w)]
            if len(persian_words) > 6 and len(latin_words) < len(persian_words):
                anomalies['persian_in_translit'].append((idx, vol, line_num, l_str))

            # 3e. Too short
            cleaned_folio = re.sub(r'\b\d+\s*[abrvpqCc]\b', '', l_no_tag)
            latin_chars = sum(1 for c in cleaned_folio if c in LATIN_LETTERS)
            if latin_chars < 2:
                anomalies['too_short_translit'].append((idx, vol, line_num, l_str))

print('='*50)
print(f'AUDIT RESULTS ON {len(items)} ITEMS:')
for k, v in anomalies.items():
    print(f' - {k}: {len(v)} anomalies')
    if v and len(v) <= 5:
        for it in v:
            print(f'    * {it}')
    elif v:
        for it in v[:3]:
            print(f'    * {it}')
        print(f'    ... and {len(v)-3} more')
