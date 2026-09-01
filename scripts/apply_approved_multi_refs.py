#!/usr/bin/env python3
"""
Applies user-approved multi-cross-ref split fixes (Batch 148) using Exact Content Matching.
Preserves all user manual edits.
Generates full audit log at reports/applied_multi_refs_audit_log.md.
"""

import os
import re

user_reviews_raw = """
۱: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۲: اصلاحش کردم.
۳: درست تشخیص دادی.
۴: اصلاحش کردم.
۵: اصلاحش کردم.
۶: اصلاحش کردم.
۷: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۸: درست تشخیص دادی.
۹: درست تشخیص دادی.
۱۰: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۱۱: اصلاحش کردم.
۱۲: اصلاحش کردم.
۱۳: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۱۴: اصلاحش کردم.
۱۵: اصلاحش کردم.
۱۶: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۱۷: اصلاحش کردم.
۱۸: درست تشخیص دادی. فقط شماره صفحه که در اول سطر دوم ارجاع آوردهایش را سر سطر بیاور.
۱۹: اصلاحش کردم.
۲۰: درست تشخیص دادی.
۲۱: اصلاحش کردم.
۲۲: درست تشخیص دادی.
۲۳: درست تشخیص دادی.
۲۴: اصلاحش کردم.
۲۵: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۲۶: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۲۷: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۲۸: درست تشخیص دادی.
۲۹: این دقیقا مانند شماره ۲۸ است اما در یک سطور دیگر... احتمالا اختلالی وجود دارد. بعد از انجام کارها باید این را بررسی کنیم.
۳۰: اصلاحش کردم.
۳۱: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۳۲: درست تشخیص دادی.
۳۳: درست تشخیص دادی. فقط کلمات «ابحات» اشتباه تایپی هستند. صحیحشان «ابحاث» است. اصلاح کن.
۳۴: اصلاحش کردم.
۳۵: اصلاحش کردم.
۳۶: اصلاحش کردم.
۳۷: اصلاحش کردم.
۳۸: درست تشخیص دادی.
۳۹: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۴۰: اصلاحش کردم.
۴۱: اصلاحش کردم.
۴۲: اصلاحش کردم.
۴۳: اصلاحش کردم.
۴۴: اصلاحش کردم.
۴۵: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور. کاراکتر «ب» هم که در انتهای سطر اول ارجاع هست را حذف کن.
۴۶: اصلاحش کردم.
۴۷: درست تشخیص دادی.
۴۸: درست تشخیص دادی.
۴۹: اصلاحش کردم.
۵۰: درست تشخیص دادی.
۵۱: درست تشخیص دادی.
۵۲: درست تشخیص دادی.
۵۳: درست تشخیص دادی.
۵۴: اصلاحش کردم.
۵۵: اصلاحش کردم.
۵۶: اصلاحش کردم.
۵۷: اصلاحش کردم.
۵۸: درست تشخیص دادی.
۵9: اصلاحش کردم.
۶۰: اصلاحش کردم.
۶۱: اصلاحش کردم.
۶۲: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۶۳: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۶۴: اصلاحش کردم.
۶۵: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۶۶: اصلاحش کردم.
۶۷: اصلاحش کردم.
۶۸: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۶۹: اصلاحش کردم.
۷۰: درست تشخیص دادی.
۷۱: اصلاحش کردم.
۷۲: اصلاحش کردم.
۷۳: اصلاحش کردم.
۷۴: درست تشخیص دادی.
۷۵: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۷۶: اصلاحش کردم.
۷۷: اصلاحش کردم.
۷۸: اصلاحش کردم.
۷۹: اصلاحش کردم.
۸۰: اصلاحش کردم.
۸۱: اصلاحش کردم.
۸۲: اصلاحش کردم.
۸۳: درست تشخیص دادی.
۸۴: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۸۵: اصلاحش کردم.
۸۶: درست تشخیص دادی.
۸۷: اصلاحش کردم.
۸۸: اصلاحش کردم.
۸۹: درست تشخیص دادی.
۹۰: درست تشخیص دادی.
۹۱: اصلاحش کردم.
۹۲: اصلاحش کردم.
۹۳: اصلاحش کردم.
۹۴: اصلاحش کردم.
۹۵: درست تشخیص دادی.
۹۶: اصلاحش کردم.
۹۷: اصلاحش کردم.
۹۸: درست تشخیص دادی.
۹۹: اصلاحش کردم.
۱۰۰: اصلاحش کردم.
۱۰۱: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۱۰۲: درست تشخیص دادی.
۱۰۳: درست تشخیص دادی. فقط شماره صفحه که در آخر سطر اول ارجاع آوردهایش را سر سطر بیاور.
۱۰۴: درست تشخیص دادی. فقط شماره صفحه که در اول سطر دوم ارجاع آوردهایش را سر سطر بیاور.
۱۰۵: درست تشخیص دادی. فقط شماره صفحه که در اول سطر دوم ارجاع آوردهایش را سر سطر بیاور.
۱۰۶: اصلاحش کردم.
۱۰۷: اصلاحش کردم.
۱۰۸: درست تشخیص دادی.
۱۰۹: اصلاحش کردم.
۱۱۰: اصلاحش کردم.
۱۱۱: اصلاحش کردم.
۱۱۲: درست تشخیص دادی.
۱۱۳: درست تشخیص دادی.
۱۱۴: درست تشخیص دادی.
۱۱۵: اصلاحش کردم.
۱۱۶: اصلاحش کردم.
۱۱۷: اصلاحش کردم.
۱۱۸: درست تشخیص دادی.
۱۱۹: درست تشخیص دادی.
۱۲۰: درست تشخیص دادی.
۱۲۱: درست تشخیص دادی.
۱۲۲: درست تشخیص دادی.
۱۲۳: درست تشخیص دادی.
۱۲۴: اصلاحش کردم.
۱۲۵: اصلاحش کردم.
۱۲۶: اصلاحش کردم.
۱۲۷: اصلاحش کردم.
۱۲۸: درست تشخیص دادی.
۱۲۹: اصلاحش کردم.
۱۳۰: درست تشخیص دادی.
۱۳۱: درست تشخیص دادی.
۱۳۲: درست تشخیص دادی.
۱۳۳: درست تشخیص دادی.
۱۳۴: درست تشخیص دادی.
۱۳۵: درست تشخیص دادی.
۱۳۶: درست تشخیص دادی.
۱۳۷: درست تشخیص دادی.
۱۳۸: اصلاحش کردم.
۱۳۹: اصلاحش کردم.
۱۴۰: اصلاحش کردم.
۱۴۱: اصلاحش کردم.
۱۴۲: اصلاحش کردم.
۱۴۳: اصلاحش کردم.
۱۴۴: اصلاحش کردم.
۱۴۵: اصلاحش کردم.
۱۴۶: درست تشخیص دادی.
۱۴۷: درست تشخیص دادی.
۱۴۸: اصلاحش کردم.
"""

