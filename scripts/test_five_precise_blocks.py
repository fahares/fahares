import os
import json
import re

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
    return list(range(j_start, min(j_end + 1, 69)))

LIBRARY_CITIES = [
    'تهران؛', 'مشهد؛', 'قم؛', 'اصفهان؛', 'تبریز؛', 'شیراز؛', 'یزد؛', 'کرمان؛', 'رشت؛', 'ساری؛',
    'همدان؛', 'قزوین؛', 'کاشان؛', 'زنجان؛', 'ارومیه؛', 'سنندج؛', 'کرمانشاه؛', 'اهواز؛', 'خرم‌آباد؛',
    'گرگان؛', 'سمنان؛', 'اراک؛', 'اردبیل؛', 'بوشهر؛', 'بندرعباس؛', 'زاهدان؛', 'بیرجند؛', 'بجنورد؛',
    'لندن؛', 'پاریس؛', 'استانبول؛', 'قاهره؛', 'نجف؛', 'کربلا؛', 'بغداد؛', 'سامرا؛', 'کاظمین؛',
    'بیروت؛', 'دمشق؛', 'پیشاور؛', 'لاهور؛', 'دهلی؛', 'کلکته؛', 'علیگر؛', 'حیدرآباد؛', 'پتنه؛', 'رامپور؛',
    'کابل؛', 'هرات؛', 'مزار شریف؛', 'تاشکند؛', 'دوشنبه؛', 'سمرقند؛', 'بخارا؛', 'باکو؛', 'ایروان؛', 'تفلیس؛'
]

city_pattern = '|'.join(re.escape(c) for c in LIBRARY_CITIES)

def is_truly_bare_shelfmark(line):
    clean = line.strip()
    if not clean or clean.startswith('<!--') or clean.startswith('●'):
        return False
    m_num = re.match(r'^(?:\d+[\.\-\)]|\.\s*\d+)?\s*(.*)$', clean)
    if not m_num:
        return False
    rest = m_num.group(1).strip()
    if not any(c in rest for c in ['شماره نسخه:', '؛ شماره نسخه:', 'ش:']):
        return False
    if any(kw in rest for kw in ['خط:', 'کاغذ:', 'جلد:', 'قطع:', 'سطر', 'سم', '[ف:', '[نشریه:', '[رایانه]', '[ف', 'مؤلف:']):
        return False
    return True

def is_truly_orphan_desc(line):
    clean = line.strip()
    if not clean or clean.startswith('<!--') or clean.startswith('●'):
        return False
    if 'شماره نسخه:' in clean or '؛ شماره نسخه:' in clean:
        return False
    if re.match(r'^\d+[\.\-\)]', clean):
        return False
    if any(clean.startswith(kw) for kw in ['خط:', 'کاغذ:', 'جلد:', 'قطع:', 'آغاز:', 'انجام:', 'افتادگی:', 'فقراتی', 'شامل', 'مؤلف:', 'کاتب:', 'مصحح', 'محشی', 'مجدول', 'فاقد']):
        return True
    if re.search(r'\[ف:\s*[\d\-]+\]', clean) or re.search(r'\[نشریه:\s*[\d\-]+\]', clean):
        return True
    return False

def split_shelfmark_and_desc(para_text):
    m = re.search(r'(?:\s|^)(آغاز:|انجام:|خط:|کاغذ:|مؤلف:|کاتب:|چاپ:|افتادگی:|جلد:|قطع:|اندازه:|مصحح|محشی|مجدول|فاقد|وقف:|تملک:|نخستین)', para_text)
    if m:
        idx = m.start()
        shelfmark = para_text[:idx].strip()
        desc = para_text[idx:].strip()
        return shelfmark, desc
    return para_text.strip(), ""

