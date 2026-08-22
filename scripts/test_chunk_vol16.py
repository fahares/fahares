#!/usr/bin/env python3
"""
Test Snippet Script for Volume 16 with:
1. Volume number tracking in Reconstructed Titles Report.
2. Fixed page tag formatting (no extra newline before non-inline page tags).
3. Title reconstruction for dropped OCR titles with persistent reporting (reports/reconstructed_titles.md).
4. Sub-Block Multi-Column Splitting.
"""

import sys
import os
import json
import re
from statistics import median

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from scripts.apply_ocr_corrections import repair_text, repair_transliteration_with_fa_context

PERSIAN_TO_ENGLISH_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
FOREIGN_SHELF_BRANDS = r'(?:Add|Or|Suppl|MS|Cod|Rieu|Blochet|Ethé|Lat|Ar|Pers|BOD|Cambridge|Paris|London|Berlin|Vatican)'
TRANSLIT_CHAR_CLASS = r'[a-zA-Z\u0100-\u024F\u1E00-\u1EFF]'

EXPLICIT_RECONSTRUCTED_TITLES = {
    r"zaxā'?er-ol\s+asfār": "● ذخائر الاسفار",
    r"zaxā'?ir-ul\s+usūl": "● ذخائر الاصول",
    r"dabā'?ih-u\s+ahl-il\s+kitāb": "● ذبائح اهل الكتاب",
}

RECONSTRUCTED_LOG = []

def get_reconstructed_title(translit_str):
    if not translit_str: return None
    clean = translit_str.strip().split('\n')[0].strip()
    clean = re.sub(r'^[●\s]+', '', clean)
    clean = re.sub(r'\(.*?\)', '', clean).strip()
    
    for pat, title in EXPLICIT_RECONSTRUCTED_TITLES.items():
        if re.search(pat, clean, re.IGNORECASE):
            return title
    return None

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
    if not html: return "", False
    
    is_h2_tag = bool(re.search(r'<h2\b', html, re.IGNORECASE))
    
    t = html.replace('&lt;', '←')
    t = re.sub(r'<\s+(?![a-zA-Z/])', '← ', t)
    
    # All <br/> patterns must account for optional nested <span ...> tags
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=[→–➤])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=[\d۰-۹]+\.\s+[آ-ی])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(rf'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=(?:\(-|\()?\s*{TRANSLIT_CHAR_CLASS}+)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=(?:آغاز|انجام|خط)\s*:)', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<(?:p|div|h[1-6]|li)[^>]*>', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'</(?:p|div|h[1-6]|li)>', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<br\s*/?>', ' ', t, flags=re.IGNORECASE)
    t = re.sub(r'<[^>]+>', ' ', t)
    
    # Fix OCR split digits in manuscript numbering e.g. "۲ ۱. " -> "۲۱. "
    t = re.sub(r'([\d۰-۹]+)\s+([\d۰-۹]+\.\s+[آ-ی])', r'\1\2', t)
    
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
            # Fix split numbering inside line before splitting
            sp = re.sub(r'([\d۰-۹]+)\s+([\d۰-۹]+\.\s+[آ-ی])', r'\1\2', sp)
            m_parts = re.split(r'(?<=\S)\s+(?=[\d۰-۹]+\.\s+[آ-ی])', sp)
            for mp in m_parts:
                t_parts = re.split(rf'(?<=[آ-ی])(?<!ش)(<!شماره)\s+(?=(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}{{3,}})', mp)
                for tp in t_parts:
                    # Do NOT split on digits (\d) followed by Persian words like "833 قمری"!
                    l2p_parts = re.split(r'(?<=[a-zA-Z\u0100-\u024F\u1E00-\u1EFF\)\’\'])\s+(?=[\u0600-\u06FF]{2,})', tp)
                    for l2p in l2p_parts:
                        d_parts = re.split(r'(?<=\S)\s+(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', l2p)
                        for dp in d_parts:
                            sp_clean = repair_text(dp.strip())
                            if sp_clean:
                                sp_clean = re.sub(r'\[ف\]\s*:\s*', r'[ف: ', sp_clean)
                                sp_clean = re.sub(r'\[ف\]\s*(?=[\d۰-۹])', r'[ف: ', sp_clean)
                                sp_clean = re.sub(r'\[ف:\s*:\s*', r'[ف: ', sp_clean)
                                
                                sp_clean = re.sub(rf'^\(-\s+({TRANSLIT_CHAR_CLASS}.*?)\s+(\d+\?\))', r'\1 (-\2', sp_clean)
                                sp_clean = re.sub(rf'^\(-\s+({TRANSLIT_CHAR_CLASS}.*?)\s+\(-\s*(\d+)', r'\1 (-\2', sp_clean)
                                
                                sp_clean = standardize_bib_terms_safely(sp_clean)
                                
                                final_lines.append(sp_clean)
                
    text = "\n".join(final_lines)
    text = text.translate(PERSIAN_TO_ENGLISH_DIGITS)
    
    text = re.sub(r'\(\s+', '(', text)
    text = re.sub(r'\s+\)', ')', text)
    text = re.sub(r'\[\s+', '[', text)
    text = re.sub(r'\s+\]', ']', text)
    
    return text, is_h2_tag

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

