import re
import os
from collections import Counter

with open('reports/candidate_stray_bullets.md', 'r', encoding='utf-8') as f:
    content = f.read()

item_pattern = re.compile(
    r'### شماره (\d+) \(جلد (\d+) - سطر (\d+) \| دسته‌بندی: (.*?)\)\n\n'
    r'🔴 \*\*وضعیت فعلی:\*\*\n```text\n(.*?)\n```\n\n'
    r'🟢 \*\*اصلاح پیشنهادی \(حذف بالت\):\*\*\n```text\n(.*?)\n```',
    re.DOTALL
)

items = list(item_pattern.finditer(content))
print(f'Total parsed candidate stray bullet items: {len(items)}')

anomalies = {
    'content_loss': [],
    'page_tag_mismatch': [],
    'unremoved_bullet': []
}

for m in items:
    idx = int(m.group(1))
    vol = int(m.group(2))
    line_num = int(m.group(3))
    cat = m.group(4)
    current_text = m.group(5).strip()
    proposed_text = m.group(6).strip()

    # 1. Page tag conservation
    current_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', current_text)
    proposed_pages = re.findall(r'<!--\s*page:\s*\d+\s*-->', proposed_text)
    if current_pages != proposed_pages:
        anomalies['page_tag_mismatch'].append((idx, vol, line_num, current_pages, proposed_pages))

    # 2. Content conservation (only '●' and space removed)
    c_clean = re.sub(r'[\s●]', '', current_text)
    p_clean = re.sub(r'[\s●]', '', proposed_text)
    if c_clean != p_clean:
        anomalies['content_loss'].append((idx, vol, line_num, current_text, proposed_text))

    # 3. Unremoved bullet
    if proposed_text.startswith('●'):
        anomalies['unremoved_bullet'].append((idx, vol, line_num, proposed_text))

print('='*50)
print(f'AUDIT RESULTS ON {len(items)} STRAY BULLET ITEMS:')
for k, v in anomalies.items():
    print(f' - {k}: {len(v)} anomalies')
