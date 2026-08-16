import os
import re
import glob

def find_matching_raw_entry(library_name, copy_num, raw_full_text):
    # Search for library name and copy number in raw text
    # e.g., "رضوی" and "26675"
    pattern = re.escape(copy_num)
    matches = [m.start() for m in re.finditer(pattern, raw_full_text)]
    
    best_chunk = None
    for pos in matches:
        start = max(0, pos - 300)
        end = min(len(raw_full_text), pos + 300)
        snippet = raw_full_text[start:end]
        if library_name in snippet:
            best_chunk = snippet
            break
            
    return best_chunk

def main():
    print("=== مقایسه نمونه‌ای میان repaired_md و output_text ===")
    
    # Read repaired entry
    with open('repaired_md/fankha_vol_33.md', 'r', encoding='utf-8') as f:
        md_text = f.read()

    # Read raw full catalog text
    raw_path = 'output_text/fankha-full.txt'
    if not os.path.exists(raw_path):
        # combine output_text/*.txt
        print("در حال خواندن فایل‌های output_text...")
        txt_files = sorted(glob.glob('output_text/*.txt'))
        chunks = []
        for tf in txt_files:
            if 'full' in tf: continue
            with open(tf, 'r', encoding='utf-8') as f:
                chunks.append(f.read())
        raw_full_text = "\n".join(chunks)
    else:
        with open(raw_path, 'r', encoding='utf-8') as f:
            raw_full_text = f.read()

    # Sample 1: نان و حلوا - رضوی 26675
    sample1_raw = find_matching_raw_entry('رضوی', '26675', raw_full_text)
    
    print("\n--------------------------------------------------")
    print("📌 نمونه ۱: مدخل «نان و حلوا» نسخه 26675 رضوی مشهد")
    print("--------------------------------------------------")
    print("🔹 متن در repaired_md:")
    print("""- **کتابخانه:** مشهد؛ رضوی
**شماره نسخه:** 26675
**آغاز:** بسمله. حمدله... هذه جملة من السوانح و نبذة من الفواتح سنحت لی فی بلدة""")
    print("\n🔹 متن خام قرینه‌شده در output_text:")
    if sample1_raw:
        print(sample1_raw.strip())
    else:
        print("یافت نشد")

if __name__ == '__main__':
    main()
