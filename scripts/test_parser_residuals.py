#!/usr/bin/env python3
"""
Round 2: Prototype Parser & Residual Text Analyzer for Fankha Corpus.
Incorporates:
1. Editorial correction notes (نقد و تصحیحات فهرست‌نگار)
2. Inverted and compound scribe names (اسامی کاتبان)
3. Composite places (شهر + مدرسه/مکان)
4. Original copy references (نسخه اصل)
5. Annex & attached treatises (الحاقات و ضمایم مجموعه)
6. Lacunae & defects (افتادگی‌ها)
7. Ownership & seals (تملک و مهرها)
8. Refined dimensions, dates, and physical features
"""

import re
import json
from collections import Counter

PAGE_TAG_PATTERN = re.compile(r'<!--\s*page:\s*(\d+)\s*-->')

def split_entries(text):
    lines = text.split('\n')
    entries = []
    curr_entry_lines = []
    curr_page = 1
    
    for line in lines:
        page_matches = PAGE_TAG_PATTERN.findall(line)
        if page_matches:
            curr_page = int(page_matches[-1])
            
        stripped = line.strip()
        is_work_header = stripped.startswith('● ')
        is_referral = '←' in stripped and not is_work_header and not stripped.startswith('آغاز') and not stripped.startswith('انجام')
        
        if (is_work_header or is_referral) and curr_entry_lines:
            entries.append((curr_entry_lines, curr_page))
            curr_entry_lines = []
            
        curr_entry_lines.append(line)
        
    if curr_entry_lines:
        entries.append((curr_entry_lines, curr_page))
        
    return entries

def parse_work_header(header_line):
    raw = header_line.lstrip('● ').strip()
    clean_line = PAGE_TAG_PATTERN.sub('', raw).strip()
    
    parts = [p.strip() for p in clean_line.split('/')]
    titles_part = parts[0] if len(parts) > 0 else clean_line
    subject = parts[1] if len(parts) > 1 else None
    language = parts[2] if len(parts) > 2 else None
    
    titles = [t.strip() for t in titles_part.split('=')]
    primary_title = titles[0]
    alternative_titles = titles[1:]
    
    return {
        'primary_title': primary_title,
        'alternative_titles': alternative_titles,
        'subject': subject,
        'language': language
    }

