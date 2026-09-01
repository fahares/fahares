#!/usr/bin/env python3
import os, re, sys

LATIN_CHARS = set('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZāīūšṣżṭẓčžēō')

def is_translit_token(w):
    clean_w = w.strip('.,;:\'\"()[]«»-')
    return bool(clean_w and all(c in LATIN_CHARS or c in '-=\'ʻ‘`’' for c in clean_w))

def contains_persian(s):
    return any('\u0600' <= c <= '\u06FF' for c in s)

candidates = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = [line.rstrip('\r\n') for line in f]
        
    num_lines = len(lines)
    for l_idx, clean in enumerate(lines, 1):
        if not clean: continue
        
        # Skip pure comments / standalone page tags
        if clean.startswith('<!--') and clean.endswith('-->'):
            continue
            
        # Foreign shelfmark lines like [MS 123]
        if clean.startswith('[') and clean.endswith(']') and not clean.startswith('●'):
            if any(clean.startswith(f'[{prefix}') for prefix in ['ف:', 'نشریه:', 'MS', 'Cod', 'Add', 'Or', 'Suppl', 'Lat', 'BOD', 'Rieu', 'Blochet', 'Ethé']):
                continue

        # 1. Page tag glued to transliteration (either <!-- page: X --> latin or latin <!-- page: X -->)
        if clean.startswith('<!-- page:') and '-->' in clean:
            after = clean.split('-->', 1)[1].strip()
            if after and not contains_persian(after) and any(c in LATIN_CHARS for c in after):
                candidates.append({
                    'vol': vol,
                    'line_num': l_idx,
                    'type': 'برچسب صفحه + آوانگاری در یک خط',
                    'current': clean,
                    'proposed': f"{clean.split('-->', 1)[0]}-->\n\n{after}",
                    'prev_line': lines[l_idx - 2] if l_idx >= 2 else '',
                    'next_line': lines[l_idx] if l_idx < num_lines else ''
                })
                continue
                
        if clean.endswith('-->') and '<!-- page:' in clean:
            before = clean.split('<!-- page:', 1)[0].strip()
            if before and not contains_persian(before) and any(c in LATIN_CHARS for c in before):
                candidates.append({
                    'vol': vol,
                    'line_num': l_idx,
                    'type': 'آوانگاری + برچسب صفحه در یک خط',
                    'current': clean,
                    'proposed': f"{before}\n\n<!-- page:{clean.split('<!-- page:', 1)[1]}",
                    'prev_line': lines[l_idx - 2] if l_idx >= 2 else '',
                    'next_line': lines[l_idx] if l_idx < num_lines else ''
                })
                continue

        # For remaining checks, must contain both Persian and Latin characters
        if not (contains_persian(clean) and any(c in LATIN_CHARS for c in clean)):
            continue
            
        words = clean.split()
        if len(words) < 2: continue
        
        # 2. Leading Transliteration followed by Persian text
        lead_words = []
        for w in words:
            if is_translit_token(w):
                lead_words.append(w)
            else:
                break
                
        if lead_words and len(lead_words) < len(words):
            lead_str = ' '.join(lead_words)
            persian_suffix = clean[len(lead_str):].strip()
            
            # Check transliteration characteristics (at least 3 chars)
            if any(len(w.strip('.,;:\'\"()[]«»-')) >= 3 for w in lead_words):
                if not (lead_str.startswith(('Karl', 'Johan', 'Dr.', 'Prof.')) and 'و' in words[:4]):
                    if not (lead_str.startswith('(') and lead_str.endswith(')')):
                        candidates.append({
                            'vol': vol,
                            'line_num': l_idx,
                            'type': 'آوانگاری در ابتدا + متن فارسی در ادامه',
                            'current': clean,
                            'proposed': f"{lead_str}\n\n{persian_suffix}",
                            'prev_line': lines[l_idx - 2] if l_idx >= 2 else '',
                            'next_line': lines[l_idx] if l_idx < num_lines else ''
                        })
                        continue

        # 3. Trailing Transliteration preceded by Persian text
        trail_words = []
        for w in reversed(words):
            if is_translit_token(w):
                trail_words.append(w)
            else:
                break
                
        if trail_words and len(trail_words) < len(words):
            trail_words.reverse()
            trail_str = ' '.join(trail_words)
            persian_prefix = clean[:-len(trail_str)].strip()
            
            # Shelfmark filter
            if any(persian_prefix.endswith(s) for s in ['[', '[ف:', '[نشریه:']) or any(s in persian_prefix[-15:] for s in ['[MS', '[Cod', '[Add', '[Or', '[Suppl', '[Lat', '[BOD', '[Rieu', '[Blochet', '[Ethé']):
                continue
            if re.match(r'^\(?\s*-?\s*\d{3,4}\s*(?:شمسی|قمری|میلادی)?\s*\)?$', trail_str):
                continue
            if trail_str in ['(Rich)', '(Loth)', '(Rich).', '(Loth).']:
                continue
            if any(len(w.strip('.,;:\'\"()[]«»-')) >= 3 for w in trail_words):
                line_type = 'عنوان اصلی + آوانگاری در انتها' if clean.startswith('●') else ('سطر مؤلف/متن + آوانگاری در انتها' if not '←' in clean else 'سطر ارجاع + آوانگاری در انتها')
                candidates.append({
                    'vol': vol,
                    'line_num': l_idx,
                    'type': line_type,
                    'current': clean,
                    'proposed': f"{persian_prefix}\n\n{trail_str}",
                    'prev_line': lines[l_idx - 2] if l_idx >= 2 else '',
                    'next_line': lines[l_idx] if l_idx < num_lines else ''
                })

report_path = 'reports/candidate_isolated_transliterations.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش جامع تفکیک و استقلال سطور آوانگاری (بررسی مجدد و دوطرفه)\n\n")
    f.write(f"تعداد کل موارد شناسایی شده: **{len(candidates)}** مورد\n\n")
    f.write("توضیح:\n")
    f.write("این گزارش شامل کلیه سطوری است که قاعده استقلال آوانگاری در آن‌ها نقض شده است:\n")
    f.write("۱. آوانگاری در ابتدای سطر با ادامه متن فارسی.\n")
    f.write("۲. متن فارسی در ابتدا با آوانگاری چسبیده در انتهای سطر.\n")
    f.write("۳. برچسب صفحه ادغام‌شده در خط آوانگاری.\n\n")
    f.write("="*60 + "\n\n")
    
    for idx, c in enumerate(candidates, 1):
        f.write(f"### شماره {idx} (جلد {c['vol']:02d} - سطر {c['line_num']} | نوع: {c['type']})\n\n")
        f.write(f"🔴 **وضعیت فعلی:**\n```text\n{c['current']}\n```\n\n")
        f.write(f"�� **اصلاح پیشنهادی:**\n```text\n{c['proposed']}\n```\n\n")
        if c['prev_line']:
            f.write(f"- سطر قبل: `{c['prev_line'][:100]}`\n")
        if c['next_line']:
            f.write(f"- سطر بعد: `{c['next_line'][:100]}`\n")
        f.write("\n---\n\n")

print(f"✅ Generated candidate isolated transliterations report -> {report_path} (Total items: {len(candidates)})")
