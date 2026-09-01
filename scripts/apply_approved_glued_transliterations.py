#!/usr/bin/env python3
"""
Applies user-approved glued transliteration split fixes (Batch 669) using Exact Content Matching.
Preserves all user manual edits.
Excludes the 16 items specified by the user.
Generates full audit log at reports/applied_glued_transliterations_audit_log.md.
"""

import os
import re

excluded_items = {4, 29, 41, 44, 84, 120, 143, 270, 271, 276, 342, 460, 513, 525, 536, 669}

# Parse candidate report
with open('reports/candidate_glued_transliterations.md', 'r', encoding='utf-8') as f:
    report_text = f.read()

items_raw = report_text.split('### شماره ')
report_items = {}

for it in items_raw[1:]:
    lines = it.strip().split('\n')
    header = lines[0]
    match = re.match(r'(\d+)\s*\(جلد\s*(\d+)\s*-\s*سطر\s*(\d+)', header)
    if not match: continue
    it_num = int(match.group(1))
    vol_num = int(match.group(2))
    line_num = int(match.group(3))
    
    it_text = '\n'.join(lines)
    
    curr_match = re.search(r'🔴 \*\*وضعیت فعلی:\*\*\s*```text\s*(.*?)\s*```', it_text, re.DOTALL)
    prop_match = re.search(r'🟢 \*\*اصلاح پیشنهادی:\*\*\s*```text\s*(.*?)\s*```', it_text, re.DOTALL)
    
    if curr_match and prop_match:
        report_items[it_num] = {
            'vol': vol_num,
            'line_num': line_num,
            'current': curr_match.group(1).strip(),
            'proposed': prop_match.group(1).strip()
        }

print(f"Parsed {len(report_items)} report items.")

# Load all volumes
vol_contents = {}
for v in range(1, 35):
    fpath = f'sources/text/fahares_vol_{v:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        vol_contents[v] = f.read()

audit_logs = []
applied_count = 0
skipped_manual_count = 0
excluded_count = 0

for num in range(1, len(report_items) + 1):
    rep = report_items.get(num)
    if not rep: continue
    
    v = rep['vol']
    content = vol_contents[v]
    curr_text = rep['current']
    prop_text = rep['proposed']
    
    if num in excluded_items:
        excluded_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': '🔘 توسط کاربر به عنوان مورد اصیل/صحیح حفظ شد (بدون تغییر)',
            'current': curr_text
        })
        continue
        
    # Exact content matching
    if curr_text in content:
        vol_contents[v] = content.replace(curr_text, prop_text, 1)
        applied_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': '✅ تفکیک و اصلاح با موفقیت اعمال شد',
            'before': curr_text,
            'after': prop_text
        })
    else:
        skipped_manual_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': 'ℹ️ قبلاً توسط کاربر در فایل ویرایش شده بود (تغییری داده نشد)',
            'current': curr_text
        })

# Save updated volume files
full_texts = []
for v in range(1, 35):
    fpath = f'sources/text/fahares_vol_{v:02d}.txt'
    with open(fpath, 'w', encoding='utf-8') as f:
        f.write(vol_contents[v])
    full_texts.append(vol_contents[v])

with open('sources/text/fankha-full.txt', 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(full_texts))

# Generate full Audit Log Markdown
log_path = 'reports/applied_glued_transliterations_audit_log.md'
with open(log_path, 'w', encoding='utf-8') as f:
    f.write('# گزارش جامع لاگ تفکیک آوانگاری‌های چسبیده (دسته ۶۶۹ موردی)\n\n')
    f.write(f'- تعداد کل موارد بررسی‌شده: **{len(report_items)}**\n')
    f.write(f'- تعداد موارد تفکیک و اعمال‌شده توسط سیستم: **{applied_count}**\n')
    f.write(f'- تعداد مواردی که کاربر قبلاً دستی ویرایش فرموده بود: **{skipped_manual_count}**\n')
    f.write(f'- تعداد موارد استثنای مشخص‌شده توسط کاربر (بدون تغییر): **{excluded_count}**\n\n')
    f.write('='*60 + '\n\n')
    
    for a in audit_logs:
        f.write(f"### شماره {a['num']} | وضعیت: {a['status']}\n\n")
        if 'vol' in a: f.write(f"- **جلد**: {a['vol']:02d}\n")
        
        if 'before' in a and 'after' in a:
            f.write(f"\n🔴 **متن قبلی:**\n```text\n{a['before']}\n```\n")
            f.write(f"\n🟢 **متن تفکیک‌شده جدید:**\n```text\n{a['after']}\n```\n")
        elif 'current' in a:
            f.write(f"\n📋 **متن اصلی مورد در گزارش:**\n```text\n{a['current']}\n```\n")
            
        f.write('\n---\n\n')

print(f"DONE: Applied {applied_count} fixes, preserved {skipped_manual_count} manual edits, excluded {excluded_count} items -> {log_path}")
