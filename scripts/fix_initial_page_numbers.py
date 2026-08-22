#!/usr/bin/env python3
"""
Fix the initial page number for each of the 34 volumes.
Since the first page of each volume does not contain a running header,
its page number is deduced accurately from the second page number (first_page = second_page - 1).
Also regenerates clean_text/fankha-full.txt and sources/text/fankha-full.txt.
"""

import os
import re

def fix_initial_pages():
    full_text_list = []
    
    for vol in range(1, 35):
        vol_clean_path = f"clean_text/fahares_vol_{vol:02d}.txt"
        vol_sources_path = f"sources/text/fahares_vol_{vol:02d}.txt"
        
        if not os.path.exists(vol_clean_path):
            print(f"❌ File not found: {vol_clean_path}")
            continue
            
        with open(vol_clean_path, 'r', encoding='utf-8') as f:
            content = f.read()
            
        matches = list(re.finditer(r'<!-- page:\s*(\d+)\s*-->', content))
        if len(matches) >= 2:
            m1 = matches[0]
            m2 = matches[1]
            first_num = int(m1.group(1))
            second_num = int(m2.group(1))
            correct_first = second_num - 1
            
            if first_num != correct_first:
                # Replace only the first occurrence
                start, end = m1.span()
                new_tag = f"<!-- page: {correct_first} -->"
                content = content[:start] + new_tag + content[end:]
                print(f"✅ Vol {vol:02d}: Corrected first page from {first_num} to {correct_first} (second page is {second_num})")
            else:
                print(f"✔️ Vol {vol:02d}: First page already {first_num} (second page is {second_num})")
        else:
            print(f"⚠️ Vol {vol:02d}: Less than 2 page tags found!")

        with open(vol_clean_path, 'w', encoding='utf-8') as f:
            f.write(content)
        with open(vol_sources_path, 'w', encoding='utf-8') as f:
            f.write(content)
            
        full_text_list.append(content)

    full_clean_path = "clean_text/fankha-full.txt"
    full_sources_path = "sources/text/fankha-full.txt"
    combined = "\n\n".join(full_text_list)
    
    with open(full_clean_path, 'w', encoding='utf-8') as f:
        f.write(combined)
    with open(full_sources_path, 'w', encoding='utf-8') as f:
        f.write(combined)
        
    print(f"\n🎉 Successfully updated all 34 volumes and regenerated {full_clean_path} ({len(combined):,} chars)!")

if __name__ == "__main__":
    fix_initial_pages()
