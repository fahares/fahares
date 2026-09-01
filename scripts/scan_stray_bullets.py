import os
import sys
import re
import time

start_t = time.time()

FIELD_PREFIXES = [
    'آغاز:', 'انجام:', 'خط:', 'کاغذ:', 'مؤلف:', 'کاتب:', 'چاپ:', 'شماره نسخه:',
    'فهرست:', 'وابسته به:', 'مأخذ:', 'ن.ک.:', 'ن.ک:', 'نک:', 'یادداشت:',
    'تاریخ تألیف:', 'تاریخ کتابت:', 'تاریخ اجازه:', 'محل صدور:', 'نسخه اصل:', 'نسخه:',
    'موضوع:', 'زبان:', 'شرح و حواشی:', 'آغاز و انجام:'
]

LIBRARY_CITIES = [
    'تهران؛', 'مشهد؛', 'قم؛', 'اصفهان؛', 'تبریز؛', 'شیراز؛', 'یزد؛', 'کرمان؛', 'رشت؛', 'ساری؛',
    'همدان؛', 'قزوین؛', 'کاشان؛', 'زنجان؛', 'ارومیه؛', 'سنندج؛', 'کرمانشاه؛', 'اهواز؛', 'خرم‌آباد؛',
    'گرگان؛', 'سمنان؛', 'اراک؛', 'اردبیل؛', 'بوشهر؛', 'بندرعباس؛', 'زاهدان؛', 'بیرجند؛', 'بجنورد؛',
    'لندن؛', 'پاریس؛', 'استانبول؛', 'قاهره؛', 'نجف؛', 'کربلا؛', 'بغداد؛', 'سامرا؛', 'کاظمین؛',
    'بیروت؛', 'دمشق؛', 'پیشاور؛', 'لاهور؛', 'دهلی؛', 'کلکته؛', 'علیگر؛', 'حیدرآباد؛', 'پتنه؛', 'رامپور؛',
    'کابل؛', 'هرات؛', 'مزار شریف؛', 'تاشکند؛', 'دوشنبه؛', 'سمرقند؛', 'بخارا؛', 'باکو؛', 'ایروان؛', 'تفلیس؛',
    'پترزبورگ؛', 'مسکو؛', 'برلین؛', 'مونیخ؛', 'لایپزیگ؛', 'وین؛', 'رم؛', 'واتیکان؛', 'مادرید؛', 'لیدن؛',
    'کمبریج؛', 'آکسفورد؛', 'منچستر؛', 'دوبلین؛', 'پرینستون؛', 'کلمبیا؛', 'هاروارد؛', 'شیکاگو؛', 'ییل؛',
    'میشیگان؛', 'کالیفرنیا؛', 'لس‌آنجلس؛', 'واشنگتن؛', 'نیویورک؛', 'تورنتو؛'
]

def is_cross_reference(line):
    clean = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', line).strip()
    clean = clean.lstrip('●').strip()
    return any(sym in clean for sym in [' ← ', ' -> ', ' ↪ ', '←'])

def is_field_line(line):
    clean = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', line).strip()
    clean = clean.lstrip('●').strip()
    return any(clean.startswith(p) for p in FIELD_PREFIXES)

def is_shelfmark_line(line):
    clean = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', line).strip()
    clean = clean.lstrip('●').strip()
    if any(clean.startswith(c) for c in LIBRARY_CITIES):
        return True
    if re.match(r'^\d+[\.\-\)]\s*(?:[^\/]+؛|تهران|مشهد|قم|اصفهان|شیراز|تبریز|یزد|نسخه|لندن|پاریس)', clean):
        return True
    if any(kw in clean for kw in ['شماره نسخه:', '؛ خط:', 'کاتب = مؤلف', 'کاتب:', 'کا:', 'تا:']) and any(kw in clean for kw in ['سطر', 'گ،', 'گک', 'ص،', 'جلد:']):
        return True
    if clean.startswith('[ف:') or clean.startswith('[نشریه:') or clean.startswith('[دنا:') or clean.startswith('[فیلما:'):
        return True
    return False