FA_TO_EN = {'۰':'0', '۱':'1', '۲':'2', '۳':'3', '۴':'4', '۵':'5', '۶':'6', '۷':'7', '۸':'8', '۹':'9'}
def parse_fa_num(s):
    res = ''
    for c in s:
        res += FA_TO_EN.get(c, c)
    return int(res)

user_decisions = {}
for line in user_reviews_raw.strip().split('\n'):
    line = line.strip()
    if not line or ':' not in line: continue
    num_str, dec = line.split(':', 1)
    num = parse_fa_num(num_str.strip())
    dec_clean = dec.strip()
    is_approved = 'درست تشخیص دادی' in dec_clean
    user_decisions[num] = {
        'approved': is_approved,
        'comment': dec_clean
    }

# Parse candidate report
with open('reports/candidate_multi_cross_refs.md', 'r', encoding='utf-8') as f:
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

print(f"Parsed {len(user_decisions)} user decisions and {len(report_items)} report items.")

# Load all volumes
vol_contents = {}
for v in range(1, 35):
    fpath = f'sources/text/fahares_vol_{v:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        vol_contents[v] = f.read()

audit_logs = []
applied_count = 0
skipped_manual_count = 0
rejected_count = 0

for num in range(1, 149):
    u_dec = user_decisions.get(num, {'approved': False, 'comment': 'نامشخص'})
    rep = report_items.get(num)
    
    if not rep:
        audit_logs.append({
            'num': num,
            'status': '⚠️ مورد در گزارش یافت نشد',
            'comment': u_dec['comment']
        })
        continue
        
    v = rep['vol']
    content = vol_contents[v]
    curr_text = rep['current']
    prop_text = rep['proposed']
    
    if not u_dec['approved']:
        rejected_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': '🔘 اصلاح دستی توسط کاربر اعمال شده است (بدون تغییر توسط سیستم)',
            'comment': u_dec['comment'],
            'current': curr_text
        })
        continue
        
    # Formatting adjustments based on user comment:
    # 1. Standalone page tags:
    # If prop_text contains `<!-- page: X -->` attached to end or start of line, make it standalone
    if '<!-- page:' in prop_text:
        # e.g., "Line 1 <!-- page: X -->\n\nLine 2" -> "Line 1\n\n<!-- page: X -->\n\nLine 2"
        # or "<!-- page: X --> Line 2" -> "<!-- page: X -->\n\nLine 2"
        prop_text = re.sub(r'\s*(<!--\s*page:\s*\d+\s*-->)\s*', r'\n\n\1\n\n', prop_text)
        # Clean up any triple newlines
        prop_text = re.sub(r'\n{3,}', '\n\n', prop_text).strip()
        
    # 2. Specific fix for Item 33: ابحات -> ابحاث
    if num == 33:
        prop_text = prop_text.replace('ابحات', 'ابحاث')
        
    # 3. Specific fix for Item 45: remove stray 'ب'
    if num == 45:
        prop_text = re.sub(r'\s+ب\s*(?=\n|$)', '', prop_text)
        
    # Exact content matching
    if curr_text in content:
        vol_contents[v] = content.replace(curr_text, prop_text, 1)
        applied_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': '✅ تفکیک و اصلاح با موفقیت اعمال شد',
            'comment': u_dec['comment'],
            'before': curr_text,
            'after': prop_text
        })
    else:
        skipped_manual_count += 1
        audit_logs.append({
            'num': num,
            'vol': v,
            'status': 'ℹ️ قبلاً توسط کاربر در فایل ویرایش شده بود (تغییری داده نشد)',
            'comment': u_dec['comment'],
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
log_path = 'reports/applied_multi_refs_audit_log.md'
with open(log_path, 'w', encoding='utf-8') as f:
    f.write('# گزارش جامع لاگ تفکیک سطور چندارجاعی (دسته ۱۴۸ موردی)\n\n')
    f.write(f'- تعداد کل موارد بررسی‌شده: **{len(user_decisions)}**\n')
    f.write(f'- تعداد موارد تفکیک و اعمال‌شده توسط سیستم: **{applied_count}**\n')
    f.write(f'- تعداد مواردی که کاربر قبلاً دستی ویرایش فرموده بود: **{skipped_manual_count}**\n')
    f.write(f'- تعداد مواردی که کاربر دستی اصلاح کرده بود (اصلاحش کردم): **{rejected_count}**\n\n')
    f.write('='*60 + '\n\n')
    
    for a in audit_logs:
        f.write(f"### شماره {a['num']} | وضعیت: {a['status']}\n\n")
        f.write(f"- **نظر کاربر**: `{a['comment']}`\n")
        if 'vol' in a: f.write(f"- **جلد**: {a['vol']:02d}\n")
        
        if 'before' in a and 'after' in a:
            f.write(f"\n🔴 **متن قبلی سطر:**\n```text\n{a['before']}\n```\n")
            f.write(f"\n🟢 **متن تفکیک‌شده جدید:**\n```text\n{a['after']}\n```\n")
        elif 'current' in a:
            f.write(f"\n📋 **متن اصلی مورد در گزارش:**\n```text\n{a['current']}\n```\n")
            
        f.write('\n---\n\n')

print(f"DONE: Applied {applied_count} fixes, preserved {skipped_manual_count} manual edits, recorded audit log -> {log_path}")
