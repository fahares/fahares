import re
import sys

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

# Verify initial page count
init_pages = re.findall(r"<!--\s*page:\s*(\d+)\s*-->", text)
assert len(init_pages) == 1015, f"Expected 1015 pages initially, found {len(init_pages)}"

# 1. Page 327: Fix order of translator Rajā'i and 'وابسته به'
p327_old = """● احیاء العلوم (ترجمه) / اخلاق / فارسی
iḥyā'-ul 'ulūm (t.)

وابسته به: احیاء علوم الدین = احیاء العلوم غزالی، محمد بن محمد (450-505)

مترجم: رجائی، احمد علی
rajā'i, ahmad 'alī"""

p327_new = """● احیاء العلوم (ترجمه) / اخلاق / فارسی
iḥyā'-ul 'ulūm (t.)

مترجم: رجائی، احمد علی
rajā'i, ahmad 'alī

وابسته به: احیاء علوم الدین = احیاء العلوم؛ غزالی، محمد بن محمد (450-505)"""

assert p327_old in text, "Target p327_old not found!"
text = text.replace(p327_old, p327_new, 1)
print("Page 327 fix applied.")

# 2. Page 328: Standardize transliterations
p328_old = """● احیاء العلوم (مختصر) / اخلاق / عربی
ihyā'-ul 'ulūm (muxtaṣar)

عزالدین بن مکی
'ezz-od-dīn ebn-e makkī

وابسته به: احیاء علوم الدین = احیاء العلوم غزالی، محمد بن محمد (450-505)"""

p328_new = """● احیاء العلوم (مختصر) / اخلاق / عربی
iḥyā'-ul 'ulūm (muxtaṣar)

عزالدین بن مکی
‘ezz-od-dīn ebn-e makkī

وابسته به: احیاء علوم الدین = احیاء العلوم؛ غزالی، محمد بن محمد (450-505)"""

assert p328_old in text, "Target p328_old not found!"
text = text.replace(p328_old, p328_new, 1)

p328_old2 = """● احیاء العلوم (منتخب) / اخلاق / عربی
ihyā'-ul 'ulūm (mn.)"""

p328_new2 = """● احیاء العلوم (منتخب) / اخلاق / عربی
iḥyā'-ul 'ulūm (mn.)"""

assert p328_old2 in text, "Target p328_old2 not found!"
text = text.replace(p328_old2, p328_new2, 1)

p328_old3 = """● احیاء العلوم (منتخب) = مناسک حج / فقه / فارسی
ihyā'-ul 'ulūm (mn.) = manāsek-e hajj"""

p328_new3 = """● احیاء العلوم (منتخب) = مناسک حج / فقه / فارسی
iḥyā'-ul 'ulūm (mn.) = manāsek-e hajj"""

assert p328_old3 in text, "Target p328_old3 not found!"
text = text.replace(p328_old3, p328_new3, 1)
print("Page 328 fix applied.")

# 3. Page 342: Restore '● اخبار بعض الخلفاء و الوزراء العباسیة' and '● اخبار البلدان / جغرافیا / عربی'
p342_old = """<!-- page: 342 -->
تهران؛ مینوی؛ شماره نسخه: 283 بخش 3
نسخه اصل: بادلیان 270

poc.

کا: محمد بن الحاج الشاکر، تا: ذیحجه 886ق، جا: دیه ایمن دمشق نزدیک دیه فارا؛ افتادگی: آغاز؛ 10گ (105-115) [ف: 136]
axbār-ul-buldān

ابن فقیه، احمد بن محمد، - 365 قمری"""

p342_new = """<!-- page: 342 -->
● اخبار بعض الخلفاء و الوزراء العباسیة / تاریخ پادشاهان / عربی
axbār-u ba‘ḍ-il xulafā’ wa-l wuzarā’-il ‘abbāsīya

تهران؛ مینوی؛ شماره نسخه: 283 بخش 3
نسخه اصل: بادلیان poc. 270؛ کا: محمد بن الحاج الشاکر، تا: ذیحجه 886ق، جا: دیه ایمن دمشق نزدیک دیه فارا؛ افتادگی: آغاز؛ 10گ (105-115) [ف: 136]

● اخبار البلدان / جغرافیا / عربی
axbār-ul-buldān

ابن فقیه، احمد بن محمد، - 365 قمری"""

assert p342_old in text, "Target p342_old not found!"
text = text.replace(p342_old, p342_new, 1)
print("Page 342 fix applied.")

