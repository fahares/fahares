import os
import re
import glob

def clean_persian_text(text):
    if not text: return text
    t = text
    # Fix reversed BiDi brackets
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

    # Convert page headers globally inline so they don't break multi-line brackets or paragraphs
    content = re.sub(
        r'\n?\s*(\d+)\s+فهرستگان\s+نسخه\s*های\s+خطی\s+ایران[^\n\/]*?\/\s*جلد\s*\d+\s*',
        r' <!-- page: \1 --> ',
        content
    )

    # Split entries on bullet (●), main category headers, or cross-reference markers (➤, –)
    raw_entries = re.split(r'\n(?=●|\n[آ-ی\s\?]+?\s*/\s*(?:عربی|فارسی|ترکی)|\s*[➤–])', content)
    
    parsed_blocks = []
    for raw in raw_entries:
        raw_str = raw.strip()
        if not raw_str: continue
        
        lines = [l.strip() for l in raw_str.split('\n') if l.strip()]
        if not lines: continue
        
        first_line = lines[0].lstrip('●').strip()
        
        # If this entry is a cross-reference line (starts with ➤ or –)
        if first_line.startswith('➤') or first_line.startswith('–'):
            ref_block = "\n".join([clean_persian_text(l) for l in lines])
            parsed_blocks.append(ref_block)
            continue

        block_lines = []
        block_lines.append(f"### {first_line}")
        
        for l in lines[1:]:
            clean_l = clean_persian_text(l)
            
            if re.match(r'^\d+\.\s*', clean_l):
                block_lines.append(f"\n{clean_l}")
            elif clean_l.startswith('➤') or clean_l.startswith('–'):
                block_lines.append(f"\n{clean_l}")
            else:
                block_lines.append(clean_l)
                
        parsed_blocks.append("\n".join(block_lines))
        
    return parsed_blocks

def main():
    print("در حال تبدیل و جداسازی متون منبع به ۳۴ جلد کامل در sources/md/ ...")
    
    txt_files = sorted(glob.glob('sources/text/*.txt'))
    os.makedirs('sources/md', exist_ok=True)
    
    # Collect all blocks across all 68 files
    all_blocks = []
    for tf in txt_files:
        if 'full' in tf: continue
        blocks = parse_output_text_file(tf)
        all_blocks.extend(blocks)
        
    print(f"✅ مجموع کل مدخل‌های استخراج‌شده در کل فایل‌ها: {len(all_blocks)}")
    
    # Partition all_blocks into 34 volumes cleanly
    total_vols = 34
    blocks_per_vol = (len(all_blocks) + total_vols - 1) // total_vols
    
    for v in range(1, total_vols + 1):
        start_idx = (v - 1) * blocks_per_vol
        end_idx = min(len(all_blocks), v * blocks_per_vol)
        vol_blocks = all_blocks[start_idx:end_idx]
        
        out_path = f'sources/md/fahares_vol_{v:02d}.md'
        vol_lines = [f'# فهرستگان نسخه‌های خطی ایران (فنخا) - جلد {v:02d}\n']
        for b in vol_blocks:
            vol_lines.append(f'{b}\n')
            
        with open(out_path, 'w', encoding='utf-8') as f:
            f.write('\n'.join(vol_lines))
            
        print(f"  - ساخت جلد {v:02d}: {out_path} ({len(vol_blocks)} مدخل)")
        
    print("🎉 تمامی ۳۴ جلد فنخا به صورت تمیز و یکپارچه در sources/md/ ساخته شدند.")

if __name__ == '__main__':
    main()
