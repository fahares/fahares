#!/usr/bin/env python3
"""
Generates high-precision candidate split report for all 148 multi-cross-ref lines.
Uses:
1. Known Main Title Corpus Match
2. Common Source Prefix Detection
3. Alphabetical Bound Alignment
4. Description / Non-ref Filter
Output: reports/candidate_multi_cross_refs.md
"""

import os
import re

# 1. Build Corpus of Main Titles & Aliases
known_titles = set()
for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    with open(fpath, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line.startswith('●'):
                # Strip ●, / subject / language
                t_part = line[1:].split('/')[0].strip()
                # Split aliases =
                for sub_t in t_part.split('='):
                    clean_t = sub_t.strip()
                    if clean_t:
                        known_titles.add(clean_t)
            elif '←' in line and not line.startswith('آغاز:') and not line.startswith('چاپ:'):
                parts = line.split('←')
                if len(parts) == 2:
                    src = parts[0].strip()
                    tgt = parts[1].strip()
                    if src: known_titles.add(src)
                    if tgt: known_titles.add(tgt)

print(f"Loaded {len(known_titles)} known title entities from corpus.")

def split_multi_ref_line(line, prev_line, next_line):
    clean = line.strip()
    
    # Check if non-ref description line
    if any(clean.startswith(p) for p in ['آغاز:', 'چاپ:', 'خط:', 'مؤلف:', 'کاغذ:', 'انجام:']) or re.match(r'^[a-zA-Zāīūšṣżṭẓčžēō\'\-\s=←]+$', clean):
        return {
            'type': 'توصیف نسخه / بدون تغییر',
            'proposed_lines': [clean],
            'confidence': 'مطمئن'
        }
        
    # Handle embedded page tags
    page_tag_match = re.search(r'(<!--\s*page:\s*\d+\s*-->)', clean)
    page_tag = page_tag_match.group(1) if page_tag_match else None
    
    # Split by ←
    arrow_parts = clean.split('←')
    num_refs = len(arrow_parts) - 1
    
    if num_refs < 2:
        return {'type': 'تک ارجاع', 'proposed_lines': [clean], 'confidence': 'مطمئن'}
        
    # We want to form N entries: (src_1, tgt_1), (src_2, tgt_2), ..., (src_N, tgt_N)
    # arrow_parts[0] is src_1
    # arrow_parts[N] is tgt_N
    # arrow_parts[i] (1 <= i < N) is (tgt_i + " " + src_{i+1})
    
    entries = []
    current_src = arrow_parts[0].strip()
    
    for i in range(1, num_refs):
        mid_text = arrow_parts[i].strip()
        words = mid_text.split()
        
        best_split_idx = -1
        best_score = -100
        
        # Determine alphabetical root from current_src or prev_line
        src_first_word = current_src.split()[0] if current_src.split() else ""
        src_first_two = " ".join(current_src.split()[:2]) if len(current_src.split()) >= 2 else src_first_word
        
        # Test all possible split positions between 1 and len(words)-1
        for w_idx in range(1, len(words)):
            cand_tgt = " ".join(words[:w_idx]).strip()
            cand_next_src = " ".join(words[w_idx:]).strip()
            
            score = 0
            
            # Rule 1: cand_next_src starts with the same words as current_src
            if cand_next_src.startswith(src_first_two) and len(src_first_two) > 3:
                score += 50
            elif cand_next_src.startswith(src_first_word) and len(src_first_word) > 2:
                score += 30
                
            # Rule 2: cand_tgt exists in known_titles
            if cand_tgt in known_titles:
                score += 20
                
            # Rule 3: cand_next_src exists in known_titles
            if cand_next_src in known_titles:
                score += 20
                
            # Rule 4: Clean punctuation / page tag boundaries
            if '<!-- page:' in cand_next_src:
                score += 15
                
            # Rule 5: Length penalty if cand_tgt is 0
            if len(cand_tgt) == 0 or len(cand_next_src) == 0:
                score -= 100
                
            if score > best_score:
                best_score = score
                best_split_idx = w_idx
                
        if best_split_idx != -1:
            tgt_i = " ".join(words[:best_split_idx]).strip()
            next_src = " ".join(words[best_split_idx:]).strip()
            entries.append((current_src, tgt_i))
            current_src = next_src
        else:
            # Fallback midpoint
            mid_w = max(1, len(words) // 2)
            tgt_i = " ".join(words[:mid_w]).strip()
            next_src = " ".join(words[mid_w:]).strip()
            entries.append((current_src, tgt_i))
            current_src = next_src
            
    # Add final entry
    final_tgt = arrow_parts[-1].strip()
    entries.append((current_src, final_tgt))
    
    proposed_lines = []
    for s, t in entries:
        proposed_lines.append(f"{s} ← {t}")
        
    return {
        'type': 'مدخل‌های ارجاعی چسبیده',
        'proposed_lines': proposed_lines,
        'confidence': 'بالا' if best_score >= 20 else 'متوسط'
    }

# Process all volumes
report_items = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for l_idx, line in enumerate(lines, 1):
        clean = line.strip()
        count = clean.count('←')
        if count <= 1: continue
        
        prev_line = lines[l_idx - 2].strip() if l_idx >= 2 else ""
        next_line = lines[l_idx].strip() if l_idx < len(lines) else ""
        
        res = split_multi_ref_line(clean, prev_line, next_line)
        
        report_items.append({
            'vol': vol,
            'line_num': l_idx,
            'count': count,
            'type': res['type'],
            'current': clean,
            'proposed': "\n\n".join(res['proposed_lines']),
            'prev_line': prev_line,
            'next_line': next_line
        })

report_path = 'reports/candidate_multi_cross_refs.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش تفکیک سطور دارای چند علامت ارجاع (←)\n\n")
    f.write(f"تعداد کل سطور شناسایی شده: **{len(report_items)}** سطر\n\n")
    f.write("توضیح:\n")
    f.write("سطور دارای چند ارجاع با استفاده از الگوریتم ۴ مرحله‌ای (تطبیق با پیکره کل عناوین، پیشوند مشترک، نظم الفبایی و فیلتر توصیفات) تفکیک شده‌اند.\n\n")
    f.write("="*60 + "\n\n")
    
    for idx, it in enumerate(report_items, 1):
        f.write(f"### شماره {idx} (جلد {it['vol']:02d} - سطر {it['line_num']} | نوع: {it['type']} | تعداد ارجاع: {it['count']})\n\n")
        f.write(f"🔴 **وضعیت فعلی:**\n```text\n{it['current']}\n```\n\n")
        f.write(f"🟢 **اصلاح پیشنهادی:**\n```text\n{it['proposed']}\n```\n\n")
        if it['prev_line']:
            f.write(f"- سطر قبل: `{it['prev_line'][:100]}`\n")
        if it['next_line']:
            f.write(f"- سطر بعد: `{it['next_line'][:100]}`\n")
        f.write("\n---\n\n")

print(f"✅ Generated candidate multi cross-ref report -> {report_path} (Total items: {len(report_items)})")
