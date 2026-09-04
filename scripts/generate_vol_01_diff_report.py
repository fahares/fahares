import sys, re
sys.path.append('.')
from scripts.batch_3_definitions import PAGE_RECONSTRUCTIONS

with open('sources/text/fahares_vol_01.txt', 'r', encoding='utf-8') as f:
    full_text = f.read()

diff_entries = []

for current_page in sorted(PAGE_RECONSTRUCTIONS.keys()):
    pat = rf'<!--\s*page:\s*{current_page}\s*-->([\s\S]*?)(?:<!--\s*page:|$)'
    m = re.search(pat, full_text)
    orig_page_text = m.group(1).strip() if m else ""
    reconstructed_page_text = PAGE_RECONSTRUCTIONS[current_page].strip()
    
    m_start = re.search(rf'<!--\s*page:\s*{current_page}\s*-->', full_text)
    start_line = full_text[:m_start.start()].count('\n') + 1 if m_start else 1
    end_pos = m_start.end() + len(m.group(1)) if m_start and m else len(full_text)
    end_line = full_text[:end_pos].count('\n') + 1

    diff_entries.append({
        'type': 'PAGE_REBUILD',
        'page': current_page,
        'start_line': start_line,
        'end_line': end_line,
        'original': orig_page_text,
        'reconstructed': reconstructed_page_text
    })

print(f"Generated diff entries for {len(diff_entries)} pages in Batch 3.")

report_path = 'reports/vol_01_reconstruction_diff.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش پیش‌نمایش بازسازی صفحات درهم‌ریخته جلد ۱ - بسته ۳ (۲۰ صفحه)\n\n")
    f.write(f"این گزارش شامل پیش‌نمایش بازسازی کامل ۲۰ صفحه سوم ({', '.join(str(p) for p in sorted(PAGE_RECONSTRUCTIONS.keys()))}) از لیست صفحات دارای تداخل ستونی در جلد ۱ است.\n\n")
    f.write("تمامی این صفحات از روی ساختار هندسی و لایه متنی فایل PDF (`fankha-full.pdf`) استخراج شده و با حفظ کامل نگارش استاندارد، جفت‌سازی دقیق شماره نسخه‌ها با اطلاعات نسخه‌شناسی انجام پذیرفته است.\n\n")
    f.write("---\n\n")
    
    for idx, entry in enumerate(diff_entries):
        p_num = entry['page']
        f.write(f"### صفحه {p_num} [بازسازی کامل صفحه بر اساس PDF] (سطور {entry['start_line']} تا {entry['end_line']})\n\n")
        f.write("🔴 **وضعیت فعلی در فایل متنی (درهم‌ریخته، دارای افتادگی، جابه‌جایی یا عناوین مفقود):**\n")
        f.write("```text\n")
        f.write(entry['original'] + "\n")
        f.write("```\n\n")
        f.write("🟢 **بازتولید کامل، استاندارد و پیوسته پیشنهادی (بر اساس هندسه دقیق لایه متنی PDF):**\n")
        f.write("```text\n")
        f.write(entry['reconstructed'] + "\n")
        f.write("```\n\n")
        f.write("\n---\n\n")

print(f"Done! Report saved to {report_path}")
