import os
import json
import re
import time

LIBRARY_CITIES = [
    'تهران؛', 'مشهد؛', 'قم؛', 'اصفهان؛', 'تبریز؛', 'شیراز؛', 'یزد؛', 'کرمان؛', 'رشت؛', 'ساری؛',
    'همدان؛', 'قزوین؛', 'کاشان؛', 'زنجان؛', 'ارومیه؛', 'سنندج؛', 'کرمانشاه؛', 'اهواز؛', 'خرم‌آباد؛',
    'گرگان؛', 'سمنان؛', 'اراک؛', 'اردبیل؛', 'بوشهر؛', 'بندرعباس؛', 'زاهدان؛', 'بیرجند؛', 'بجنورد؛',
    'لندن؛', 'پاریس؛', 'استانبول؛', 'قاهره؛', 'نجف؛', 'کربلا؛', 'بغداد؛', 'سامرا؛', 'کاظمین؛',
    'بیروت؛', 'دمشق؛', 'پیشاور؛', 'لاهور؛', 'دهلی؛', 'کلکته؛', 'علیگر؛', 'حیدرآباد؛', 'پتنه؛', 'رامپور؛',
    'کابل؛', 'هرات؛', 'مزار شریف؛', 'تاشکند؛', 'دوشنبه؛', 'سمرقند؛', 'بخارا؛', 'باکو؛', 'ایروان؛', 'تفلیس؛',
    'پترزبورگ؛', 'مسکو؛', 'برلین؛', 'مونیخ؛', 'لایپزیگ؛', 'وین؛', 'رم؛', 'واتیکان؛', 'مادرید؛', 'لیدن؛',
    'کمبریج؛', 'آکسفورد؛', 'منچستر؛', 'دوبلین؛', 'پرینستون؛', 'کلمبیا؛', 'هاروارد؛', 'شیکاگو؛', 'ییل؛'
]

def is_shelfmark_line(line):
    clean = line.strip()
    if not clean:
        return False
    # Numbered or Unnumbered shelfmark
    m_num = re.match(r'^(?:\d+[\.\-\)]|\.\s*\d+)?\s*(.*)$', clean)
    if not m_num:
        return False
    rest = m_num.group(1).strip()
    if any(rest.startswith(c) for c in LIBRARY_CITIES) or any(c in rest for c in ['شماره نسخه:', '؛ شماره نسخه:', 'ش:']):
        if not any(kw in rest for kw in ['خط:', 'کاغذ:', 'آغاز:', 'انجام:']) or len(rest.split()) < 12:
            return True
    return False

def split_shelfmark_and_desc(record_text):
    # Separate shelfmark header from description
    # Common transition markers in description
    m = re.search(r'(?:\s|^)(آغاز:|انجام:|خط:|کاغذ:|مؤلف:|کاتب:|چاپ:|افتادگی:|جلد:|قطع:|اندازه:|مصحح|محشی|مجدول|فاقد|وقف:|تملک:)', record_text)
    if m:
        idx = m.start()
        shelfmark = record_text[:idx].strip()
        desc = record_text[idx:].strip()
        return shelfmark, desc
    return record_text.strip(), ""

print("Test script loaded successfully")
