import json
import os
import glob
import re
import html
import sys
import time

# Ensure UTF-8 output encoding for print statements
if sys.stdout and hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# Input / Output paths
INPUT_DIR = 'json'
OUTPUT_DIR = 'output_text'
os.makedirs(OUTPUT_DIR, exist_ok=True)

def clean_html(html_str):
    if not html_str:
        return ''
    # Remove image descriptions and images
    html_str = re.sub(r'<div class="img-description"[^>]*>.*?</div>', '', html_str, flags=re.DOTALL)
    html_str = re.sub(r'<img[^>]*>', '', html_str)
    
    # Convert block tags and line breaks to newlines
    html_str = re.sub(r'<br\s*/?>', '\n', html_str)
    html_str = re.sub(r'</(p|h1|h2|h3|div|li)>', '\n', html_str)
    
    # Remove all remaining XML/HTML tags
    text = re.sub(r'<[^>]+>', '', html_str)
    
    # Decode HTML entities (&amp;, &nbsp;, etc.)
    text = html.unescape(text)
    
    # Clean lines
    lines = [line.strip() for line in text.splitlines()]
    
    # Preserve single blank lines between paragraphs, remove duplicate empty lines
    cleaned_lines = []
    prev_empty = True  # start true to avoid leading empty lines
    for l in lines:
        if not l:
            if not prev_empty:
                cleaned_lines.append('')
                prev_empty = True
        else:
            cleaned_lines.append(l)
            prev_empty = False
            
    return '\n'.join(cleaned_lines)

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    children = data.get('children', [])
    page_texts = []
    
    for child in children:
        raw_html = child.get('html', '')
        text = clean_html(raw_html)
        if text:
            page_texts.append(text)
            
    return '\n\n'.join(page_texts)

def main():
    start_time = time.time()
    
    # Sort files numerically: 1.json, 2.json, ... 68.json
    pattern = os.path.join(INPUT_DIR, '*.json')
    files = sorted(glob.glob(pattern), key=lambda x: int(os.path.basename(x).split('.')[0]))
    
    total_files = len(files)
    print(f"Starting extraction of {total_files} JSON files...")
    
    full_catalog_txt_path = os.path.join(OUTPUT_DIR, 'full_catalog.txt')
    
    total_lines = 0
    total_chars = 0
    
    with open(full_catalog_txt_path, 'w', encoding='utf-8') as full_out:
        for idx, fpath in enumerate(files, 1):
            fname = os.path.basename(fpath)
            num = fname.split('.')[0]
            txt_filename = f"{num}.txt"
            txt_filepath = os.path.join(OUTPUT_DIR, txt_filename)
            
            # Process single JSON file
            cleaned_text = process_file(fpath)
            
            # Save individual TXT file
            with open(txt_filepath, 'w', encoding='utf-8') as out:
                out.write(cleaned_text)
            
            # Append to master consolidated catalog TXT
            full_out.write(f"--- FILE: {fname} ---\n\n")
            full_out.write(cleaned_text)
            full_out.write("\n\n")
            
            lines_count = len(cleaned_text.splitlines())
            chars_count = len(cleaned_text)
            total_lines += lines_count
            total_chars += chars_count
            
            print(f"[{idx:02d}/{total_files:02d}] Processed {fname:8s} -> {txt_filename:8s} ({lines_count:,} lines, {chars_count:,} chars)")
            
    elapsed = time.time() - start_time
    print(f"\nExtraction Completed Successfully!")
    print(f"Total Time: {elapsed:.2f} seconds")
    print(f"Total Processed Lines: {total_lines:,}")
    print(f"Total Processed Characters: {total_chars:,}")
    print(f"Master file saved at: {full_catalog_txt_path}")

if __name__ == '__main__':
    main()