def is_standalone_banner(child, page_mid_x):
    bbox = child.get('bbox', [])
    if not bbox or len(bbox) != 4: return False
    
    x_min, y_min, x_max, y_max = bbox
    height = y_max - y_min
    
    if y_min <= 250 or y_max >= 2000: return False
    
    if x_min < page_mid_x < x_max and height > 75:
        html = child.get('html', '')
        clean = re.sub(r'<[^>]+>', ' ', html).strip()
        if not clean.startswith('●') and '/' not in clean:
            return True
            
    return False

def get_item_column_and_y(html, default_bbox):
    spans = re.findall(r'data-bbox=\"([^\"]+)\"', html)
    if spans:
        x_centers = []
        y_mins = []
        for s in spans:
            nums = [float(v) for v in s.split()]
            if len(nums) == 4:
                x_centers.append((nums[0] + nums[2]) / 2)
                y_mins.append(nums[1])
        if x_centers:
            return median(x_centers), min(y_mins)
            
    if len(default_bbox) == 4:
        return (default_bbox[0] + default_bbox[2]) / 2, default_bbox[1]
    return 0, 0

def extract_sub_blocks_from_child(child):
    html = child.get('html', '')
    c_bbox = child.get('bbox', [0, 0, 0, 0])
    
    if '<li' in html:
        items = re.findall(r'(<li\b[^>]*>.*?</li>)', html, re.DOTALL)
        if not items:
            items = re.split(r'(?=<li\b)', html)
            items = [it for it in items if it.strip()]
            
        sub_blocks = []
        for it in items:
            x_c, y_m = get_item_column_and_y(it, c_bbox)
            sub_blocks.append((it, x_c, y_m))
        return sub_blocks
    else:
        x_c, y_m = get_item_column_and_y(html, c_bbox)
        return [(html, x_c, y_m)]

