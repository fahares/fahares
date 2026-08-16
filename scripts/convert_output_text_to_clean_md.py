import os
import re
import glob

PERSIAN_ARABIC_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٥٦٧٨٩', '01234567890123456789')

def normalize_digits(text):
    if not text: return text
    return text.translate(PERSIAN_ARABIC_DIGITS)

def clean_translit_string(s):
    if not s: return s
    s = re.sub(r'([a-zA-Z])\s+([āīūšḥżṭ‘’])\s+([a-zA-Z])', r'\1\2\3', s)
    s = re.sub(r'([a-zA-Z])\s+([āīūšḥżṭ‘’])\b', r'\1\2', s)
    s = re.sub(r'\b([āīūšḥżṭ‘’])\s+([a-zA-Z])', r'\1\2', s)
    s = re.sub(r'([a-zA-Z])\s+([āīūšḥżṭ‘’])', r'\1\2', s)
    s = re.sub(r'([a-zA-Z])\s+([’\'\`\‘])', r'\1\2', s)
    s = re.sub(r'\s*-\s*', '-', s)
    return re.sub(r'\s+', ' ', s).strip()

def clean_persian_text(text):
    if not text: return text
    t = text
    # Fix reversed brackets
    t = re.sub(r'\]\s*رايانه\s*\[', '[رایانه]', t)
    t = re.sub(r'\]\s*رایانه\s*\[', '[رایانه]', t)
    t = re.sub(r'\]\s*([^\n\[\]]+?)\s*\[', r'[\1]', t)
    
    # Fix OCR typos & spacing
    t = re.sub(r'\bطرس\b', 'سطر', t)
    t = re.sub(r'\bب\s+ه\b', 'به', t)
    t = re.sub(r'\bد\s+ر\b', 'در', t)
    t = re.sub(r'\bق\s*،\s*رن\b', 'قرن', t)
    
    return t

def parse_output_text_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    raw_entries = re.split(r'\n(?=●|\n[آ-ی]+\s*/\s*(?:عربی|فارسی|ترکی))', content)
    
    parsed_blocks = []
    for raw in raw_entries:
        raw_str = raw.strip()
        if not raw_str: continue
        
        lines = [l.strip() for l in raw_str.split('\n') if l.strip()]
        if not lines: continue
        
        block_lines = []
        header = lines[0].lstrip('●').strip()
        block_lines.append(f"### {header}")
        
        for l in lines[1:]:
            clean_l = clean_persian_text(l)
            
            if re.match(r'^[a-zA-Z\s\-\'\;\`\’\‘\u02BF\(\)\d\/]+$', l) and len(l) > 2:
                tr_clean = clean_translit_string(l)
                if ',' in tr_clean or re.search(r'\(\d{4}', tr_clean):
                    block_lines.append(f"<!-- translit_author: {tr_clean} -->")
                else:
                    block_lines.append(f"<!-- translit_title: {tr_clean} -->")
            elif re.match(r'^\d+\.\s*', clean_l):
                block_lines.append(f"\n{clean_l}")
            else:
                block_lines.append(clean_l)
                
        parsed_blocks.append("\n".join(block_lines))
        
    return parsed_blocks

def main():
    print("در حال تبدیل مستقیم فایل‌های sources/text به Markdown تمیز در sources/md ...")
    os.makedirs('sources/md', exist_ok=True)
    
    txt_files = sorted(glob.glob('sources/text/*.txt'))
    vol30_blocks = []
    
    for tf in txt_files:
        if 'full' in tf: continue
        blocks = parse_output_text_file(tf)
        for b in blocks:
            if 'مطلع الانوار' in b or 'مخزن' in b or len(vol30_blocks) > 0:
                vol30_blocks.append(b)
                if len(vol30_blocks) >= 30:
                    break
        if len(vol30_blocks) >= 30:
            break

    test_chunk = []
    test_chunk.append('# قطعه آزمایشی بازسازی‌شده مستقیماً از sources/text (۳۰ مدخل)\n')
    for i, b in enumerate(vol30_blocks[:30], start=1):
        test_chunk.append(f'## مدخل {i}:\n{b}\n')

    os.makedirs('scratch', exist_ok=True)
    with open('scratch/test_chunk_vol30_30_entries.md', 'w', encoding='utf-8') as f:
        f.write('\n'.join(test_chunk))

    print("قطعه آزمایشی بازسازی‌شده در scratch/test_chunk_vol30_30_entries.md قرار گرفت و پوشه sources/md آماده شد.")

if __name__ == '__main__':
    main()
