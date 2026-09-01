import re
import os

with open('reports/candidate_broken_oftadegi_lines.md', 'r', encoding='utf-8') as f:
    content = f.read()

item_pattern = re.compile(
    r'### شماره (\d+) \(جلد (\d+) - سطر (\d+) تا (\d+) \| دسته‌بندی: (.*?)\)\n\n'
    r'🔴 \*\*وضعیت فعلی \(شکسته در دو سطر\):\*\*\n```text\n(.*?)\n```\n\n'
    r'🟢 \*\*اصلاح پیشنهادی \(پیوستگی در یک سطر\):\*\*\n```text\n(.*?)\n```',
    re.DOTALL
)

items = list(item_pattern.finditer(content))
print(f'Total parsed candidate oftadegi items: {len(items)}')

anomalies = {
    'content_loss': [],
    'page_tag_mismatch': [],
    'unjoined_lines': []
}

for m in items:
    idx = int(m.group(1))
    vol = int(m.group(2))
    l1 = int(m.group(3))
    l2 = int(m.group(4))
    cat = m.group(5)
    current_text = m.group(6).strip()
    proposed_text = m.group(7).strip()

    # 1. Page tag conservation
    current_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', current_text)
    proposed_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', proposed_text)
    if current_pages != proposed_pages:
        anomalies['page_tag_mismatch'].append((idx, vol, l1, current_pages, proposed_pages))

    # 2. Content conservation (ignoring whitespace and colon differences in oftadegi)
    c_clean = re.sub(r'[\s:]', '', current_text)
    p_clean = re.sub(r'[\s:]', '', proposed_text)
    if c_clean != p_clean:
        anomalies['content_loss'].append((idx, vol, l1, current_text, proposed_text))

    # 3. Must be single line (no newlines in proposed)
    if '\n' in proposed_text:
        anomalies['unjoined_lines'].append((idx, vol, l1, proposed_text))

print('='*50)
print(f'AUDIT RESULTS ON {len(items)} BROKEN OFTADEGI ITEMS:')
for k, v in anomalies.items():
    print(f' - {k}: {len(v)} anomalies')
