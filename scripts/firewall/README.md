# بستن پورت‌های مدیریتی به روی بیرون

Coolify پورت‌های ۸۰۰۰ (داشبورد روی HTTP)، ۶۰۰۱ و ۶۰۰۲ را روی همه‌ی نشانی‌ها منتشر می‌کند. هیچ‌کدام از بیرون لازم نیست: داشبورد و API از `https://coolify.fahares.net` و از راه شبکه‌ی داخلی Docker در دسترس‌اند. فایل compose خود Coolify را نمی‌شود تغییر داد، چون به‌روزرسانی شبانه آن را بازنویسی می‌کند. ufw هم اثری ندارد، چون Docker پورت منتشرشده را پیش از زنجیره‌ی INPUT به کانتینر می‌فرستد. پس قانون در زنجیره‌ی `DOCKER-USER` گذاشته می‌شود.

دو پنل مدیریت دیگر روی خود میزبان (بیرون از Docker) روی پورت‌های ۲۰۵۳ و ۲۰۱۲۸ گوش می‌دهند. ufw روی این میزبان فعال نیست، پس این دو در زنجیره‌ی `INPUT` و فقط برای ترافیک `eth0` رد می‌شوند. برنامه‌های خود میزبان و ترافیکی که میزبان به خودش می‌فرستد (از `lo`) همچنان به آن‌ها می‌رسند.

## نصب روی میزبان (یک‌بار)

```bash
sudo install -m 755 fahares-docker-firewall.sh /usr/local/sbin/
sudo install -m 644 fahares-docker-firewall.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now fahares-docker-firewall.service
```

## وارسی و برگشت

```bash
sudo /usr/local/sbin/fahares-docker-firewall.sh status
sudo systemctl disable --now fahares-docker-firewall.service   # برگشت: قانون‌ها برداشته می‌شوند
```

از بیرون سرور، `curl -m 8 http://<server-ip>:8000/` باید پس از نصب بی‌پاسخ بماند و `https://coolify.fahares.net` همچنان باز شود.
