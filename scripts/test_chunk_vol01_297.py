#!/usr/bin/env python3
"""
Test Snippet Script with Title Transliteration Boundary Merger Logic & OCR Repair
Outputs clean text snippet to scratch/test_chunk_vol01_297.md for user inspection.
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
    
    t = html.replace('&lt;', '←')
    t = re.sub(r'<\s+(?![a-zA-Z/])', '← ', t)
    
    t = re.sub(r'<br\s*/?>\s*(?=[→–➤])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=[\d۰-۹]+\.\s+[آ-ی])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:\(-|\()?\s*[a-zA-Z\s\-\'\’āīūḥṣḍṭẓ‘\(\)\d]+)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>\s*(?=(?:آغاز|انجام|خط)\s*:)', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<(?:p|div|h[1-6])[^>]*>', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'</(?:p|div|h[1-6])>', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<br\s*/?>', ' ', t, flags=re.IGNORECASE)
    t = re.sub(r'<[^>]+>', ' ', t)
    
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
                    # Also split Latin transliteration ending into Persian author name
                    l2p_parts = re.split(r'(?<=[a-zA-Z\)\d\’\'])\s+(?=[\u0600-\u06FF]{2,})', tp)
                    for l2p in l2p_parts:
                        d_parts = re.split(r'(?<=\S)\s+(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', l2p)
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

def is_header_text(clean_text, y_min):
    if y_min >= 250: return False
    if re.search(r'(?:آغاز:|انجام:|آغاز و انجام:|خط:|شماره نسخه:|نسخه اصل:|چاپ:|●|وابسته به:|اهداء به:|اهدا به:)', clean_text):
        return False
    
    if 'فهرستگان' in clean_text or 'فنخا' in clean_text: return True
    if re.match(r'^\d+$', clean_text.strip()): return True
    if re.search(r'[آ-ی]\s*-\s*[آ-ی]', clean_text): return True
    if re.search(r'[\u0600-\u06FF\s\(\)]+\s+[\d۰-۹]+$', clean_text.strip()): return True
    if re.search(r'^[\d۰-۹]+\s+[\u0600-\u06FF\s\(\)]+', clean_text.strip()): return True
    return False

def process_page_node(page, last_page_num):
    children = page.get('children', [])
    if not children: return f"<!-- page: {last_page_num + 1 if last_page_num else ''} -->", "", last_page_num + 1 if last_page_num else None
        
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
        
        if is_header_text(clean_text, y_min):
            headers.append((y_min, clean_text))
        else:
            if x_center > page_mid_x:
                right_col.append((y_min, clean_text))
            else:
                left_col.append((y_min, clean_text))
                
    headers.sort(key=lambda x: x[0])
    right_col.sort(key=lambda x: x[0])
    left_col.sort(key=lambda x: x[0])
    
    detected_num = None
    for _, h_text in headers:
        m_num = re.search(r'\d+', h_text)
        if m_num:
            detected_num = int(m_num.group(0).translate(PERSIAN_TO_ENGLISH_DIGITS))
            break
            
    if detected_num is not None:
        curr_num = detected_num
    elif last_page_num is not None:
        curr_num = last_page_num + 1
    else:
        curr_num = None

    page_num_tag = f"<!-- page: {curr_num} -->" if curr_num is not None else ""
            
    col_blocks = [text for _, text in right_col] + [text for _, text in left_col]
    
    merged_blocks = []
    in_bullet_title_mode = False

    for blk in col_blocks:
        first_line = blk.split('\n')[0].strip()
        
        # Check if block starts with bullet title entry ●
        if first_line.startswith('●'):
            merged_blocks.append(blk)
            in_bullet_title_mode = True
            continue

        if in_bullet_title_mode:
            is_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b)[a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ]', first_line))
            is_entry_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or bool(re.match(r'^\d+\.\s+[آ-ی]', first_line)) or first_line.startswith('●'))

            if is_translit or is_entry_field:
                in_bullet_title_mode = False
                merged_blocks.append(blk)
            else:
                # Merge title line continuations inline onto previous title block!
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
            continue

        # Strict Latin Transliteration merger: ONLY merge onto previous block if BOTH previous block AND current block are Latin Transliterations!
        prev_is_translit = (merged_blocks and bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b)[a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ]', merged_blocks[-1].split('\n')[0].strip())))
        curr_is_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b)[a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ḍ\ṭ\ẓ]', first_line))

        if prev_is_translit and curr_is_translit:
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
    return page_num_tag, page_text, curr_num

def main():
    fpath = "sources/json/1.json"
    with open(fpath, 'r', encoding='utf-8') as f:
        data = json.load(f)
        
    pages_nodes = []
    def collect_pages(node):
        if isinstance(node, dict):
            bbox = node.get('bbox', [])
            if bbox and len(bbox) == 4 and bbox[1] <= 50 and bbox[3] >= 2000:
                pages_nodes.append(node)
            else:
                for c in node.get('children', []):
                    collect_pages(c)
    collect_pages(data)

    os.makedirs("scratch", exist_ok=True)
    out_file = "scratch/test_chunk_vol01_297.md"
    
    last_num = 290 # Start tracking around page 290
    final_text = ""
    
    # Test sheets 290 to 305 (covers Sheet 291..305 -> Pages 293..307)
    for sheet_idx in range(290, 305):
        p = pages_nodes[sheet_idx]
        tag, txt, last_num = process_page_node(p, last_num)
        if not txt: continue
        
        if not final_text:
            final_text = f"{tag}\n{txt}" if tag else txt
        else:
            if tag:
                last_line = final_text.strip().split('\n')[-1].strip()
                first_line_next = txt.strip().split('\n')[0].strip()
                is_last_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b)[a-zA-Z\š\ā\ī\ū\ḥ\ṣ\ḍ\ṭ\ẓ]', last_line))
                if is_last_translit or last_line.startswith('●') or re.search(r'/\s*(?:فارسی|عربی|ترکی)', last_line) or last_line[-1] in ['.', ':', ']', '»', '؛'] or first_line_next.startswith('●') or first_line_next.startswith('–') or first_line_next.startswith('→'):
                    final_text = final_text.rstrip() + f"\n\n{tag}\n" + txt.lstrip()
                else:
                    final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
            else:
                final_text += f"\n\n{txt}"

    with open(out_file, 'w', encoding='utf-8') as f:
        f.write(final_text)
        
    print(f"🎉 Regenerated test snippet with Latin-Persian split fix: {out_file}")

if __name__ == "__main__":
    main()