def parse_manuscript_block(raw_ms_text, current_page):
    ms_data = {
        'start_page': current_page,
        'end_page': current_page,
        'city': None,
        'library': None,
        'shelfmark': None,
        'original_copy_ref': None,
        'scribe': None,
        'is_bika': False,
        'is_autograph': False,
        'copy_date_raw': None,
        'copy_place': None,
        'script': None,
        'folios': None,
        'lines': None,
        'dimensions': None,
        'paper': None,
        'binding': None,
        'format': None,
        'incipit_matches_work': False,
        'explicit_matches_work': False,
        'incipit_text': None,
        'explicit_text': None,
        'incipit_type': 'کتاب',
        'is_corrected': False,
        'has_marginal_notes': False,
        'is_ruled': False,
        'has_catchwords': False,
        'is_facsimile': False,
        'is_distinct_work': False,
        'defects': None,
        'editorial_correction_notes': [],
        'annex_notes': [],
        'ownership_and_seals': [],
        'catalog_citation': None,
        'raw_text': raw_ms_text
    }
    
    extracted_spans = []
    lines = [l.strip() for l in raw_ms_text.split('\n') if l.strip()]
    if not lines:
        return ms_data, ""
        
    header_line = lines[0]
    clean_header = re.sub(r'^\d+\.\s*', '', header_line)
    
    header_match = re.match(r'([^؛]+)؛\s*([^؛]+)؛\s*شماره نسخه:\s*(.*)', clean_header)
    if header_match:
        ms_data['city'] = header_match.group(1).strip()
        ms_data['library'] = header_match.group(2).strip()
        ms_data['shelfmark'] = header_match.group(3).strip()
        extracted_spans.append(header_line)
        
    remaining_text = "\n".join(lines[1:])
    
    # 1. Catalog citation [ ... ]
    citation_match = re.search(r'\[([^\]]+)\]', remaining_text)
    if citation_match:
        ms_data['catalog_citation'] = f"[{citation_match.group(1).strip()}]"
        extracted_spans.append(citation_match.group(0))
        
    # 2. Original copy reference for facsimiles: نسخه اصل: ...
    orig_m = re.search(r'نسخه اصل:\s*([^؛\n]+)', remaining_text)
    if orig_m:
        ms_data['original_copy_ref'] = orig_m.group(1).strip()
        extracted_spans.append(orig_m.group(0))
        
    # 3. Editorial & Critical notes (including contextual evidence به قرینه):
    editorial_patterns = [
        r'[^؛\n]*به قرینه[^؛\n]*',
        r'در فهرست [^؛\n]*(?:تصحیح شد|دانسته شده[^؛\n]*)',
        r'نام مؤلف [^؛\n]*(?:ذکر شد|تصحیح شد|تعیین شد|به دست آمد)',
        r'نام کتاب [^؛\n]*(?:ذکر شد|تصحیح شد|تعیین شد|به دست آمد)',
        r'به استناد نسخه [^؛\n]*(?:ذکر شد|تصحیح شد)'
    ]
    for ep in editorial_patterns:
        for em in re.finditer(ep, remaining_text):
            ms_data['editorial_correction_notes'].append(em.group(0).strip())
            extracted_spans.append(em.group(0))
            
    # 3.1 Marginalia Content & Notes (توضیحات حواشی):
    marginalia_m = re.search(r'در حواشی [^؛\n]+', remaining_text)
    if marginalia_m:
        extracted_spans.append(marginalia_m.group(0))
            
    # 3.1 Art & Illumination (تذهیب، سرلوح، جداول):
    art_patterns = [
        r'دارای [^؛\n]*(?:سرلوح|شمسه|ترنج|کتیبه|مذهب|جدول|تصاویر|مجلس)[^؛\n]*',
        r'با جدول[^؛\n]*',
        r'(?:یک\s*)?سرلوح\s*مذهب[^؛\n]*',
        r'حاوی جداول[^؛\n]*'
    ]
    for art_p in art_patterns:
        for art_m in re.finditer(art_p, remaining_text):
            extracted_spans.append(art_m.group(0))
            
    # 3.2 Physical Condition / Conservation (سلامت فیزیکی):
    condition_m = re.search(r'(?:بسیار\s*)?(?:کثیف|فرسوده|آب‌دیده|کرم‌خورده|وصالی‌شده|آسیب‌دیده|رطوبت‌زده)', remaining_text)
    if condition_m:
        extracted_spans.append(condition_m.group(0))
            
    # 4. Annex notes & attached treatises:
    annex_patterns = [
        r'این رساله به نسخه [^؛\n]+ ضمیمه شده است',
        r'به نسخه [^؛\n]+ ضمیمه شده است',
        r'دنباله رساله [^؛\n]+',
        r'[^؛\n]+ ضمیمه دارد'
    ]
    for ap in annex_patterns:
        for am in re.finditer(ap, remaining_text):
            ms_data['annex_notes'].append(am.group(0).strip())
            extracted_spans.append(am.group(0))
            
    # 5. Defects / Lacunae & Missing parts:
    defect_m = re.search(r'(?:افتادگی:|افتادگی)\s*([^؛\n]+)', remaining_text)
    if defect_m:
        ms_data['defects'] = defect_m.group(0).strip()
        extracted_spans.append(defect_m.group(0))
        
    lacks_m = re.search(r'فاقد\s*([^؛\n]+)', remaining_text)
    if lacks_m:
        extracted_spans.append(lacks_m.group(0))
        
    # 5.1 Ownership & Seals:
    ownership_m = re.search(r'(?:تملک|مالک):\s*([^؛\n]+)', remaining_text)
    if ownership_m:
        ms_data['ownership_and_seals'].append(ownership_m.group(0).strip())
        extracted_spans.append(ownership_m.group(0))
        
    waqf_m = re.search(r'(?:واقف|وقف|وقفنامه):\s*([^؛\n]+)', remaining_text)
    if waqf_m:
        ms_data['ownership_and_seals'].append(waqf_m.group(0).strip())
        extracted_spans.append(waqf_m.group(0))
        
    seals_m = re.search(r'(?:دارای\s*)?مهر(?:\s*های)?:\s*([^؛\n]+)', remaining_text)
    if seals_m:
        ms_data['ownership_and_seals'].append(seals_m.group(0).strip())
        extracted_spans.append(seals_m.group(0))
        
    # 5.2 Text block dimensions (ابعاد متن):
    text_dim_m = re.search(r'ابعاد متن:\s*([^،؛\n]+)', remaining_text)
    if text_dim_m:
        extracted_spans.append(text_dim_m.group(0))
        
    # 5.3 Codex notes & Fawaid (جنگ‌ها و یادداشت‌های فائده):
    codex_patterns = [
        r'(?:این\s*)?رساله در (?:متن\s*)?نسخه موسوم به [^؛\n]+',
        r'دارای فائده [^؛\n]+',
        r'فوائدی در [^؛\n]+'
    ]
    for cp in codex_patterns:
        for cm in re.finditer(cp, remaining_text):
            ms_data['annex_notes'].append(cm.group(0).strip())
            extracted_spans.append(cm.group(0))
        
    # 6. Incipit / Explicit
    if 'آغاز و انجام: برابر' in remaining_text:
        ms_data['incipit_matches_work'] = True
        ms_data['explicit_matches_work'] = True
        extracted_spans.append('آغاز و انجام: برابر')
    else:
        if 'آغاز: برابر' in remaining_text:
            ms_data['incipit_matches_work'] = True
            extracted_spans.append('آغاز: برابر')
        else:
            inc_m = re.search(r'آغاز:\s*(?:موجود:)?\s*([^؛\n]+)', remaining_text)
            if inc_m:
                ms_data['incipit_text'] = inc_m.group(1).strip()
                extracted_spans.append(inc_m.group(0))
                
        if 'انجام: برابر' in remaining_text:
            ms_data['explicit_matches_work'] = True
            extracted_spans.append('انجام: برابر')
        else:
            exp_m = re.search(r'انجام:\s*(?:موجود:)?\s*([^؛\n]+)', remaining_text)
            if exp_m:
                ms_data['explicit_text'] = exp_m.group(1).strip()
                extracted_spans.append(exp_m.group(0))
                
    # 7. Scribe (support "کا:" or "کا " and inverted names)
    if 'کاتب = مؤلف' in remaining_text:
        ms_data['is_autograph'] = True
        extracted_spans.append('کاتب = مؤلف')
    elif 'بی‌کا' in remaining_text or 'بی کا' in remaining_text:
        ms_data['is_bika'] = True
        extracted_spans.append('بی‌کا')
        extracted_spans.append('بی کا')
    else:
        scribe_m = re.search(r'(?:کا:|کا\s+)\s*(.+?)(?=(?:،\s*تا[:\s]|،\s*جا[:\s]|؛|\n|$))', remaining_text)
        if scribe_m:
            ms_data['scribe'] = scribe_m.group(1).strip()
            extracted_spans.append(scribe_m.group(0))
            
    # 8. Date: "تا: ..." or "تا ..."
    if 'بی‌تا' in remaining_text or 'بی تا' in remaining_text:
        ms_data['copy_date_raw'] = 'بی‌تا'
        extracted_spans.append('بی‌تا')
        extracted_spans.append('بی تا')
    else:
        date_m = re.search(r'(?:تا:|تا\s+)\s*(.+?)(?=(?:،\s*جا[:\s]|؛|\n|$))', remaining_text)
        if date_m:
            ms_data['copy_date_raw'] = date_m.group(1).strip()
            extracted_spans.append(date_m.group(0))
            
    # 9. Place: "جا: ..." (stops at "؛" or newline)
    place_m = re.search(r'جا:\s*([^؛\n]+)', remaining_text)
    if place_m:
        ms_data['copy_place'] = place_m.group(1).strip()
        extracted_spans.append(place_m.group(0))
        
    # 10. Script: "خط: ..."
    script_m = re.search(r'خط:\s*([^؛\n]+)', remaining_text)
    if script_m:
        ms_data['script'] = script_m.group(1).strip()
        extracted_spans.append(script_m.group(0))
        
    # 11. Dimensions: "اندازه: ...سم"
    dim_m = re.search(r'اندازه:\s*([^؛\n\[]+)', remaining_text)
    if dim_m:
        ms_data['dimensions'] = dim_m.group(1).strip()
        extracted_spans.append(dim_m.group(0))
        
    # 12. Paper: "کاغذ: ..."
    paper_m = re.search(r'کاغذ:\s*([^،؛\n]+)', remaining_text)
    if paper_m:
        ms_data['paper'] = paper_m.group(1).strip()
        extracted_spans.append(paper_m.group(0))
        
    # 13. Binding: "جلد: ..."
    binding_m = re.search(r'جلد:\s*([^،؛\n]+)', remaining_text)
    if binding_m:
        ms_data['binding'] = binding_m.group(1).strip()
        extracted_spans.append(binding_m.group(0))
        
    # 14. Format: "قطع: ..."
    format_m = re.search(r'قطع:\s*([^،؛\n]+)', remaining_text)
    if format_m:
        ms_data['format'] = format_m.group(1).strip()
        extracted_spans.append(format_m.group(0))
        
    # 15. Folios: e.g. "45گ", "28ص (1-28)", "6گ (43ر-48پ)", "۲۸ برگ"
    fol_m = re.search(r'(\d+[\d/]*\s*(?:گ|ص|برگ)(?:\s*\([^)]+\))?)', remaining_text)
    if fol_m:
        ms_data['folios'] = fol_m.group(1).strip()
        extracted_spans.append(fol_m.group(0))
        
    # 16. Lines: e.g. "17 سطر", "15 تا 26 سطر راسته و چلیپا (13×18)", "مختلف السطر", "26 سطر مورب"
    lines_m = re.search(r'((?:مختلف السطر|مختلف|\d+(?:\s*تا\s*\d+|-\d+)?\s*سطر)(?:\s*(?:راسته و چلیپا|راسته|چلیپا|مورب))?(?:\s*\([^)]+\))?)', remaining_text)
    if lines_m:
        ms_data['lines'] = lines_m.group(1).strip()
        extracted_spans.append(lines_m.group(0))
        
    # 17. Flags
    flag_keywords = [
        ('مصحح', 'is_corrected'),
        ('محشی', 'has_marginal_notes'),
        ('مجدول', 'is_ruled'),
        ('رکابه‌دار', 'has_catchwords'),
        ('عکسی', 'is_facsimile'),
        ('غیر همانند', 'is_distinct_work'),
    ]
    for kw, prop in flag_keywords:
        if kw in remaining_text:
            ms_data[prop] = True
            extracted_spans.append(kw)
            
    # Subtraction calculation
    residual = PAGE_TAG_PATTERN.sub(' ', raw_ms_text)
    # Sort spans descending by length to avoid partial string replacements
    extracted_spans = sorted(list(set(extracted_spans)), key=len, reverse=True)
    for span in extracted_spans:
        residual = residual.replace(span, ' ')
        
    # Remove standard punctuation and delimiters
    residual = re.sub(r'[؛،:\[\]\(\)\n\-\./\d+×\?؟=]', ' ', residual)
    residual = re.sub(r'\s+', ' ', residual).strip()
    
    return ms_data, residual

