import re
import unicodedata

AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'
FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹'

def to_en_digits(text):
    res = []
    for c in text:
        if c in AR_DIGITS:
            res.append(str(AR_DIGITS.index(c)))
        elif c in FA_DIGITS:
            res.append(str(FA_DIGITS.index(c)))
        else:
            res.append(c)
    return ''.join(res)

def clean_catalogue_brackets(text):
    # Fix catalogue bracket patterns common in PDF RTL streams:
    # 1. Reverse patterns like: ] 1 - 46 [ or ]1-46[
    text = re.sub(r'\]\s*([0-9\-\s/]+)\s*\[', r'[\1]', text)
    
    # 2. ] سم or : ف]سم
    text = re.sub(r'[:؛]?\s*مخ\s*ف\s*\]\s*سم\s*', ' سم [ف.مخ: ', text)
    text = re.sub(r'[:؛]?\s*ف\s*\]\s*سم\s*', ' سم [ف: ', text)
    text = re.sub(r'\]\s*سم\s*', ' سم [', text)
    text = re.sub(r'مخ\s*ف\s*سم\s*\[\s*:\s*', 'سم [ف.مخ: ', text)
    text = re.sub(r'ف\s*سم\s*\[\s*:\s*', 'سم [ف: ', text)
    text = re.sub(r'ف\s*سم\s*\[\s*', 'سم [ف: ', text)
    
    # 3. Tags like ]رایانه[ or [رایانه[ or ]رایانه]
    text = re.sub(r'[\[\]]\s*(رایانه|عکسی|نشریه[^\]\[]*|الفبائی[^\]\[]*|تراثنا[^\]\[]*)\s*[\[\]]', r'[\1]', text)
    
    # 4. Standardize [ف: ... ]
    text = re.sub(r'\[\s*ف\s*[:؛]\s*', '[ف: ', text)
    text = re.sub(r'\[\s*ف\.مخ\s*[:؛]\s*', '[ف.مخ: ', text)
    
    # 5. Fix double opening [ or double closing ]
    text = re.sub(r'\[\s*([0-9]+(?:\s*-\s*[0-9]+)?)\s*\[', r'[\1]', text)
    text = re.sub(r'\]\s*([0-9]+(?:\s*-\s*[0-9]+)?)\s*\]', r'[\1]', text)
    
    # 6. Normalize dashes in [ف: 1-46]
    text = re.sub(r'\[ف:\s*([0-9]+)\s*-\s*([0-9]+)\s*\]', r'[ف: \1-\2]', text)
    
    return text

def fix_dimensions_and_multiplication(text):
    # Convert * to × between numbers/dimensions
    text = re.sub(r'(\d+(?:/\d+)?)\s*\*\s*(\d+(?:/\d+)?)', r'\1×\2', text)
    text = re.sub(r'(\d+)\s*\*\s*(\d+)', r'\1×\2', text)
    return text

def standardize_typography(text):
    text = unicodedata.normalize('NFKC', text)
    text = text.replace('ي', 'ی').replace('ك', 'ک').replace('•', '●')
    
    # 1. English digits
    text = to_en_digits(text)
    
    # 2. Multiplication sign
    text = fix_dimensions_and_multiplication(text)
    
    # 3. Catalogue brackets
    text = clean_catalogue_brackets(text)
    
    # 4. Fix standard spacing around colons and semicolons
    text = re.sub(r'[ \t]*([:؛])[ \t]*', r'\1 ', text)
    text = re.sub(r'؛[ \t]*\n', '؛\n', text)
    
    # 5. Spacing around parentheses
    text = re.sub(r'\(\s*', '(', text)
    text = re.sub(r'\s*\)', ')', text)
    
    # 6. Fix shelfmark formatting at start of lines: "123 . " -> "123. "
    text = re.sub(r'(?:^|\n)\s*(\d+)\s*[\.\-\)]\s*', r'\n\n\1. ', text)
    
    # 7. Normalize whitespace
    text = re.sub(r'[ \t]+', ' ', text)
    text = re.sub(r' \n', '\n', text)
    text = re.sub(r'\n{3,}', '\n\n', text)
    
    return text.strip()