# 4. Page 344: Restore 3 dropped titles
p344_old1 = """<!-- page: 344 -->
حدیث / عربی
axbār-ul-ḥisān min aḥādīṭ-i nabīyy-i āḥar-uz-zamān"""

p344_new1 = """<!-- page: 344 -->
● الاخبار الحسان من احادیث نبی آخر الزمان / حدیث / عربی
axbār-ul-ḥisān min aḥādīṯ-i nabīyy-i āxar-uz-zamān"""

assert p344_old1 in text, "Target p344_old1 not found!"
text = text.replace(p344_old1, p344_new1, 1)

p344_old2 = """خط: نسخ، کاتب = مؤلف، بی‌تا؛ جلد: مقوایی عطف تیماج قهوه ای، 34گ، مختلف السطر، اندازه: 17×21سم [ف: 18-141]

axbār-e huseynīye dar axbār-e (tāriḫ-e) madīne"""

p344_new2 = """خط: نسخ، کاتب = مؤلف، بی‌تا؛ جلد: مقوایی عطف تیماج قهوه ای، 34گ، مختلف السطر، اندازه: 17×21سم [ف: 18-141]

● اخبار حسینیه در اخبار (تاریخ) مدینه / تاریخ / فارسی
axbār-e huseynīye dar axbār-e (tāriḫ-e) madīne"""

assert p344_old2 in text, "Target p344_old2 not found!"
text = text.replace(p344_old2, p344_new2, 1)

p344_old3 = """خط: نستعلیق، کا: فقیر محمدی نقش بندی عطاری، تا: 15 جمادی الاول 1130ق؛ مقابله شده در رجب 22 جمادی الاول 1130 [فلمها - ف: 3-109]

axbār-ul-ḥallāj"""

p344_new3 = """خط: نستعلیق، کا: فقیر محمدی نقش بندی عطاری، تا: 15 جمادی الاول 1130ق؛ مقابله شده در رجب 22 جمادی الاول 1130 [فلمها - ف: 3-109]

● اخبار الحلاج / عرفان و تصوف / عربی
axbār-ul-ḥallāj"""

assert p344_old3 in text, "Target p344_old3 not found!"
text = text.replace(p344_old3, p344_new3, 1)
print("Page 344 fix applied.")

# 5. Page 345: Restore 4 dropped titles
p345_old1 = """<!-- page: 345 -->
اخبار خارجه ← جنگ عثمانی و روس
axbār-e xāreje (dar sāl-e 1884)"""

p345_new1 = """<!-- page: 345 -->
اخبار خارجه ← جنگ عثمانی و روس

● اخبار خارجه (در سال 1884م) / فارسی
axbār-e xāreje (dar sāl-e 1884)"""

assert p345_old1 in text, "Target p345_old1 not found!"
text = text.replace(p345_old1, p345_new1, 1)

p345_old2 = """خط: نستعلیق، کا: = مؤلف، تا: 1301ق؛ کاغذ: آبی، جلد: تیماج ضربی، 13 سطر (26×14)، اندازه: 33×21سم [الفبائی ف: 28]

axbār darbāre-ye mohammad-e-bn-e hanafiye"""

p345_new2 = """خط: نستعلیق، کا: = مؤلف، تا: 1301ق؛ کاغذ: آبی، جلد: تیماج ضربی، 13 سطر (26×14)، اندازه: 33×21سم [الفبائی ف: 28]

● اخبار درباره محمد بن حنفیه / تراجم / فارسی
axbār darbāre-ye mohammad-e-bn-e hanafīye"""

assert p345_old2 in text, "Target p345_old2 not found!"
text = text.replace(p345_old2, p345_new2, 1)

p345_old3 = """21×14سم [ف: 5-1659]
axbār-ud duwal wa ātār-ul uwal"""

p345_new3 = """21×14سم [ف: 5-1659]

● اخبار الدول و آثار الاول / تاریخ / عربی
axbār-ud duwal wa āṯār-ul uwal"""

assert p345_old3 in text, "Target p345_old3 not found!"
text = text.replace(p345_old3, p345_new3, 1)

p345_old4 = """خشتی [ف: 300]

فارسی
axbār rāje‘ be ‘alī ‘alayh-es-salam"""

p345_new4 = """خشتی [ف: 300]

● اخبار راجع به علی علیه السلام / کلام و اعتقادات / فارسی
axbār rāje‘ be ‘alī ‘alayh-es-salam"""

assert p345_old4 in text, "Target p345_old4 not found!"
text = text.replace(p345_old4, p345_new4, 1)
print("Page 345 fix applied.")