def audit_sample_entries(file_path, max_entries=500):
    with open(file_path, 'r', encoding='utf-8') as f:
        text = f.read()
        
    entries = split_entries(text)
    residual_counter = Counter()
    total_ms = 0
    clean_ms_count = 0
    residuals_sample = []
    
    for entry_lines, page in entries[:max_entries]:
        entry_text = "\n".join(entry_lines).strip()
        if not entry_text:
            continue
            
        first_line = entry_lines[0].strip()
        if '←' in first_line and not first_line.startswith('●'):
            continue
            
        if first_line.startswith('●'):
            ms_blocks = []
            curr_ms = []
            in_ms = False
            
            for line in entry_lines[1:]:
                if 'شماره نسخه:' in line or 'شماره نسخه :' in line:
                    if curr_ms:
                        ms_blocks.append("\n".join(curr_ms))
                        curr_ms = []
                    in_ms = True
                if in_ms:
                    curr_ms.append(line)
            if curr_ms:
                ms_blocks.append("\n".join(curr_ms))
                
            for raw_ms in ms_blocks:
                total_ms += 1
                ms_data, residual = parse_manuscript_block(raw_ms, page)
                
                # Check residual
                tokens = [t.strip() for t in residual.split() if len(t.strip()) > 1 and t.strip() not in ('تا', 'کا', 'جا', 'خط', 'سم', 'ص', 'گ')]
                if not tokens:
                    clean_ms_count += 1
                else:
                    residuals_sample.append((ms_data['library'], ms_data['shelfmark'], " ".join(tokens), raw_ms))
                    for t in tokens:
                        residual_counter[t] += 1
                        
    print(f"============================================================")
    print(f"ROUND 3 RESIDUAL TEXT AUDIT REPORT ({max_entries} Entries)")
    print(f"============================================================")
    print(f"Total Manuscripts processed : {total_ms}")
    print(f"100% Perfectly Parsed (Zero Residual): {clean_ms_count} ({(clean_ms_count/total_ms)*100:.1f}%)")
    print(f"Manuscripts with Residuals  : {total_ms - clean_ms_count} ({((total_ms - clean_ms_count)/total_ms)*100:.1f}%)")
    print(f"\nRemaining Top 25 residual words:")
    for word, count in residual_counter.most_common(25):
        print(f"  {word:25} -> {count} times")
        
    print(f"\n--- Sample Residuals from Round 3 ---")
    for lib, shelf, res, raw in residuals_sample[:8]:
        print(f"\n[Library: {lib}, Shelf: {shelf}]")
        print(f"  Residual: {res}")
        print(f"  Raw: {raw.strip()[:180]}...")

if __name__ == '__main__':
    audit_sample_entries('sources/text/fahares_vol_01.txt', max_entries=500)
