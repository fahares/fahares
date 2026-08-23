#!/usr/bin/env python3
"""
Post-processing Script for FanKha 34 Clean Text Files:
1. Normalizes Arabic digits (٠١٢٣٤٥٦٧٨٩) to standard digits (0-9), converting '٥' to '5'.
2. Standardizes Unicode triangle arrows (◀, ▶, ◄, ►, ▲, ▼) and other variants (➡, ➔, ➜, ➤, ➥, →) to standard '←'.
3. Automatically detects and cleanly splits glued Cross-Reference + Main Title entries into separate blocks.
4. Regenerates sources/text/fankha-full.txt.
"""

import os
import re
import glob

ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩'
ENGLISH_DIGITS = '0123456789'
TRANS_DIGITS = str.maketrans(ARABIC_DIGITS, ENGLISH_DIGITS)

ARROW_CHARS_REGEX = r'[←→➡➔➜➤➥◀▶◄►▲▼]'

def split_glued_cross_ref_and_title(line, next_line=""):
    m_cat = re.search(r'/\s*[\u0600-\u06FF\s]+\s*/\s*[\u0600-\u06FF]+$', line)
    if not m_cat:
        return [line]
        
    cat_str = m_cat.group(0)
    body = line[:m_cat.start()].strip()
    body = re.sub(r'^[●\s]+', '', body)
    
    has_arrow = bool(re.search(ARROW_CHARS_REGEX, body))
    if not has_arrow:
        return [line]
        
    body_std = re.sub(ARROW_CHARS_REGEX, '←', body)
    
    last_arrow_idx = body_std.rfind('←')
    if last_arrow_idx == -1:
        return [line]
        
    before_last_arrow = body_std[:last_arrow_idx].strip()
    after_last_arrow = body_std[last_arrow_idx+1:].strip()
    
    after_words = after_last_arrow.split()
    if len(after_words) <= 1:
        return [line]
        
    best_k = len(after_words) // 2
    if next_line:
        tr_words = [w.lower() for w in re.findall(r'[a-zA-Z\u0100-\u024F\u1E00-\u1EFF]+', next_line)]
        if tr_words:
            # Map of initial letters to phonetic transliteration
            char_map = {
                'م': 'm', 'ق': 'q', 'د': 'd', 'ر': 'r', 'ا': 'a', 'و': 'v', 'ز': 'z',
                'ن': 'n', 'ف': 'f', 'ی': 'y', 'ي': 'y', 'ع': "'", 'ل': 'l', 'ب': 'b',
                'ت': 't', 'ث': 's', 'ج': 'j', 'ح': 'h', 'خ': 'x', 'س': 's', 'ش': 's',
                'ص': 's', 'ض': 'z', 'ط': 't', 'ظ': 'z', 'غ': 'g', 'ک': 'k', 'ك': 'k',
                'گ': 'g', 'ه': 'h', 'ة': 't'
            }
            
            # Find the best split point k such that after_words[k:] matches tr_words
            best_score = -1
            for k in range(1, len(after_words)):
                cand_title = after_words[k:]
                score = 0
                for idx, w in enumerate(cand_title):
                    w_c = re.sub(r'[\u064B-\u065F\u0670]', '', w)
                    if not w_c: continue
                    init_char = w_c[0]
                    expected_sound = char_map.get(init_char, '')
                    
                    # Check if expected sound matches transliteration words around idx
                    for tr_idx in range(max(0, idx-1), min(len(tr_words), idx+3)):
                        if expected_sound and tr_words[tr_idx].startswith(expected_sound):
                            score += 2
                            break
                        elif w_c.lower() in tr_words[tr_idx] or tr_words[tr_idx] in w_c.lower():
                            score += 3
                            break
                if score > best_score:
                    best_score = score
                    best_k = k

    dest_words = after_words[:best_k]
    title_words = after_words[best_k:]
    
    dest_str = ' '.join(dest_words)
    title_str = ' '.join(title_words)
    
    cr_full = f"{before_last_arrow} ← {dest_str}"
    cr_parts = [p.strip() for p in cr_full.split('←') if p.strip()]
    if len(cr_parts) >= 2:
        clean_cr = f"{cr_parts[0]} ← {' '.join(cr_parts[1:])}"
    else:
        clean_cr = cr_full
        
    clean_title = f"● {title_str} {cat_str}"
    return [clean_cr, clean_title]

def process_file(fpath):
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    content = content.translate(TRANS_DIGITS)
    
    lines = content.split('\n')
    new_lines = []
    
    for i, line in enumerate(lines):
        next_l = lines[i+1].strip() if i+1 < len(lines) else ""
        
        if bool(re.search(ARROW_CHARS_REGEX, line)) and bool(re.search(r'/\s*[\u0600-\u06FF\s]+\s*/\s*[\u0600-\u06FF]+$', line)):
            if not re.search(r'\d+\s*[←→–—]\s*\d+', line):
                split_res = split_glued_cross_ref_and_title(line, next_l)
                if len(split_res) > 1:
                    new_lines.append(split_res[0])
                    new_lines.append("")
                    new_lines.append(split_res[1])
                    continue

        l_mod = line
        if any(c in l_mod for c in ['◀', '▶', '◄', '►', '▲', '▼', '➡', '➔', '➜', '➤', '➥']):
            if not l_mod.startswith('●') and '/' not in l_mod:
                l_mod = re.sub(r'[◀▶◄►▲▼➡➔➜➤➥→]', '←', l_mod)
                l_mod = re.sub(r'^[←\s]+', '', l_mod)
                parts = [p.strip() for p in l_mod.split('←') if p.strip()]
                if len(parts) >= 2:
                    l_mod = f"{parts[0]} ← {' '.join(parts[1:])}"
                    
        new_lines.append(l_mod)
        
    new_content = '\n'.join(new_lines)
    
    with open(fpath, 'w', encoding='utf-8') as f:
        f.write(new_content)
        
    return new_content

def main():
    full_text_list = []
    
    for vol in range(1, 35):
        fpath = f"sources/text/fahares_vol_{vol:02d}.txt"
        if not os.path.exists(fpath):
            continue
            
        updated_content = process_file(fpath)
        full_text_list.append(updated_content)
        print(f"✅ Processed and standardized Vol {vol:02d} -> {fpath}")
        
    full_path = "sources/text/fankha-full.txt"
    combined = "\n\n".join(full_text_list)
    with open(full_path, 'w', encoding='utf-8') as f:
        f.write(combined)
        
    print(f"\n🎉 Successfully updated all 34 volumes and regenerated {full_path} ({len(combined):,} chars)!")

if __name__ == "__main__":
    main()