# 6. Page 348: Restore 5 dropped titles + 1 broken heading
p348_old1 = """<!-- page: 348 -->
axbār-e rūznāme-hā-ye hend"""

p348_new1 = """<!-- page: 348 -->
● اخبار روزنامه‌های هند / روزنامه / فارسی
axbār-e rūznāme-hā-ye hend"""

assert p348_old1 in text, "Target p348_old1 not found!"
text = text.replace(p348_old1, p348_new1, 1)

p348_old2 = """خط: نستعلیق، کا: محمد قزوینی، بی‌تا، جا: طهران؛ کاغذ: فرنگی، جلد: کاغذی گل و بوته دار عطف تیماج قرمز مقوایی، 18 گ، 31 سطر (25×13)، اندازه: 21×32/5 سم [ف: 4 - 248]

axbār-e rūsiye"""

p348_new2 = """خط: نستعلیق، کا: محمد قزوینی، بی‌تا، جا: طهران؛ کاغذ: فرنگی، جلد: کاغذی گل و بوته دار عطف تیماج قرمز مقوایی، 18 گ، 31 سطر (25×13)، اندازه: 21×32/5 سم [ف: 4 - 248]

● اخبار روسیه / روزنامه / فارسی
axbār-e rūsīye"""

assert p348_old2 in text, "Target p348_old2 not found!"
text = text.replace(p348_old2, p348_new2, 1)

p348_old3 = """خط: نسخ، بی‌کا، تا: سده 13 و 14 ق؛ کاغذ: فرنگی، جلد: تازه، 54 گ، 13 سطر، قطع: رحلی [آستانه قم: 76 -]

al-axbār-uz-zakardānīyyāt"""

p348_new3 = """خط: نسخ، بی‌کا، تا: سده 13 و 14 ق؛ کاغذ: فرنگی، جلد: تازه، 54 گ، 13 سطر، قطع: رحلی [آستانه قم: 76 -]

● الاخبار الزکردانیات / حدیث / عربی
al-axbār-uz-zakardānīyyāt"""

assert p348_old3 in text, "Target p348_old3 not found!"
text = text.replace(p348_old3, p348_new3, 1)

p348_old4 = """اندازه: 15×21/5 سم [ف: 1 - 227]

axbār-uz-ziynabāt"""

p348_new4 = """اندازه: 15×21/5 سم [ف: 1 - 227]

● اخبار الزینبات / عربی
axbār-uz-ziynabāt"""

assert p348_old4 in text, "Target p348_old4 not found!"
text = text.replace(p348_old4, p348_new4, 1)

p348_old5 = """تبریز؛ قاضی طباطبائی؛ شماره نسخه: بدون شماره/ 13 بی‌کا، بی‌تا [نشریه: 7 - 521]

الملك من امر الجارية / تاریخ / عربی
axbār-us sab'at-il 'ibād = xabar-us sab'at-i 'abbād-in wa mā jarā ma'a-l mulk min amr-il jāriya"""

p348_new5 = """تبریز؛ قاضی طباطبائی؛ شماره نسخه: بدون شماره/ 13 بی‌کا، بی‌تا [نشریه: 7 - 521]

● اخبار السبعة العباد = خبر السبعة عباد و ما جری مع الملک من امر الجاریة / تاریخ / عربی
axbār-us sab‘at-il ‘ibād = xabar-us sab‘at-i ‘abbād-in wa mā jarā ma‘a-l mulk min amr-il jāriya"""

assert p348_old5 in text, "Target p348_old5 not found!"
text = text.replace(p348_old5, p348_new5, 1)

p348_old6 = """اندازه: 15×20 سم [ف: 32 - 446]

axbār-e šāhī ūde"""

p348_new6 = """اندازه: 15×20 سم [ف: 32 - 446]

● اخبار شاهی اوده / تاریخ / فارسی
axbār-e šāhī ūde"""

assert p348_old6 in text, "Target p348_old6 not found!"
text = text.replace(p348_old6, p348_new6, 1)
print("Page 348 fix applied.")

# Final page sequence assertion
final_pages = re.findall(r"<!--\s*page:\s*(\d+)\s*-->", text)
assert len(final_pages) == 1015, f"Expected 1015 pages after fixes, found {len(final_pages)}"
expected_sequence = [str(i) for i in range(7, 1022)]
assert final_pages == expected_sequence, "Page sequence mismatch!"

with open("sources/text/fahares_vol_02.txt", "w", encoding="utf-8") as f:
    f.write(text)

print("Batch 11 successfully applied to sources/text/fahares_vol_02.txt.")
