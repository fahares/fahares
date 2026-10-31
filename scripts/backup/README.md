# سامانه‌ی پشتیبان‌گیری فهارس

پشتیبان‌گیری خودکار از پروداکشن، در دو مقصد:

| مقصد | چه چیزی | قالب | نگهداری |
|---|---|---|---|
| کانال خصوصی تلگرام | پایگاه داده (روزانه)، تنظیمات و اسرار (هفتگی) | تکه‌های ۱۹ مگابایتی، رمزنگاری‌شده با age | همیشگی (ربات نمی‌تواند پیام‌های قدیمی‌تر از ۴۸ ساعت را پاک کند) |
| Google Drive (restic) | پایگاه داده، تصاویر مآخذ، تنظیمات | مخزن رمزنگاری‌شده و بدون تکرار restic | ۱۴ روزانه، ۸ هفتگی، ۱۲ ماهانه |
| خود سرور (`/var/backups/fahares`) | سه dump آخر پایگاه داده | `.sql.zst` | سه نسخه |

Meilisearch پشتیبان‌گیری نمی‌شود و پس از بازیابی با `scout:import` از نو ساخته می‌شود.

## فایل‌ها

- `fahares-backup.sh`: زیرفرمان‌های `db`، `media`، `config`، `verify` و `status`
- `fahares-restore.sh`: بازیابی از فایل، تلگرام یا restic در یک پایگاه داده‌ی مقصد که صریحاً نام برده شود
- `backup.env.example`: نمونه‌ی تنظیمات (`/etc/fahares-backup/backup.env`)
- `crontab.example`: زمان‌بندی روی میزبان
- `hermes.sudoers`: اجازه‌ی محدود برای Hermes

## راه‌اندازی روی میزبان (یک‌بار)

این کارها با `ssh fahares-server` انجام می‌شود.

1. نصب ابزارها: `sudo apt install age restic rclone`، و قرار دادن اسکریپت‌ها در `/usr/local/bin`.
2. ساختن پوشه‌ها (`<backup-user>` در این راهنما و در `hermes.sudoers` کاربری از میزبان است که بکاپ را اجرا می‌کند):
   ```bash
   sudo install -d -o <backup-user> -m 700 /etc/fahares-backup /var/backups/fahares
   sudo install -d -o <backup-user> -m 755 /var/lib/fahares-backup
   ```
3. پر کردن `/etc/fahares-backup/backup.env` با مجوز 600.
4. ساختن رمز restic:
   ```bash
   head -c 32 /dev/urandom | base64 > /etc/fahares-backup/restic.pass
   ```
   یک نسخه از این رمز را آفلاین نزد خودتان نگه دارید.
5. `rclone config`: یک remote به نام `gdrive` از نوع Google Drive بسازید.
6. ساختن مخزن restic و اجرای دستی نخستین بکاپ:
   ```bash
   restic init
   fahares-backup.sh db
   ```
   متغیرهای restic را پیش از `restic init` از `backup.env` بارگذاری کنید.
7. `crontab -e` و افزودن خطوط `crontab.example`.
8. اجازه‌ی Hermes: نصب `hermes.sudoers` در `/etc/sudoers.d/`.

## Hermes

Hermes فقط سه فرمان را می‌تواند اجرا کند:

```bash
sudo -u <backup-user> /usr/local/bin/fahares-backup.sh status   # کد خروج ۱ = بکاپ کهنه یا ناموفق
sudo -u <backup-user> /usr/local/bin/fahares-backup.sh db       # بکاپ فوری
sudo -u <backup-user> /usr/local/bin/fahares-backup.sh verify   # بررسی سلامت مخزن Drive
```

کهنه شدن بکاپ را خود میزبان می‌پاید: `fahares-backup.sh watch` هر روز ساعت ۱۰ از cron اجرا می‌شود و اگر `status` شکست بخورد (بکاپ ناموفق، یا بکاپی که اصلاً اجرا نشده)، با ربات بکاپ در تلگرام هشدار می‌دهد. Hermes لازم نیست؛ فقط می‌تواند `status` را برای پاسخ به پرسش بخواند. فایل وضعیت در `/var/lib/fahares-backup/last.json` برای همه خواندنی است.

هشدار شکست هر کار را خود اسکریپت مستقیماً با ربات بکاپ می‌فرستد. پس اگر Hermes از کار بیفتد، باز هم هشدار می‌رسد.

## بازیابی در روز مبادا

پیش‌نیازها:
- کلید خصوصی age (فقط برای نسخه‌های تلگرام)
- رمز restic (برای Drive)
- یک کانتینر MariaDB که در حال اجرا باشد

### از Google Drive (ساده‌ترین)
```bash
export RESTIC_REPOSITORY=rclone:gdrive:fahares-restic RESTIC_PASSWORD_FILE=~/restic.pass
fahares-restore.sh --restic --container <mariadb-container> --database default
```

یک snapshot خاص را هم می‌شود داد: `--restic d09c2e56`. فهرست snapshotها با `restic snapshots --tag db` به دست می‌آید.

### از تلگرام
1. فایل `fahares-db-….manifest.json` را از کانال دانلود کنید. این همان پیام ✅ است که پس از تکه‌ها می‌آید.
2. اجرا:
   ```bash
   TG_BOT_TOKEN=... fahares-restore.sh --telegram fahares-db-….manifest.json \
       --identity ~/fahares-age.key --container <mariadb-container> --database default
   ```
   اسکریپت تکه‌ها را با ربات دانلود می‌کند، sha256 هر کدام را بررسی می‌کند، آن‌ها را سرهم و رمزگشایی می‌کند و در پایگاه داده بارگذاری می‌کند.

اگر ربات در دسترس نبود، تکه‌ها (`*.age.part000`، `part001`، …) را دستی از کانال دانلود کنید و این مراحل را اجرا کنید:
```bash
cat fahares-db-….sql.zst.age.part* > dump.sql.zst.age
fahares-restore.sh --file dump.sql.zst.age --identity ~/fahares-age.key --container ... --database ...
```

### نکته‌ها
- اگر پایگاه داده‌ی مقصد جدول داشته باشد، اسکریپت متوقف می‌شود. برای جایگزینی آگاهانه `--replace` بدهید.
- فقط برای بررسی سالم بودن فایل، بدون بارگذاری: `--check-only`
- پس از بازیابی:
  ```bash
  php artisan migrate --force
  php artisan scout:sync-index-settings
  php artisan scout:import "App\Models\Manuscript"
  php artisan scout:import "App\Models\Work"
  php artisan scout:import "App\Models\Person"
  ```
- داده‌ی جدول‌های `cache`، `sessions` و `jobs` عمداً در بکاپ نیست، ولی ساختارشان هست. پس از بازیابی کاربران باید دوباره وارد شوند.

## مانور بازیابی

ماهی یک بار آخرین بکاپ را روی سیستم محلی بازیابی کنید و شمار سطرها را با `row_counts` در manifest مقایسه کنید. `verify` ماهانه روی سرور فقط کامل و خواندنی بودن آخرین dump را بررسی می‌کند. بارگذاری واقعی در پایگاه داده را جایگزین نمی‌کند.
