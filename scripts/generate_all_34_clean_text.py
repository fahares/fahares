#!/usr/bin/env python3
"""
Convert 68 OCR JSON tree files (sources/json/1.json..68.json) into 34 clean, Volume-sorted text files
using strict Volume Start Markers defined in sources/volume_starts.txt:
- sources/text/fahares_vol_01.txt .. sources/text/fahares_vol_34.txt
- sources/text/fankha-full.txt
"""

import sys
import os
import json
import re

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from scripts.apply_ocr_corrections import repair_text

PERSIAN_TO_ENGLISH_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
FOREIGN_SHELF_BRANDS = r'(?:Add|Or|Suppl|MS|Cod|Rieu|Blochet|Ethé|Lat|Ar|Pers|BOD|Cambridge|Paris|London|Berlin|Vatican)'

def load_volume_starts(config_path="sources/volume_starts.txt"):
    starts = {}
    if not os.path.exists(config_path):
        return starts
        
    with open(config_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'): continue
            
            line_clean = line.translate(PERSIAN_TO_ENGLISH_DIGITS)
            m = re.search(r'(?:vol(?:ume)?\s*[_:]?\s*|جلد\s*)?(\d+)\s*[:=]\s*(\d+\.json)\s*[/,:]?\s*(?:sheet|page|برگه|صفحه)?\s*[_:]?\s*(\d+)', line_clean, flags=re.IGNORECASE)
            if m:
                vol_num = int(m.group(1))
                json_file = m.group(2)
                sheet_idx = int(m.group(3))
                starts[(json_file, sheet_idx)] = vol_num
                
    return starts

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
    t = re.sub(r'<br\s*/?>\s*(?=(?:اهداء|وابسته|ترجمه)\s+به:)', '\n', t, flags=re.IGNORECASE)
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

def process_page_node(page):
    children = page.get('children', [])
    if not children: return "", ""
        
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
    return page_num_tag, page_text

def main():
    vol_starts = load_volume_starts("sources/volume_starts.txt")
    print(f"📖 Loaded {len(vol_starts)} explicit volume start markers from sources/volume_starts.txt")

    vol_pages_contents = {v: [] for v in range(1, 35)}
    curr_vol = 1
    
    for i in range(1, 69):
        json_filename = f"{i}.json"
        fpath = f"sources/json/{json_filename}"
        if not os.path.exists(fpath): continue
            
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            data = json.load(f)
            
        pages_nodes = []
        def collect_pages(node):
            if isinstance(node, dict):
                bbox = node.get('bbox', [])
                children = node.get('children', [])
                if bbox and len(bbox) == 4 and bbox[1] <= 50 and bbox[3] >= 2000:
                    pages_nodes.append(node)
                else:
                    for c in children:
                        collect_pages(c)
        collect_pages(data)
        
        for sheet_idx, p in enumerate(pages_nodes, 1):
            if (json_filename, sheet_idx) in vol_starts:
                curr_vol = vol_starts[(json_filename, sheet_idx)]
                print(f"📌 Volume boundary triggered -> Volume {curr_vol:02d} starting at {json_filename} / sheet {sheet_idx}")
            else:
                p_vol = None
                for c in p.get('children', []):
                    html = c.get('html', '')
                    c_bbox = c.get('bbox', [0,0,0,0])
                    if c_bbox[1] < 250 and ('فهرستگان' in html or 'جلد' in html):
                        m_vol = re.search(r'جلد\s*([۰-۹\d]+)', html)
                        if m_vol:
                            val = int(m_vol.group(1).translate(PERSIAN_TO_ENGLISH_DIGITS))
                            if 1 <= val <= 34:
                                p_vol = val
                                break
                if p_vol and not vol_starts:
                    curr_vol = p_vol
                    
            tag, txt = process_page_node(p)
            if txt:
                vol_pages_contents[curr_vol].append({'tag': tag, 'txt': txt})

    full_text_list = []
    
    for vol in range(1, 35):
        items = vol_pages_contents[vol]
        if not items: continue
            
        final_text = ""
        for item in items:
            tag = item['tag']
            txt = item['txt']
            if not final_text:
                final_text = f"{tag}\n{txt}" if tag else txt
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
                    
        vol_path = f"sources/text/fahares_vol_{vol:02d}.txt"
        with open(vol_path, 'w', encoding='utf-8') as f:
            f.write(final_text)
            
        full_text_list.append(final_text)
        print(f"✅ Created Volume file: {vol_path} ({len(items)} pages)")

    full_path = "sources/text/fankha-full.txt"
    with open(full_path, 'w', encoding='utf-8') as f:
        f.write("\n\n".join(full_text_list))
    print(f"\n🎉 Successfully updated all 34 volume files and combined {full_path}!")

if __name__ == "__main__":
    main()

