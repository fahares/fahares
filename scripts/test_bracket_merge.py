import re

def fix_split_brackets(text):
    # Pattern 1: [ف]:\n16-326] -> [ف: 16-326]
    # Pattern 2: [ف:\n16-326] -> [ف: 16-326]
    # Pattern 3: [\n16-326]   -> [16-326]
    
    t = text
    
    # 1. Merge [ف]: \n 16-326] => [ف: 16-326]
    t = re.sub(r'\[ف\]\s*:\s*\n\s*(\d+[\d\-–\s]*\])', r'[ف: \1', t)
    t = re.sub(r'\[ف\s*:\s*\n\s*(\d+[\d\-–\s]*\])', r'[ف: \1', t)
    
    # 2. General split bracket merge: line ending with [ followed by line starting with number and ]
    t = re.sub(r'\[\s*\n\s*([^\n\]]+?\])', r'[\1', t)
    
    return t

sample_text = """مطلبی راجع به عقد ازدواج؛ 6گ (43-48پ)، 13 سطر [ف]:
16-326]"""

print("=== قبل از اصلاح ===")
print(sample_text)
print("\n=== بعد از اصلاح ===")
print(fix_split_brackets(sample_text))

