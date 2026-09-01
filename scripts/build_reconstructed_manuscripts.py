import os
import sys
import json
import re
import time

start_t = time.time()

# 1. Load volume starts mapping
with open('sources/volume_starts.txt', 'r', encoding='utf-8') as f:
    v_lines = f.readlines()

vol_starts = {}
for line in v_lines:
    m = re.match(r'vol_(\d+):\s*(\d+)\.json,\s*sheet\s*(\d+)', line)
    if m:
        vol = int(m.group(1))
        j_num = int(m.group(2))
        sheet = int(m.group(3))
        vol_starts[vol] = (j_num, sheet)

def get_json_files_for_vol(vol):
    j_start, _ = vol_starts.get(vol, (1, 1))
    next_vol = vol + 1
    if next_vol in vol_starts:
        j_end, _ = vol_starts[next_vol]
    else:
        j_end = 68
    return list(range(j_start, min(j_end + 2, 69)))

LIBRARY_CITIES = [
    'تهران؛', 'مشهد؛', 'قم؛', 'اصفهان؛', 'تبریز؛', 'شیراز؛', 'یزد؛', 'کرمان؛', 'رشت؛', 'ساری؛',
    'همدان؛', 'قزوین؛', 'کاشان؛', 'زنجان؛', 'ارومیه؛', 'سنندج؛', 'کرمانشاه؛', 'اهواز؛', 'خرم‌آباد؛',
    'گرگان؛', 'سمنان؛', 'اراک؛', 'اردبیل؛', 'بوشهر؛', 'بندرعباس؛', 'زاهدان؛', 'بیرجند؛', 'بجنورد؛',
    'لندن؛', 'پاریس؛', 'استانبول؛', 'قاهره؛', 'نجف؛', 'کربلا؛', 'بغداد؛', 'سامرا؛', 'کاظمین؛',
    'بیروت؛', 'دمشق؛', 'پیشاور؛', 'لاهور؛', 'دهلی؛', 'کلکته؛', 'علیگر؛', 'حیدرآباد؛', 'پتنه؛', 'رامپور؛',
    'کابل؛', 'هرات؛', 'مزار شریف؛', 'تاشکند؛', 'دوشنبه؛', 'سمرقند؛', 'بخارا؛', 'باکو؛', 'ایروان؛', 'تفلیس؛',
    'پترزبورگ؛', 'مسکو؛', 'برلین؛', 'مونیخ؛', 'لایپزیگ؛', 'وین؛', 'رم؛', 'واتیکان؛', 'مادرید؛', 'لیدن؛',
    'کمبریج؛', 'آکسفورد؛', 'منچستر؛', 'دوبلین؛', 'پرینستون؛', 'کلمبیا؛', 'هاروارد؛', 'شیکاگو؛', 'ییل؛'
]

def is_bare_shelfmark(line):
    clean = line.strip()
    if not clean:
        return False
    # Check if starts with a number or starts with a library city
    m_num = re.match(r'^(?:\d+[\.\-\)]|\.\s*\d+)?\s*(.*)$', clean)
    if not m_num:
        return False
    rest = m_num.group(1).strip()
    if any(rest.startswith(c) for c in LIBRARY_CITIES) or any(c in rest for c in ['شماره نسخه:', '؛ شماره نسخه:', 'ش:']):
        if not any(kw in rest for kw in ['خط:', 'کاغذ:', 'آغاز:', 'انجام:', 'جلد:']):
            return True
    return False

def is_orphan_description(line):
    clean = line.strip()
    if not clean:
        return False
    if re.match(r'^(?:\d+[\.\-\)]|\.\s*\d+)', clean):
        return False
    if clean.startswith('●'):
        return False
    if any(clean.startswith(p) for p in ['خط:', 'کاغذ:', 'آغاز:', 'انجام:', 'جلد:', 'اندازه:', 'افتادگی:']):
        return True
    return False

def split_shelfmark_and_desc(para_text):
    # Normalize digits from Persian to English for shelfmark numbers
    # Separate shelfmark line from description line
    m = re.search(r'(?:\s|^)(آغاز:|انجام:|خط:|کاغذ:|مؤلف:|کاتب:|چاپ:|افتادگی:|جلد:|قطع:|اندازه:|مصحح|محشی|مجدول|فاقد|وقف:|تملک:)', para_text)
    if m:
        idx = m.start()
        shelfmark = para_text[:idx].strip()
        desc = para_text[idx:].strip()
        return shelfmark, desc
    return para_text.strip(), ""

