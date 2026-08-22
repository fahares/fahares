import os
import glob
import re

def merge_broken_brackets(text):
    t = text
    
    # Pattern A: Line 1 ends with [ف]: or [ف: or [فیلم‌ها - ف: and Line 2 starts with numbers and ends with ]
    t = re.sub(r'\[\s*ف\s*\]\s*:\s*\n\s*(\d+[\d\-–\s]*\])', r'[ف: \1', t)
    t = re.sub(r'\[\s*ف\s*:\s*\n\s*(\d+[\d\-–\s]*\])', r'[ف: \1', t)
    t = re.sub(r'\[([^\]\n]+?-\s*ف\s*:\s*)\n\s*(\d+[\d\-–\s]*\])', r'[\1 \2', t)
    
    # Pattern B: Any line ending with [ followed immediately by line starting with number and ]
    t = re.sub(r'\[\s*\n\s*([^\n\]]+?\])', r'[\1', t)
    
    return t

def main():
    txt_files = sorted(glob.glob('sources/text/*.txt'))
    print("در حال ادغام ۲۸۵۲ مورد کروشه چندخطی و شکستگی سطرها...")
    
    fixed_count = 0
    for fpath in txt_files:
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            
        merged = merge_broken_brackets(content)
        if merged != content:
            fixed_count += 1
            with open(fpath, 'w', encoding='utf-8') as f:
                f.write(merged)
                
    print(f"✅ ادغام کروشه‌های شکسته روی {fixed_count} فایل انجام شد.")

if __name__ == '__main__':
    main()
