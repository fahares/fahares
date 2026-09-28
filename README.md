# فهارس (FAHARES)

> **سامانه و موتور کاوش جامع نسخه‌های خطی ایران (بر پایه ۳۴ جلد فنخا، به کوشش مصطفی درایتی)**

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php)](https://php.net)
[![Meilisearch](https://img.shields.io/badge/Meilisearch-1.6-FF45A0?style=flat-square&logo=meilisearch)](https://meilisearch.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-Latest-003545?style=flat-square&logo=mariadb)](https://mariadb.org)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat-square&logo=docker)](https://docker.com)
[![Corpus](https://img.shields.io/badge/Data-fahares--corpus-blue?style=flat-square)](https://github.com/fahares/fahares-corpus)

---

## 📖 درباره پروژه

پروژه **فهارس**، سامانه جامع کتاب‌شناختی و موتور کاوش متنی پیشرفته برای **فهرستگان نسخه‌های خطی ایران (فنخا)** است. این مجموعه عظیم که در **۳۴ مجلد** به کوشش استاد **مصطفی درایتی** تدوین شده است، جامع‌ترین مرجع شناسایی میراث مکتوب و نسخه‌های خطی کهن در سراسر ایران و جهان به شمار می‌رود.

این مخزن شامل هسته نرم‌افزاری، ساختار دیتابیس رابطه‌ای، پایپ‌لاین نمایه‌سازی و موتور جستجوی بلادرنگ تحت وب است. داده‌های متنی و خط تولید استخراج OCR در مخزن همکار [fahares/fahares-corpus](https://github.com/fahares/fahares-corpus) نگهداری می‌شوند.

---

## 📊 مشخصات و آمارهای دیتابیس

| ردیف | شاخص | تعداد رکورد | توضیحات |
| :---: | :--- | :---: | :--- |
| ۱ | **مجلدات پردازش‌شده** | **۳۴ جلد** | پوشش ۱۰۰٪ تمام مجلدات فنخا |
| ۲ | **عناوین آثار (Works)** | **۷۱,۵۵۰** | همراه با آوانگاری لاتین، موضوعات و زبان‌ها |
| ۳ | **نسخه‌های خطی (Manuscripts)** | **۳۲۳,۸۷۴** | با تمام مؤلفه‌های نسخه‌شناسی، آغاز/انجام و کاتبان |
| ۴ | **ارجاعات کتاب‌شناختی (Referrals)** | **۱۴,۸۰۵** | ارجاعات متقاطع برای پیوند نام‌ها و آثار |
| ۵ | **اشخاص و اعلام (People)** | **۷۴,۲۱۳** | مؤلفان، کاتبان، مترجمان و واقفان با قرن هجری |
| ۶ | **کتابخانه‌ها و مراکز اسناد (Libraries)** | **۱,۰۴۸** | کتابخانه‌ها و مجموعه‌های خصوصی بر حسب شهر و کشور |
| ۷ | **پیوندهای چندخطی نسخ (Scripts)** | **۲۹۲,۷۶۸** | ثبت دقیق انواع اقلام خط (نستعلیق، نسخ، شکسته و...) |
| ۸ | **اسناد جستجوی زنده (Meilisearch)** | **۴۶۹,۶۳۷** | جستجوی بلادرنگ ترکیبی با پاسخ‌دهی زیر ۵ میلی‌ثانیه |

---

## 🛠️ استک و معماری فنی

- **بک‌اند:** PHP 8.3 با فریم‌ورک Laravel 13
- **دیتابیس رابطه‌ای:** MariaDB با ۱۰ مایگریشن اختصاصی و طراحی کاملاً نرمال‌شده
- **موتور کاوش سریع:** Meilisearch متصل از طریق Laravel Scout (پشتیبانی از خطایابی تایپی و رسم‌الخط عربی/فارسی)
- **فرانت‌اند:** Blade و Tailwind CSS با پشتیبانی بومی راست‌به‌چپ (RTL)
- **محیط اجرا:** Docker و Docker Compose ایزوله برای محیط‌های توسعه و سرور

---

## 🚀 راهنمای راه‌اندازی سریع (Quick Start)

### پیش‌نیازها
- نصب [Docker](https://docs.docker.com/get-docker/) و Docker Compose
- نصب `git` و `curl`

### ۱. کلون مخزن
```bash
git clone https://github.com/fahares/fahares.git
cd fahares
```

### ۲. پیکربندی متغیرهای محیطی
```bash
cp .env.example .env
```

### ۳. راه‌اندازی کانتینرهای داکر
```bash
docker compose up -d --build
```

### ۴. نصب وابستگی‌های PHP
```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
```

### ۵. دانلود داده‌های ۳۴ جلد (از مخزن Releases)
```bash
./scripts/download_data.sh
```

### ۶. اجرای مایگریشن‌ها و تزریق داده‌ها به MariaDB
```bash
docker compose exec app php artisan fahares:seed --fresh
```

### ۷. همگام‌سازی ایندکس‌های Meilisearch
```bash
docker compose exec app php artisan scout:sync-index-settings
docker compose exec app php artisan scout:import "App\Models\Work"
docker compose exec app php artisan scout:import "App\Models\Person"
docker compose exec app php artisan scout:import "App\Models\Manuscript"
```

سامانه روی آدرس `http://localhost:8000` (یا پورت تنظیم‌شده) آماده بهره‌برداری است.

---

## 📂 ساختار مخزن

```text
fahares/
├── app/
│   ├── Console/Commands/SeedFaharesDataCommand.php # لودر دسته‌ای با راندمان بالا
│   ├── Models/                                     # مدل‌های رابطه‌ای Eloquent
├── config/
│   ├── scout.php                                   # تنظیمات ایندکس‌ها و فیلترهای Meilisearch
│   └── database.php
├── database/
│   ├── migrations/                                 # اسکیما و جداول دیتابیس
├── docs/
│   └── PROCESS_DOCUMENTATION.md                   # گزارش فنی و مراحل پیشرفت پروژه
├── scripts/
│   ├── download_data.sh                            # اسکریپت دانلود مستقیم ریلیز رسمی ۳۴ جلد
│   └── sync_corpus.sh                             # همگام‌ساز محلی پیکره متنی
├── docker-compose.yml
└── Dockerfile
```

---

## 📜 مجوز (License)

پروژه فهارس به صورت متن‌باز منتشر شده و تحت مجوز [MIT License](LICENSE) قرار دارد.
داده‌های کتاب‌شناختی بر پایه اثر مرجع فنخا، به کوشش استاد مصطفی درایتی تهیه شده است.
