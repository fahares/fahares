import os
import sys
import re
import time

start_t = time.time()

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
    # Starts with a number e.g. '65. تهران؛ ...' or '.65 تهران؛ ...'
    m_num = re.match(r'^(?:\d+[\.\-\)]|\.\s*\d+)\s*(.*)$', clean)
    if not m_num:
        return False
    rest = m_num.group(1).strip()
    # Must contain a city or library or shelfmark keyword
    if any(rest.startswith(c) for c in LIBRARY_CITIES) or any(c in rest for c in ['شماره نسخه:', '؛ شماره نسخه:', 'ش:']):
        # Must NOT contain detailed description keywords already attached
        if not any(kw in rest for kw in ['خط:', 'کاغذ:', 'آغاز:', 'انجام:', 'جلد:', 'اندازه:']):
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

def scan_all():
    clusters = []

    for vol in range(1, 35):
        fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
        if not os.path.exists(fpath):
            continue

        with open(fpath, 'r', encoding='utf-8') as f:
            lines = [l.rstrip('\r\n') for l in f]

        num_lines = len(lines)
        i = 0
        current_page = "نامشخص"

        while i < num_lines:
            line_str = lines[i]
            clean = line_str.strip()

            # Track page tag
            m_pg = re.search(r'<!--\s*page:\s*(\d+)\s*-->', line_str)
            if m_pg:
                current_page = m_pg.group(1)

            if is_bare_shelfmark(clean):
                # We found a potential start of a bare shelfmark cluster
                start_l_idx = i + 1
                bare_shelfmarks = [(i + 1, clean)]
                j = i + 1
                
                while j < num_lines:
                    nxt = lines[j].strip()
                    m_pg_inner = re.search(r'<!--\s*page:\s*(\d+)\s*-->', lines[j])
                    if m_pg_inner:
                        current_page = m_pg_inner.group(1)
                    if not nxt or (nxt.startswith('<!--') and nxt.endswith('-->')):
                        j += 1
                        continue
                    if is_bare_shelfmark(nxt):
                        bare_shelfmarks.append((j + 1, nxt))
                        j += 1
                    else:
                        break

                # If we have 3 or more bare shelfmarks in a row, check what follows
                if len(bare_shelfmarks) >= 3:
                    desc_lines = []
                    k = j
                    while k < num_lines:
                        nxt = lines[k].strip()
                        m_pg_inner = re.search(r'<!--\s*page:\s*(\d+)\s*-->', lines[k])
                        if m_pg_inner:
                            current_page = m_pg_inner.group(1)
                        if not nxt or (nxt.startswith('<!--') and nxt.endswith('-->')):
                            k += 1
                            continue
                        if is_orphan_description(nxt):
                            desc_lines.append((k + 1, nxt))
                            k += 1
                        else:
                            break

                    if len(desc_lines) >= 2:
                        clusters.append({
                            'vol': vol,
                            'page': current_page,
                            'start_line': start_l_idx,
                            'end_line': k,
                            'num_shelfmarks': len(bare_shelfmarks),
                            'num_descriptions': len(desc_lines),
                            'shelfmarks': bare_shelfmarks,
                            'descriptions': desc_lines
                        })
                        i = k
                        continue

            i += 1

    print(f"Total interleaved manuscript clusters found: {len(clusters)}")
    
    report_path = 'reports/interleaved_manuscripts_discovery.md'
    os.makedirs('reports', exist_ok=True)
    with open(report_path, 'w', encoding='utf-8') as f:
        f.write("# گزارش جامع کشف اختلال صفحه‌آرایی دو ستونه و جدا افتادن مشخصات نسخه‌های خطی\n\n")
        f.write(f"تعداد کل بلوک‌ها و صفحات دچار اختلال شناسایی‌شده: **{len(clusters):,}** بلوک\n\n")
        f.write("="*60 + "\n\n")

        for idx, c in enumerate(clusters, 1):
            f.write(f"### شماره {idx} (جلد {c['vol']:02d} - صفحه {c['page']} | سطور {c['start_line']} تا {c['end_line']})\n\n")
            f.write(f"- **تعداد شماره نسخه‌ها:** {c['num_shelfmarks']} نسخه\n")
            f.write(f"- **تعداد سطور مشخصات جدا افتاده:** {c['num_descriptions']} سطر\n\n")
            f.write("📋 **شماره نسخه‌های استخراج‌شده (ستون اول):**\n```text\n")
            for _, s in c['shelfmarks']:
                f.write(f"{s}\n")
            f.write("```\n\n")
            f.write("📝 **توصیفات جدا افتاده (ستون دوم):**\n```text\n")
            for _, d in c['descriptions']:
                f.write(f"{d}\n")
            f.write("```\n\n")
            f.write("\n---\n\n")

    print(f"Finished in {time.time() - start_t:.2f}s! Report saved to {report_path}")

if __name__ == '__main__':
    scan_all()