def is_main_entry_title(line):
    clean = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', line).strip()
    if not clean.startswith('●'):
        return False
    clean_no_bullet = clean.lstrip('●').strip()
    
    # 1. Cross-references MUST NOT have bullets
    if is_cross_reference(line):
        return False
        
    # 2. Field tags or shelfmark lines MUST NOT have bullets
    if is_field_line(line) or is_shelfmark_line(line):
        return False
        
    # 3. Long narrative paragraph check (> 180 chars with narrative phrases)
    if len(clean_no_bullet) > 180 and any(kw in clean_no_bullet for kw in ['مجموعه‌ای است', 'رساله‌ای است', 'کتابی است', 'تألیف نموده', 'این نسخه']):
        return False
        
    return True

def analyze_and_generate_report():
    candidates = []
    main_entries_count = 0
    total_bullets_found = 0

    for vol in range(1, 35):
        fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
        if not os.path.exists(fpath):
            continue

        with open(fpath, 'r', encoding='utf-8') as f:
            lines = [l.rstrip('\r\n') for l in f]

        num_lines = len(lines)
        for l_idx, line_str in enumerate(lines, 1):
            clean = line_str.strip()
            if '●' not in clean:
                continue

            total_bullets_found += clean.count('●')

            if is_main_entry_title(clean):
                main_entries_count += 1
                continue

            prev_l = lines[l_idx - 2] if l_idx >= 2 else ""
            next_l = lines[l_idx] if l_idx < num_lines else ""

            # Determine category
            cat = "سایر بالت‌های سرگردان"
            if is_cross_reference(clean):
                cat = "مدخل ارجاعی (Cross-reference)"
            elif is_field_line(clean):
                cat = "سطر فیلد مشخصات (Field Tag)"
            elif is_shelfmark_line(clean):
                cat = "سطر نسخه خطی / کتابخانه (Shelfmark)"
            elif any(kw in clean for kw in ['مجموعه‌ای است', 'رساله‌ای است', 'کتابی است', 'این نسخه']):
                cat = "متن توضیحات / نثر (Narrative Description)"

            # Proposed fix: strip '●' from the line
            if clean.startswith('●'):
                proposed = re.sub(r'^●\s*', '', clean)
            else:
                proposed = re.sub(r'\s*●\s*', ' ', clean)

            candidates.append({
                'vol': vol,
                'line_num': l_idx,
                'category': cat,
                'current': clean,
                'proposed': proposed,
                'prev_line': prev_l,
                'next_line': next_l
            })

    report_path = 'reports/candidate_stray_bullets.md'
    os.makedirs('reports', exist_ok=True)
    with open(report_path, 'w', encoding='utf-8') as f:
        f.write("# گزارش جامع شناسایی و پاکسازی بالت‌های سرگردان (`●` غیر عنوان)\n\n")
        f.write(f"تعداد کل مدخل‌های اصلی معتبر (دارای `●`): **{main_entries_count:,}** مدخل\n\n")
        f.write(f"تعداد کل بالت‌های سرگردان نیازمند پالایش: **{len(candidates):,}** مورد\n\n")
        
        # Summary by category
        cat_counts = {}
        for c in candidates:
            cat_counts[c['category']] = cat_counts.get(c['category'], 0) + 1
            
        f.write("### 📊 آمار بالت‌های سرگردان به تفکیک دسته‌بندی:\n\n")
        for cat, count in sorted(cat_counts.items(), key=lambda x: x[1], reverse=True):
            f.write(f"- **{cat}:** {count:,} مورد\n")
        f.write("\n" + "="*60 + "\n\n")

        for idx, c in enumerate(candidates, 1):
            f.write(f"### شماره {idx} (جلد {c['vol']:02d} - سطر {c['line_num']} | دسته‌بندی: {c['category']})\n\n")
            f.write(f"🔴 **وضعیت فعلی:**\n```text\n{c['current']}\n```\n\n")
            f.write(f"🟢 **اصلاح پیشنهادی (حذف بالت):**\n```text\n{c['proposed']}\n```\n\n")
            if c['prev_line']:
                f.write(f"- سطر قبل: `{c['prev_line'][:100]}`\n")
            if c['next_line']:
                f.write(f"- سطر بعد: `{c['next_line'][:100]}`\n")
            f.write("\n---\n\n")

    print(f"Finished in {time.time() - start_t:.2f}s! Valid main entries: {main_entries_count}, Stray candidates: {len(candidates)} -> {report_path}")

if __name__ == '__main__':
    analyze_and_generate_report()
