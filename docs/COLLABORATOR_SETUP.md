# راه‌اندازی محلی برای همکار

این راهنما برای کسی است که روی دستگاه خودش روی سایت کار می‌کند. قواعد کار (شاخه، pull request، ادغام و انتشار) در بخش
«Collaborators on another machine» فایل `CLAUDE.md` است.

پیش‌نیاز: Docker با Docker Compose، `git` و `zstd`. حدود ۶ گیگابایت جای خالی برای پایگاه داده و نمایه‌ی جست‌وجو لازم است.

## ۱. کد و سرویس‌ها

```bash
git clone https://github.com/fahares/fahares.git
cd fahares
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app sh -c 'npm install && npm run build'
```

## ۲. داده

فایل داده (`fahares-dev-<تاریخ>.sql.zst`) را مالک پروژه می‌دهد. این فایل رونوشتی از پایگاه داده‌ی سایت است و جای مراحل
`download_data.sh` و `fahares:seed` در `README.md` را می‌گیرد. وارد کردنش حدود پنج دقیقه طول می‌کشد:

```bash
zstd -dc fahares-dev-<تاریخ>.sql.zst | docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
docker compose exec app php artisan migrate
```

`migrate` تغییرهای ساختاری پس از تاریخ فایل را اعمال می‌کند. پس از هر `git pull` هم آن را اجرا کنید.

## ۳. نمایه‌ی جست‌وجو

جست‌وجو و فهرست‌ها از Meilisearch می‌خوانند و بدون این مرحله خالی‌اند. ساختن نمایه زمان‌بر است:

```bash
docker compose exec app php artisan scout:sync-index-settings
docker compose exec app php artisan scout:import "App\Models\Work"
docker compose exec app php artisan scout:import "App\Models\Person"
docker compose exec app php artisan scout:import "App\Models\Manuscript"
docker compose exec app php artisan scout:import "App\Models\SourceItem"
```

## ۴. کاربر مدیر

کاربرهای فایل داده ساختگی‌اند و با آنها نمی‌شود وارد شد. برای خودتان کاربر بسازید:

```bash
docker compose exec app php artisan admin:create-user --email=you@example.test --name="نام شما"
```

سایت روی `http://localhost:8000` است.

## ۵. کار روی ظاهر

پس از تغییر در `resources/css` یا `resources/js` باید دوباره ساخته شود. تغییر در قالب‌های `resources/views` ساخت نمی‌خواهد.

```bash
docker compose exec app npm run build
```

## ۶. آزمون‌ها

آزمون‌ها پایگاه داده‌ی جدای `fahares_test` را به کار می‌برند و به داده‌ی بالا دست نمی‌زنند. پیش از هر pull request کل
مجموعه را اجرا کنید:

```bash
./scripts/create_test_db.sh
docker compose exec app php artisan test
```

## آنچه در فایل داده نیست

- **تصویر صفحه‌ها:** تصویر صفحه‌های فهرست‌ها روی سرور است و در نسخه‌ی محلی نمایش داده نمی‌شود. متن صفحه‌ها هست.
- **کاربران:** شناسه، نقش و تاریخ هر کاربر مانده و نام، ایمیل، گذرواژه و باقی مشخصات با مقدار ساختگی جایگزین شده است.
- **پیشنهادهای اصلاح:** بدون نام و ایمیل مهمان و یادداشت مدیر.
- **جدول‌های خالی:** نشست‌ها، دستگاه‌ها، پیام‌ها، اعلان‌ها، تاریخچه‌ی تغییرها (`audit_logs`)، و آمار بازدید و جست‌وجو. صفحه‌هایی که از
  اینها می‌خوانند خالی دیده می‌شوند.

فایل داده را منتشر نکنید و به کس دیگری ندهید.

## برای مالک: ساختن فایل داده

`scripts/dev/dev-dump <خروجی.sql.zst>` فایل را از رونوشت محلی پروداکشن (`fahares_prod`) می‌سازد. فهرست جدول‌های خالی و
ستون‌های پاک‌شده در همان اسکریپت است. جدول تازه‌ای که داده‌ی خصوصی دارد باید پیش از ساختن فایل بعدی به آن افزوده شود.
