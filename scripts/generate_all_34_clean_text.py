#!/usr/bin/env python3
"""
Convert output OCR JSON tree files into 34 clean, beautifully formatted plain text files in sources/text/.
Applies:
- BBox 2-column RTL sorting (Right column first, then Left column)
- Semantic HTML tag extraction & paragraph control
- 100% Inline Page transitions without blank line gaps
- Persian-to-English digit conversion across all volumes
- Clean attachment of Latin transliterations to Persian titles/authors with single newline
- Foreign shelfmarks inline attachment (e.g., "ش Add 19619")
- Manuscript record splitting (1. , 2. , 3. ... onto dedicated lines)
- Spacing balance between manuscript components (single line within manuscript, double newline between manuscripts)
- Paired cross-reference entries (– [source] ← [target])
- General bracket balance fusion rule (fixing broken multiline [ف: 2-306] reference brackets)
- Context-aware ZWNJ standardization for بی‌کا, بی‌تا, بی‌جا, بی‌نام
- Bracket and parenthesis inner space cleanup
- Full OCR typo repair dictionary integration
"""

import sys
import os
import json
import re

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from scripts.apply_ocr_corrections import repair_text

PERSIAN_TO_ENGLISH_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
FOREIGN_SHELF_BRANDS = r'(?:Add|Or|Suppl|MS|Cod|Rieu|Blochet|Ethé|Lat|Ar|Pers|BOD|Cambridge|Paris|London|Berlin|Vatican)'

def standardize_bib_terms_safely(text):
    if not text: return ""
    lines = text.split('\n')
    out_lines = []
    
    for line in lines:
        if line.startswith('آغاز:') or line.startswith('انجام:') or line.startswith('آغاز و انجام:') or line.startswith('●'):
            out_lines.append(line)
            continue
            
        l = line
        
        if 'خط:' in l or 'تا:' in l:
            l = re.sub(r'\b(?:بی[\s\u200c]*ک[اءعأئ‌آ]*|بیک)\b', 'بی‌کا', l)
            l = re.sub(r'\b(?:بی[\s\u200c]*ت[اءعأئ‌آ]*|بیت)\b', 'بی‌تا', l)

        if 'چاپ:' in l:
            l = re.sub(r'\bبی[\s\u200c]*جاء?\b', 'بی‌جا', l)

        out_lines.append(l)
        
    return '\n'.join(out_lines)

def clean_html_semantically(html):
    if not html: return ""
    
    # 1. Unescape &lt; and replace with arrow ←
    t = html.replace('&lt;', '←')
    t = re.sub(r'<\s+(?![a-zA-Z/])', '← ', t)
    
    # 2. Convert <br/> before reference markers (→, –, ➤), manuscript numbers, or inline transliteration to newlines \n
    t = re.sub(r'<br\s*/?>\s*(?=[→–➤])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=[\d۰-۹]+\.\s+[آ-ی])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:\(-|\()?\s*[a-zA-Z\s\-\'\’āīūḥṣḍṭẓ‘\(\)\d]+)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:اهداء|وابسته|ترجمه)\s+به:)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:آغاز|انجام|خط)\s*:)', '\n', t, flags=re.IGNORECASE)
    
    # 3. Block-level tags to newlines
    t = re.sub(r'<(?:p|div|h[1-6])[^>]*>', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'</(?:p|div|h[1-6])>', '\n', t, flags=re.IGNORECASE)
    
    # 4. Remaining <br/> inside paragraphs to space
    t = re.sub(r'<br\s*/?>', ' ', t, flags=re.IGNORECASE)
    
    # 5. Strip remaining HTML tags
    t = re.sub(r'<[^>]+>', ' ', t)
    
    # 6. Clean up spaces while preserving intentional newlines
    lines = [re.sub(r'[ \t]+', ' ', l).strip() for l in t.split('\n')]
    lines = [l for l in lines if l]
    
    final_lines = []
    for line in lines:
        tokens = re.split(r'\s+([→–➤])\s+', line)
        if len(tokens) > 1:
            rebuilt_entries = []
            curr_entry = tokens[0]
            for i in range(1, len(tokens), 2):
                marker = tokens[i]
                next_part = tokens[i+1]
                if '←' not in curr_entry:
                    curr_entry += f" ← {next_part}"
                else:
                    rebuilt_entries.append(curr_entry.strip())
                    curr_entry = f"– {next_part}"
            if curr_entry:
                rebuilt_entries.append(curr_entry.strip())
            sub_parts = rebuilt_entries
        else:
            sub_parts = [line]

        for sp in sub_parts:
            m_parts = re.split(r'(?<=\S)\s+(?=[\d۰-۹]+\.\s+[آ-ی])', sp)
            for mp in m_parts:
                t_parts = re.split(rf'(?<=[آ-ی])(?<!ش)(<!شماره)\s+(?=(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b)[a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ][a-zA-Z\s\-\'\’āīūḥṣḍṭẓ‘\(\)\d]{{3,}})', mp)
                for tp in t_parts:
                    d_parts = re.split(r'(?<=\S)\s+(?=(?:اهداء|وابسته|ترجمه)\s+به:)', tp)
                    for dp in d_parts:
                        sp_clean = repair_text(dp.strip())
                        if sp_clean:
                            sp_clean = re.sub(r'\[ف\]\s*:\s*', r'[ف: ', sp_clean)
                            sp_clean = re.sub(r'\[ف\]\s*(?=[\d۰-۹])', r'[ف: ', sp_clean)
                            sp_clean = re.sub(r'\[ف:\s*:\s*', r'[ف: ', sp_clean)
                            
                            sp_clean = re.sub(r'^\(-\s+([a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ].*?)\s+(\d+\?\))', r'\1 (-\2', sp_clean)
                            sp_clean = re.sub(r'^\(-\s+([a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ].*?)\s+\(-\s*(\d+)', r'\1 (-\2', sp_clean)
                            
                            sp_clean = standardize_bib_terms_safely(sp_clean)
                            
                            final_lines.append(sp_clean)
                
    text = "\n".join(final_lines)
    text = text.translate(PERSIAN_TO_ENGLISH_DIGITS)
    
    text = re.sub(r'\(\s+', '(', text)
    text = re.sub(r'\s+\)', ')', text)
    text = re.sub(r'\[\s+', '[', text)
    text = re.sub(r'\s+\]', ']', text)
    
    return text

