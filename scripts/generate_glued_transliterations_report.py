#!/usr/bin/env python3
"""
Scans all 34 volumes for glued transliterations (Latin transliterations attached to Persian text or page tags).
Generates detailed candidate report in reports/candidate_glued_transliterations.md.
"""

import os
import re

LATIN_CHARS = set('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZāīūšṣżṭẓčžēō')

candidates = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for l_idx, line in enumerate(lines, 1):
        clean = line.strip()
        if not clean: continue
        
        # Pattern A: Embedded page tag followed by Latin text
        if '<!-- page:' in clean and '-->' in clean:
            tag_match = re.search(r'(<!--\s*page:\s*\d+\s*-->)', clean)
            if tag_match:
                tag = tag_match.group(1)
                before_tag = clean[:tag_match.start()].strip()
                after_tag = clean[tag_match.end():].strip()
                
                # If after_tag is predominantly Latin transliteration
                words_after = after_tag.split()
                if words_after and any(c in LATIN_CHARS for c in words_after[0]):
                    # Check if before_tag exists
                    proposed_parts = []
                    if before_tag:
                        proposed_parts.append(before_tag)
                    proposed_parts.append(tag)
                    proposed_parts.append(after_tag)
                    
                    candidates.append({
                        'vol': vol,
                        'line_num': l_idx,
                        'type': 'برچسب صفحه + آوانگاری',
                        'current': clean,
                        'proposed': "\n\n".join(proposed_parts),
                        'prev_line': lines[l_idx - 2].strip() if l_idx >= 2 else "",
                        'next_line': lines[l_idx].strip() if l_idx < len(lines) else ""
                    })
                    continue
                    
        # Pattern B: Persian text ending with Latin transliteration
        words = clean.split()
        if len(words) >= 2:
            latin_trailing = []
            for w in reversed(words):
                clean_w = w.strip('.,;:\'\"()[]«»-')
                if clean_w and all(c in LATIN_CHARS or c in '-=\'ʻ‘`’' for c in clean_w):
                    latin_trailing.append(w)
                else:
                    break
                    
            if latin_trailing:
                latin_trailing.reverse()
                latin_str = " ".join(latin_trailing)
                persian_str = clean[:-len(latin_str)].strip()
                
                # Filters
                if not persian_str or not any('\u0600' <= c <= '\u06FF' for c in persian_str):
                    continue
                # Shelfmark filter
                if any(persian_str.endswith(s) for s in ['[', '[ف:', '[نشریه:']) or any(s in persian_str[-15:] for s in ['[MS', '[Cod', '[Add', '[Or', '[Suppl', '[Lat', '[BOD', '[Rieu', '[Blochet', '[Ethé']):
                    continue
                # Date filter like (1920) or (- 1850)
                if re.match(r'^\(?\s*-?\s*\d{3,4}\s*(?:شمسی|قمری|میلادی)?\s*\)?$', latin_str):
                    continue
                # Single short word filter unless starting with ‘
                if len(latin_trailing) == 1 and len(latin_str.strip('.,;:\'\"()[]«»-')) < 3 and not latin_str.startswith(('‘', 'ʻ', '\'')):
                    continue
                if latin_str in ['(Rich)', '(Loth)', '(Rich).', '(Loth).']:
                    continue
                    
                line_type = 'عنوان اصلی + آوانگاری' if clean.startswith('●') else ('سطر مؤلف/متن + آوانگاری' if not '←' in clean else 'سطر ارجاع + آوانگاری')
                
                # Build proposed split
                proposed_parts = [persian_str, latin_str]
                
                candidates.append({
                    'vol': vol,
                    'line_num': l_idx,
                    'type': line_type,
                    'current': clean,
                    'proposed': "\n\n".join(proposed_parts),
                    'prev_line': lines[l_idx - 2].strip() if l_idx >= 2 else "",
                    'next_line': lines[l_idx].strip() if l_idx < len(lines) else ""
                })

report_path = 'reports/candidate_glued_transliterations.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش تفکیک آوانگاری‌های چسبیده به سطر قبل\n\n")
    f.write(f"تعداد کل موارد شناسایی شده: **{len(candidates)}** مورد\n\n")
    f.write("توضیح:\n")
    f.write("این گزارش سطوری را شامل می‌شود که در آن‌ها متن آوانگاری لاتین به دلیل عدم شکست خط (Newline) به انتهای سطر فارسی، نام مؤلف، یا برچسب صفحه چسبیده است.\n\n")
    f.write("="*60 + "\n\n")
    
    for idx, c in enumerate(candidates, 1):
        f.write(f"### شماره {idx} (جلد {c['vol']:02d} - سطر {c['line_num']} | نوع: {c['type']})\n\n")
        f.write(f"🔴 **وضعیت فعلی:**\n```text\n{c['current']}\n```\n\n")
        f.write(f"🟢 **اصلاح پیشنهادی:**\n```text\n{c['proposed']}\n```\n\n")
        if c['prev_line']:
            f.write(f"- سطر قبل: `{c['prev_line'][:100]}`\n")
        if c['next_line']:
            f.write(f"- سطر بعد: `{c['next_line'][:100]}`\n")
        f.write("\n---\n\n")

print(f"✅ Generated candidate glued transliterations report -> {report_path} (Total items: {len(candidates)})")