def process_page_node(page, last_page_num, last_title_fa="", title_incomplete=False, vol_num=16):
    children = page.get('children', [])
    if not children: return f"<!-- page: {last_page_num + 1 if last_page_num else ''} -->", "", last_page_num + 1 if last_page_num else None, last_title_fa, title_incomplete
        
    page_bbox = page.get('bbox', [0, 0, 1600, 2240])
    page_width = page_bbox[2] - page_bbox[0]
    page_mid_x = page_bbox[0] + (page_width / 2)
    
    headers = []
    right_col = []
    left_col = []
    
    for child in children:
        # Geometrically filter standalone banner headers (like الذات)
        if is_standalone_banner(child, page_mid_x):
            continue

        # Extract sub-blocks (handles multi-column <li> items seamlessly)
        sub_blocks = extract_sub_blocks_from_child(child)
        
        for html, x_center, y_min in sub_blocks:
            clean_text, is_h2 = clean_html_semantically(html)
            if not clean_text: continue
                
            if is_header_text(clean_text, y_min):
                headers.append((y_min, clean_text, is_h2))
            else:
                if x_center > page_mid_x:
                    right_col.append((y_min, clean_text, is_h2))
                else:
                    left_col.append((y_min, clean_text, is_h2))
                
    headers.sort(key=lambda x: x[0])
    right_col.sort(key=lambda x: x[0])
    left_col.sort(key=lambda x: x[0])
    
    detected_num = None
    for _, h_text, _ in headers:
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
            
    col_blocks = [(text, is_h2) for _, text, is_h2 in right_col] + [(text, is_h2) for _, text, is_h2 in left_col]
    
    merged_blocks = []
    in_bullet_title_mode = title_incomplete
    curr_title_fa = last_title_fa

    for blk_idx, (blk, is_h2) in enumerate(col_blocks):
        # Always attempt cross-validation repair on any block if it contains Persian context
        if 'ذ' in blk or 'احد' in blk:
            blk = repair_transliteration_with_fa_context(blk, blk)
        elif curr_title_fa and ('ذ' in curr_title_fa or 'احد' in curr_title_fa):
            blk = repair_transliteration_with_fa_context(curr_title_fa, blk)

        first_line = blk.split('\n')[0].strip()
        
        # If we are currently inside an incomplete title (missing '/'):
        if in_bullet_title_mode and merged_blocks:
            prev_title_has_slash = ('/' in merged_blocks[-1])
            is_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', first_line))
            is_entry_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or bool(re.match(r'^\d+\.\s+[آ-ی]', first_line)))

            if not prev_title_has_slash and not is_translit and not is_entry_field:
                clean_blk = re.sub(r'^●\s*', '', blk).strip()
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {clean_blk}"
                curr_title_fa = merged_blocks[-1]
                if '/' in blk:
                    in_bullet_title_mode = False
                continue

        # Authentic Title Entry Detection:
        has_category_slash = bool(re.search(r'/\s*[\u0600-\u06FF\s]+\s*/(?:\s*[\u0600-\u06FF]+)?$', first_line))
        
        # If title was incomplete from previous page and this first block is its continuation:
        if blk_idx == 0 and title_incomplete:
            clean_blk_no_bullet = re.sub(r'^●\s*', '', blk)
            words_prev = curr_title_fa.split()
            words_curr = clean_blk_no_bullet.split()
            
            prefix_len = 0
            for k in range(1, len(words_curr)+1):
                phrase = ' '.join(words_curr[:k])
                if phrase in curr_title_fa:
                    prefix_len = k
                else:
                    break
                    
            remaining_curr = ' '.join(words_curr[prefix_len:]) if prefix_len > 0 else clean_blk_no_bullet
            
            if merged_blocks:
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {remaining_curr.strip()}"
            else:
                merged_blocks.append(remaining_curr.strip())
                
            curr_title_fa = curr_title_fa.strip() + f" {remaining_curr.strip()}"
            in_bullet_title_mode = ('/' not in curr_title_fa)
            title_incomplete = False
            continue

        is_title_fa = (first_line.startswith('●') or is_h2 or has_category_slash)
        
        if is_title_fa:
            # Prepend bullet ● ONLY if it is an authentic title entry
            if not blk.startswith('●'):
                blk = f"● {blk}"
                first_line = blk.split('\n')[0].strip()

            curr_title_fa = first_line
            merged_blocks.append(blk)
            in_bullet_title_mode = ('/' not in first_line)
            continue

        if in_bullet_title_mode:
            is_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', first_line))
            is_entry_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or bool(re.match(r'^\d+\.\s+[آ-ی]', first_line)) or is_title_fa)

            if is_translit:
                in_bullet_title_mode = False
                repaired_translit = repair_transliteration_with_fa_context(curr_title_fa, blk)
                merged_blocks.append(repaired_translit)
                continue
            elif is_entry_field:
                in_bullet_title_mode = False
                merged_blocks.append(blk)
                continue
            else:
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
                curr_title_fa = merged_blocks[-1]
                if '/' in blk:
                    in_bullet_title_mode = False
                continue

        # Check for Standalone Transliteration whose Persian Title was dropped in OCR
        prev_is_persian_title = (merged_blocks and (merged_blocks[-1].startswith('●') or '/' in merged_blocks[-1].split('\n')[-1]) and not re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', merged_blocks[-1].split('\n')[-1].strip()))
        curr_is_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', first_line))

        if curr_is_translit and not prev_is_persian_title:
            reconstructed_title = get_reconstructed_title(first_line)
            if reconstructed_title:
                # Log reconstruction event with volume number!
                context_preview = col_blocks[blk_idx+1][0].split('\n')[0] if blk_idx+1 < len(col_blocks) else ""
                RECONSTRUCTED_LOG.append({
                    "vol": vol_num,
                    "page": curr_num,
                    "transliteration": first_line,
                    "reconstructed_title": reconstructed_title,
                    "context": context_preview
                })
                merged_blocks.append(reconstructed_title)
                curr_title_fa = reconstructed_title
                repaired_blk = repair_transliteration_with_fa_context(curr_title_fa, blk)
                merged_blocks.append(repaired_blk)
                continue

        # Strict Latin Transliteration merger: ONLY merge onto previous block if BOTH previous block AND current block are Latin Transliterations!
        prev_is_translit = (merged_blocks and bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', merged_blocks[-1].split('\n')[-1].strip())))

        if curr_is_translit:
            repaired_blk = repair_transliteration_with_fa_context(curr_title_fa, blk)
            if prev_is_translit:
                merged_blocks[-1] += f"\n{repaired_blk}"
            else:
                merged_blocks.append(repaired_blk)
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

        # Incomplete sentence merger: if previous block ends without terminal punctuation, merge inline!
        if merged_blocks:
            prev_last_line = merged_blocks[-1].strip().split('\n')[-1].strip()
            prev_is_title = (merged_blocks[-1].startswith('●') or bool(re.search(r'/\s*[\u0600-\u06FF\s]+\s*/(?:\s*[\u0600-\u06FF]+)?$', prev_last_line)))
            prev_is_translit_block = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', prev_last_line))
            prev_no_term = not prev_last_line.endswith(('.', ':', '؛', ']', '»', '؟', '!', ')', '×'))
            curr_is_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or first_line.startswith('چاپ:') or bool(re.match(r'^\d+\.\s+[آ-ی]', first_line)) or is_title_fa)
            
            # LATIN TRANSLITERATION BLOCKS MUST NEVER MERGE INLINE WITH PERSIAN AUTHOR BLOCKS!
            if prev_no_term and not prev_is_title and not prev_is_translit_block and not curr_is_field and not first_line.startswith('●'):
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
                continue
                
        merged_blocks.append(blk)

    # Check if last block of page is an incomplete title entry (starts with ● but lacks category slash / or period .)
    is_last_block_incomplete_title = False
    if merged_blocks:
        last_blk = merged_blocks[-1].strip().split('\n')[-1].strip()
        if last_blk.startswith('●') and '/' not in last_blk and not last_blk.endswith('.'):
            is_last_block_incomplete_title = True

    page_text = "\n\n".join(merged_blocks)
    return page_num_tag, page_text, curr_num, curr_title_fa, is_last_block_incomplete_title

def main():
    fpath = "sources/json/30.json" # Volume 16 JSON
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
    os.makedirs("reports", exist_ok=True)
    out_file = "scratch/test_chunk_vol16.md"
    report_file = "reports/reconstructed_titles.md"
    
    last_num = 1 # Start Volume 16 tracking
    last_title_fa = ""
    title_incomplete = False
    final_text = ""
    
    # Test Volume 16 start (sheets 474 to 495 of 30.json)
    for sheet_idx in range(474, 495):
        if sheet_idx >= len(pages_nodes): break
        p = pages_nodes[sheet_idx]
        tag, txt, last_num, last_title_fa, title_incomplete = process_page_node(p, last_num, last_title_fa, title_incomplete, vol_num=16)
        if not txt: continue
        
        if not final_text:
            final_text = f"{tag}\n{txt}" if tag else txt
        else:
            if tag:
                last_line = final_text.strip().split('\n')[-1].strip()
                first_line_next = txt.strip().split('\n')[0].strip()
                
                # If page split happened inside an incomplete title:
                if last_line.startswith('●') and '/' not in last_line and not last_line.endswith('.'):
                    final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
                else:
                    is_last_translit = bool(re.match(rf'^(?:\(-|\()?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}', last_line))
                    is_last_title = bool(final_text.strip().split('\n\n')[-1].startswith('●') or '/' in last_line)
                    
                    if is_last_translit or is_last_title or last_line[-1] in ['.', ':', ']', '»', '؛'] or first_line_next.startswith('●') or first_line_next.startswith('–') or first_line_next.startswith('→'):
                        # Attach page tag without extra blank line before it!
                        final_text = final_text.rstrip() + f"\n{tag}\n" + txt.lstrip()
                    else:
                        final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
            else:
                final_text += f"\n\n{txt}"

    with open(out_file, 'w', encoding='utf-8') as f:
        f.write(final_text)

    # Generate Reconstructed Titles Report with Volume Column!
    report_md = "# فهرست عناوین بازسازی‌شده از روی آوانگاری (Reconstructed Titles Log)\n\n"
    report_md += "| # | جلد | صفحه | عنوان بازسازی‌شده | آوانگاری لاتین در منبع | بافت / مؤلف |\n"
    report_md += "|---|-----|------|-------------------|------------------------|-------------|\n"
    for i, item in enumerate(RECONSTRUCTED_LOG, 1):
        report_md += f"| {i} | {item['vol']} | {item['page']} | **{item['reconstructed_title']}** | `{item['transliteration']}` | {item['context']} |\n"

    with open(report_file, 'w', encoding='utf-8') as f:
        f.write(report_md)
        
    print(f"🎉 Successfully regenerated Volume 16 test snippet: {out_file}")
    print(f"📋 Generated Reconstructed Titles Report: {report_file} ({len(RECONSTRUCTED_LOG)} items logged)")

if __name__ == "__main__":
    main()

