import os
import glob
import re

# Comprehensive OCR anomaly repair mapping based on Fankha web OCR patterns
OCR_CORRECTIONS = {
    # Dedicated bibliographical terms standardizations
    r'\bاهدایا\s+به:': 'اهدا به:',
    r'\bاهداء\s+به:': 'اهدا به:',
    r'\bالذا\s+ت\b': 'الذات',

    # Common mixed Latin-Persian / Arabic typos
    r'\bفوqe\b': 'فوق',
    r'\bزerkوب\b': 'زرکوب',
    r'\bاوfer\b': 'اوفر',
    r'\bالاستمache\b': 'الاستماحة',
    r'\bپرمelon\b': 'یرملون',
    r'\bمthane\b': 'مثانه',
    r'\bاسترabad\b': 'استرآباد',
    r'\bاسترabadی\b': 'استرآبادی',
    r'\bاسترabadی،\b': 'استرآبادی،',
    r'\bكerman\b': 'کرمان',
    r'\bالخنafi\b': 'الخناقی',
    r'\bدیدan\b': 'دیدن',
    r'\bحiale\b': 'حواله',
    r'\bاغلوqn\b': 'اقلوقن',
    r'\bنisan\b': 'نیسان',
    r'\bنisan؛\b': 'نیسان؛',
    r'\bعman\b': 'عمان',
    r'\bمسبوqa\b': 'مسبوقاً',
    r'\bدram\b': 'درام',
    r'\bرmse\b': 'رمثه',
    r'\bدبوqa\b': 'دبوقا',
    r'\bدبوqa،\b': 'دبوقا،',
    r'\bنafe\b': 'نافع',
    r'\bخمami\b': 'خمامی',
    r'\bزorst\b': 'زرتشت',
    r'\bغلmani\b': 'غلمانی',
    r'\bهشتروddy\b': 'هشترودی',
    r'\bالعزr\b': 'العزر',
    r'\bسahوان\b': 'سهوان',
    r'\bسahوان؛\b': 'سهوان؛',
    r'\bدرصحاft\b': 'در صحافت',
    r'\bقراfi\b': 'قرافی',
    r'\bقراfi،\b': 'قرافی،',
    r'\bلابلis\b': 'ابلیس',
    r'\bxیا\b': 'خیابان',
    r'\bلاودlavd\b': 'لاود',
    r'\bوiser\b': 'وزیر',
    r'\bزDMAوی\b': 'دماوی',
    r'\bزeydī\b': 'زیدی',
    r'\bهdana\b': 'هدانا',
    r'\borkی\b': 'ترکی',
    r'\bحala\b': 'حالا',
    r'\bدwانی\b': 'دوانی',
    r'\bشeyx\b': 'شیخ',
    r'\bافسستا\b': 'افست',
    r'\bسشش\b': 'ششم',
    r'\bمواظظ\b': 'مواظبت',
    r'\bمششی\b': 'منشی'
}

def repair_text(text):
    if not text: return text
    t = text
    for pat, rep in OCR_CORRECTIONS.items():
        t = re.sub(pat, rep, t)
    return t

def repair_transliteration_with_fa_context(title_fa, title_lat):
    """
    Cross-validates Latin Romanization against corresponding Persian/Arabic Title.
    Fixes OCR misrecognition of 'ذ' (ḏ) misread as 'ḡ' when 'ذ' is in Persian title.
    Enforces assimilation of solar letter 'ذ' (aḏ-ḏāt).
    """
    if not title_fa or not title_lat: return title_lat
    lat = title_lat
    
    # Check if Persian/Arabic title contains 'ذ' (Dhal)
    if 'ذ' in title_fa:
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ad-ḡāt(?![a-zA-Z\u0100-\u024F])', 'aḏ-ḏāt', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ad-ḏāt(?![a-zA-Z\u0100-\u024F])', 'aḏ-ḏāt', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])al-ḡāt(?![a-zA-Z\u0100-\u024F])', 'aḏ-ḏāt', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡāt(?![a-zA-Z\u0100-\u024F])', 'ḏāt', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ruṣḡ(?![a-zA-Z\u0100-\u024F])', 'ruṣāḏ', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡaxīra(?![a-zA-Z\u0100-\u024F])', 'ḏaxīra', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡarī‘a(?![a-zA-Z\u0100-\u024F])', 'ḏarī‘a', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡikr(?![a-zA-Z\u0100-\u024F])', 'ḏikr', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡihn(?![a-zA-Z\u0100-\u024F])', 'ḏihn', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])ḡahab(?![a-zA-Z\u0100-\u024F])', 'ḏahab', lat)

    # Check if Persian/Arabic title contains 'احد' (Aḥad / Aḥadiyya)
    if 'احد' in title_fa:
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])aḡadiyya(?![a-zA-Z\u0100-\u024F])', 'aḥadiyya', lat)
        lat = re.sub(r'(?<![a-zA-Z\u0100-\u024F])aḡad(?![a-zA-Z\u0100-\u024F])', 'aḥad', lat)
        
    return lat

def main():
    txt_files = sorted(glob.glob('sources/text/*.txt'))
    print(f"در حال اعمال اصلاحات هوشمند OCR روی {len(txt_files)} فایل متنی...")
    
    total_replaced = 0
    for fpath in txt_files:
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            
        repaired = repair_text(content)
        if repaired != content:
            with open(fpath, 'w', encoding='utf-8') as f:
                f.write(repaired)
            total_replaced += 1
            
    print(f"✅ اصلاحات هوشمند OCR روی {total_replaced} فایل با موفقیت اعمال شد.")

if __name__ == '__main__':
    main()