def extract_json_pages_cache(json_files):
    pages = []
    for j_num in json_files:
        jpath = f'sources/json/{j_num}.json'
        if not os.path.exists(jpath):
            continue
        try:
            with open(jpath, 'r', encoding='utf-8') as f:
                data = json.load(f)
            for child in data.get('children', []):
                html = child.get('html', '')
                if html:
                    pages.append({
                        'json_file': j_num,
                        'sheet': child.get('page'),
                        'html': html
                    })
        except Exception as e:
            pass
    return pages

def find_matching_json_page(shelfmark_sample, json_pages):
    # Extract unique search keywords (e.g. city + numbers)
    # Convert digits to Persian for search in JSON
    tr_en_to_fa = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')
    
    tokens = [w for w in shelfmark_sample.split() if len(w) >= 3 and w not in ['شماره', 'نسخه:', 'نسخه', 'تهران؛', 'قم؛', 'مشهد؛']]
    if not tokens:
        tokens = shelfmark_sample.split()[:3]
        
    for pg in json_pages:
        html = pg['html']
        match_count = 0
        for tok in tokens:
            tok_fa = tok.translate(tr_en_to_fa)
            if tok in html or tok_fa in html:
                match_count += 1
        if match_count >= max(1, len(tokens) // 2):
            return pg
            
    return None

def process_volume(vol):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath):
        return []

    with open(fpath, 'r', encoding='utf-8') as f:
        lines = [l.rstrip('\r\n') for l in f]

    num_lines = len(lines)
    j_files = get_json_files_for_vol(vol)
    json_pages = extract_json_pages_cache(j_files)

    clusters = []
    i = 0
    current_page = "نامشخص"

    while i < num_lines:
        line_str = lines[i]
        clean = line_str.strip()

        m_pg = re.search(r'<!--\s*page:\s*(\d+)\s*-->', line_str)
        if m_pg:
            current_page = m_pg.group(1)

        if is_bare_shelfmark(clean):
            start_l_idx = i + 1
            bare_shelfmarks = [clean]
            j = i + 1

            while j < num_lines:
                nxt = lines[j].strip()
                if not nxt or (nxt.startswith('<!--') and nxt.endswith('-->')):
                    j += 1
                    continue
                if is_bare_shelfmark(nxt):
                    bare_shelfmarks.append(nxt)
                    j += 1
                else:
                    break

            if len(bare_shelfmarks) >= 3:
                desc_lines = []
                k = j
                while k < num_lines:
                    nxt = lines[k].strip()
                    if not nxt or (nxt.startswith('<!--') and nxt.endswith('-->')):
                        k += 1
                        continue
                    if is_orphan_description(nxt):
                        desc_lines.append(nxt)
                        k += 1
                    else:
                        break

                if len(desc_lines) >= 2:
                    # Look up matching JSON page
                    matched_pg = find_matching_json_page(bare_shelfmarks[0], json_pages)
                    
                    reconstructed_records = []
                    if matched_pg:
                        paras = matched_pg['html'].split('</p>')
                        for p in paras:
                            p_clean = re.sub(r'<[^>]+>', ' ', p).strip()
                            p_clean = ' '.join(p_clean.split())
                            # Convert Persian numbers back to standard English digits in text
                            tr_fa_to_en = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
                            p_clean_en = p_clean.translate(tr_fa_to_en)
                            
                            # Check if paragraph is a manuscript record
                            if any(c in p_clean for c in LIBRARY_CITIES) or any(c in p_clean for c in ['شماره نسخه:', '؛ شماره نسخه:']):
                                sh, d = split_shelfmark_and_desc(p_clean_en)
                                if d:
                                    reconstructed_records.append(f"{sh}\n{d}")
                                else:
                                    reconstructed_records.append(sh)

                    clusters.append({
                        'vol': vol,
                        'page': current_page,
                        'start_line': start_l_idx,
                        'end_line': k,
                        'num_shelfmarks': len(bare_shelfmarks),
                        'num_descriptions': len(desc_lines),
                        'current_text': '\n'.join(lines[start_l_idx-1:k]),
                        'reconstructed': '\n\n'.join(reconstructed_records) if reconstructed_records else "در حال استخراج دقیق...",
                        'has_json_match': bool(matched_pg)
                    })
                    i = k
                    continue

        i += 1

    return clusters

if __name__ == '__main__':
    vol1_c = process_volume(1)
    print(f"Vol 1 processed: {len(vol1_c)} clusters found.")
    for idx, c in enumerate(vol1_c[:3], 1):
        print(f"=== Cluster {idx} (Vol {c['vol']} Page {c['page']} Lines {c['start_line']}-{c['end_line']}) ===")
        print(f"Shelfmarks: {c['num_shelfmarks']}, Descs: {c['num_descriptions']}, Matched JSON: {c['has_json_match']}")
        print("Sample Reconstructed:")
        print(c['reconstructed'][:300])
        print()