def extract_all_records_for_volume(j_files):
    all_records = []
    tr_fa_to_en = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
    
    for j_num in j_files:
        jpath = f'sources/json/{j_num}.json'
        if not os.path.exists(jpath):
            continue
        try:
            with open(jpath, 'r', encoding='utf-8') as f:
                data = json.load(f)
            for c in data.get('children', []):
                html = c.get('html', '')
                if not html:
                    continue
                paras = html.split('</p>')
                for p in paras:
                    p_clean = re.sub(r'<[^>]+>', ' ', p).strip()
                    p_clean = ' '.join(p_clean.split())
                    p_clean_en = p_clean.translate(tr_fa_to_en)
                    
                    boundaries = [m.start() for m in re.finditer(r'(?:^|\s)(?:[۰-۱۲۳۴۵۶۷۸۹\d]+[\.\-\)]|\.\s*[۰-۱۲۳۴۵۶۷۸۹\d]+)?\s*(?:' + city_pattern + ')', p_clean_en)]
                    boundaries.append(len(p_clean_en))
                    
                    if len(boundaries) > 2:
                        for b_idx in range(len(boundaries) - 1):
                            chunk = p_clean_en[boundaries[b_idx]:boundaries[b_idx+1]].strip()
                            if chunk and (any(c in chunk for c in LIBRARY_CITIES) or 'شماره نسخه:' in chunk):
                                sh, d = split_shelfmark_and_desc(chunk)
                                all_records.append((sh, d))
                    else:
                        if any(c in p_clean_en for c in LIBRARY_CITIES) or 'شماره نسخه:' in p_clean_en:
                            sh, d = split_shelfmark_and_desc(p_clean_en)
                            all_records.append((sh, d))
        except Exception as e:
            pass
    return all_records

sample_cases = []

for vol in [1, 12]:
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = [l.rstrip('\r\n') for l in f]

    j_files = get_json_files_for_vol(vol)
    vol_json_records = extract_all_records_for_volume(j_files)
    
    i = 0
    current_page = None

    while i < len(lines):
        m_pg = re.search(r'<!--\s*page:\s*(\d+)\s*-->', lines[i])
        if m_pg:
            current_page = int(m_pg.group(1))

        if is_truly_bare_shelfmark(lines[i]):
            start_l_idx = i + 1
            bare_shelfmarks = [lines[i].strip()]
            j = i + 1

            while j < len(lines):
                nxt = lines[j].strip()
                if not nxt:
                    j += 1
                    continue
                if nxt.startswith('<!--') and nxt.endswith('-->'):
                    m_pg_inner = re.search(r'<!--\s*page:\s*(\d+)\s*-->', nxt)
                    if m_pg_inner:
                        current_page = int(m_pg_inner.group(1))
                    j += 1
                    continue
                if is_truly_bare_shelfmark(nxt):
                    bare_shelfmarks.append(nxt)
                    j += 1
                else:
                    break

            if len(bare_shelfmarks) >= 3:
                desc_lines = []
                k = j
                while k < len(lines):
                    nxt = lines[k].strip()
                    if not nxt:
                        k += 1
                        continue
                    if nxt.startswith('<!--') and nxt.endswith('-->'):
                        m_pg_inner = re.search(r'<!--\s*page:\s*(\d+)\s*-->', nxt)
                        if m_pg_inner:
                            current_page = int(m_pg_inner.group(1))
                        k += 1
                        continue
                    if is_truly_orphan_desc(nxt):
                        desc_lines.append(nxt)
                        k += 1
                    else:
                        break

                if len(desc_lines) >= 2:
                    reconstructed = []
                    
                    for b_sh in bare_shelfmarks:
                        # Extract shelfmark number
                        m_snum = re.search(r'شماره نسخه:\s*([^\s]+)', b_sh)
                        matched_desc = None
                        
                        if m_snum:
                            snum = m_snum.group(1)
                            for j_sh, j_desc in vol_json_records:
                                if snum in j_sh:
                                    matched_desc = j_desc
                                    break
                        
                        if matched_desc:
                            reconstructed.append(f"{b_sh}\n{matched_desc}")
                        else:
                            reconstructed.append(b_sh)

                    sample_cases.append({
                        'vol': vol,
                        'page': current_page,
                        'start_line': start_l_idx,
                        'end_line': k,
                        'num_shelfmarks': len(bare_shelfmarks),
                        'num_descriptions': len(desc_lines),
                        'current_text': '\n'.join(lines[start_l_idx-1:k]),
                        'reconstructed': '\n\n'.join(reconstructed)
                    })
                    i = k
                    if len(sample_cases) >= 5:
                        break
                    continue
        i += 1
    if len(sample_cases) >= 5:
        break

print(f"Extracted {len(sample_cases)} sample cases successfully!\n")
for idx, c in enumerate(sample_cases, 1):
    print(f"==================================================")
    print(f"نمونه {idx}: جلد {c['vol']} - صفحه {c['page']} (سطور {c['start_line']} تا {c['end_line']})")
    print(f"تعداد شماره نسخه‌ها: {c['num_shelfmarks']} | تعداد سطور توصیف: {c['num_descriptions']}")
    print(f"\n🔴 [وضعیت فعلی در فایل متنی]:\n{c['current_text']}")
    print(f"\n🟢 [بازسازی دقیق ۱ به ۱ از مرجع JSON]:\n{c['reconstructed']}")
    print()