def collect_pages(node, pages_nodes):
    if isinstance(node, dict):
        bbox = node.get('bbox', [])
        children = node.get('children', [])
        if bbox and len(bbox) == 4 and bbox[1] <= 50 and bbox[3] >= 2000:
            pages_nodes.append(node)
        else:
            for c in children:
                collect_pages(c, pages_nodes)

def process_volume(vol_num):
    json_path = f"sources/json/{vol_num}.json"
    txt_path = f"sources/text/{vol_num}.txt"
    
    if not os.path.exists(json_path):
        print(f"⚠️ File not found: {json_path}")
        return False
        
    print(f"⏳ Processing Volume {vol_num:02d} ({json_path}) ...")
    
    with open(json_path, 'r', encoding='utf-8', errors='ignore') as f:
        data = json.load(f)
        
    pages_nodes = []
    collect_pages(data, pages_nodes)
    
    page_contents = []
    
    for page_idx, page in enumerate(pages_nodes, 1):
        children = page.get('children', [])
        if not children: continue
            
        page_bbox = page.get('bbox', [0, 0, 1600, 2240])
        page_width = page_bbox[2] - page_bbox[0]
        page_mid_x = page_bbox[0] + (page_width / 2)
        
        headers = []
        right_col = []
        left_col = []
        
        for child in children:
            c_bbox = child.get('bbox', [0, 0, 0, 0])
            html = child.get('html', '')
            clean_text = clean_html_semantically(html)
            if not clean_text: continue
                
            y_min = c_bbox[1] if len(c_bbox) == 4 else 0
            x_min = c_bbox[0] if len(c_bbox) == 4 else 0
            x_max = c_bbox[2] if len(c_bbox) == 4 else 0
            x_center = (x_min + x_max) / 2
            
            if y_min < 250 and ('فهرستگان' in clean_text or re.search(r'[آ-ی]\s*-\s*[آ-ی]', clean_text) or re.search(r'^\d+\s*$', clean_text)):
                headers.append((y_min, clean_text))
            else:
                if x_center > page_mid_x:
                    right_col.append((y_min, clean_text))
                else:
                    left_col.append((y_min, clean_text))
                    
        headers.sort(key=lambda x: x[0])
        right_col.sort(key=lambda x: x[0])
        left_col.sort(key=lambda x: x[0])
        
        page_num_tag = ""
        for _, h_text in headers:
            m_num = re.search(r'\d+', h_text)
            if m_num:
                page_num_tag = f"<!-- page: {m_num.group(0).translate(PERSIAN_TO_ENGLISH_DIGITS)} -->"
                break
                
        col_blocks = [text for _, text in right_col] + [text for _, text in left_col]
        
        merged_blocks = []
        for blk in col_blocks:
            first_line = blk.split('\n')[0].strip()
            
            if merged_blocks and re.search(r'^(?:\(-|\()?\s*[a-zA-Z\s\-\'\’āīūḥṣḍṭẓ‘\(\)\d]', first_line) and not re.search(rf'^\s*{FOREIGN_SHELF_BRANDS}\b', first_line):
                merged_blocks[-1] += f"\n{blk}"
                continue

            if merged_blocks and re.search(r'(?:نسخه اصل:.*?ش|موزه.*?ش)\s*$', merged_blocks[-1].strip()) and re.search(rf'^\s*{FOREIGN_SHELF_BRANDS}\b', first_line):
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
                continue

            if merged_blocks and (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:')):
                merged_blocks[-1] += f"\n{blk}"
                continue

            prev_has_open = (merged_blocks and merged_blocks[-1].count('[') > merged_blocks[-1].count(']'))
            prev_has_broken_ref = (merged_blocks and bool(re.search(r'\[[آ-ی\s]*:\s*\d+[\-–/]\s*\]$', merged_blocks[-1].strip())))
            
            if prev_has_open or prev_has_broken_ref:
                clean_blk_str = re.sub(r'^\[?(\d+\]?)$', r'\1', blk.strip())
                if re.match(r'^\d+\]?$', clean_blk_str):
                    num = re.search(r'\d+', clean_blk_str).group(0)
                    if prev_has_broken_ref:
                        merged_blocks[-1] = re.sub(r'\]$', '', merged_blocks[-1].strip()) + f"{num}]"
                    else:
                        merged_blocks[-1] = merged_blocks[-1].strip() + f"{num}]"
                        
                    merged_blocks[-1] = re.sub(r'\[ف\]\s*:\s*', r'[ف: ', merged_blocks[-1])
                    merged_blocks[-1] = re.sub(r'\[ف\]\s*', r'[ف: ', merged_blocks[-1])
                    merged_blocks[-1] = re.sub(r'\[ف:\s*:\s*', r'[ف: ', merged_blocks[-1])
                    continue
                    
            merged_blocks.append(blk)
            
        page_text = "\n\n".join(merged_blocks)
        
        page_contents.append({
            'page_tag': page_num_tag,
            'text': page_text
        })

    final_text = ""
    for item in page_contents:
        tag = item['page_tag']
        txt = item['text']
        
        if not final_text:
            if tag:
                final_text = f"{tag}\n{txt}"
            else:
                final_text = txt
        else:
            if tag:
                last_line = final_text.strip().split('\n')[-1].strip()
                first_line_next = txt.strip().split('\n')[0].strip()
                
                if last_line.startswith('●') or re.search(r'/\s*(?:فارسی|عربی|ترکی)', last_line) or last_line[-1] in ['.', ':', ']', '»', '؛'] or first_line_next.startswith('●') or first_line_next.startswith('–') or first_line_next.startswith('→'):
                    final_text = final_text.rstrip() + f"\n{tag}\n" + txt.lstrip()
                else:
                    final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
            else:
                final_text += f"\n\n{txt}"
                
    os.makedirs(os.path.dirname(txt_path), exist_ok=True)
    with open(txt_path, 'w', encoding='utf-8') as f:
        f.write(final_text)
        
    print(f"✅ Generated {txt_path} ({len(pages_nodes)} pages processed)")
    return True

def main():
    print("🚀 Starting conversion for all 34 volume plain text files in sources/text/ ...")
    success_count = 0
    full_text_list = []
    
    for vol in range(1, 35):
        if process_volume(vol):
            success_count += 1
            txt_path = f"sources/text/{vol}.txt"
            with open(txt_path, 'r', encoding='utf-8') as f:
                full_text_list.append(f.read())
                
    print(f"\n🎉 Successfully processed {success_count}/34 volume text files in sources/text/!")
    
    # Generate sources/text/fankha-full.txt
    full_path = "sources/text/fankha-full.txt"
    with open(full_path, 'w', encoding='utf-8') as f:
        f.write("\n\n".join(full_text_list))
    print(f"✅ Generated combined {full_path}")

if __name__ == "__main__":
    main()

