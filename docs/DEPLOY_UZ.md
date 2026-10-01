# Xostingga o'rnatish (Hostmaster cPanel) — qadamma-qadam

Hammasi telefondan: GitHub sayti + cPanel. Kompyuter kerak emas. Birinchi o'rnatish ~30 daqiqa.

> EN summary: build the package with the "Release package" workflow, upload + extract it in cPanel File Manager, run `activate.sh` in cPanel Terminal twice (first run creates `~/billiard/shared/.env`), point the domain's document root to `billiard/current/apps/api/public`, add the cron line, create the Super Admin with `artisan admin:create-super`. Technical details: DEPLOYMENT.md.

## 0. Oldindan (bir marta)
- GitHub secret `DEVICE_REGISTRATION_SECRET` qo'shilgan bo'lsin (ESP32_FLASHING.md §0). Uning qiymatini §6 da serverga ham yozasiz.
- Parollar saqlanadigan joy (parol menejeri yoki qog'oz, seyfda) tayyor bo'lsin.

## 1. PHP versiyasi
cPanel → **Select PHP Version** (CloudLinux "PHP Selector"):
1. **Current PHP version**: `8.3` yoki `8.4` → **Apply**.
2. Pastdagi domenlar ro'yxatida tizim ishlaydigan domen yonida **Use PHP Selector** (aks holda sayt MultiPHP'dagi kengaytmasiz PHP bilan ishlaydi).
3. **Extensions** yorlig'ida belgilang: `pdo_mysql` (yoki PostgreSQL uchun `pdo_pgsql` va `pgsql`), `mbstring`, `fileinfo`, `gd`, `zip`, `intl`, `dom`, `xml`, `ctype`, `tokenizer`.
4. Terminalda tekshiring: `php -v` → `PHP 8.3…`; `php -m | grep -iE "pgsql|mbstring|zip|gd"` → nomlar chiqadi.
> Buyruqlarni qo'lda yozmang — nusxalab qo'ying (bosib turing → Copy, Terminalda bosib turing → Paste). Bitta harf xatosi buyruqni ishlatmaydi.

## 2. Ma'lumotlar bazasi
cPanel → **Manage My Databases** (MySQL):
1. **Create New Database**: nom masalan `bilyart` → **Create Database** (to'liq nomi `<cPanel login>_bilyart` bo'ladi; PostgreSQL uchun → **PostgreSQL Databases**, masalan `itcode_nbx`).
2. **Add New User**: masalan `bilyart`, parolni **Password Generator** bilan yarating → nusxa oling → **Create User**.
3. **Add User To Database**: shu user + shu baza → **ALL PRIVILEGES** → **Make Changes**.
4. Uch narsani yozib qo'ying: baza nomi, user nomi, parol.
> MySQL 8.0.16+ yoki MariaDB 10.6+ kerak (Hosting check hisobotida ko'rinadi). PostgreSQL ham ishlaydi (cPanel → PostgreSQL Databases).

## 3. Paketni olish (GitHub)
1. GitHub → repozitoriy → **Actions** → chapda **Release package** → **Run workflow** → Version: `1.0.0` → **Run workflow**.
2. 3–5 daqiqadan keyin build yashil ✓ bo'ladi → uni oching → pastda **Artifacts → bilyart-release-1.0.0** → yuklab oling (telefonga zip tushadi).

## 4. Serverga yuklash
cPanel → **File Manager** → chapda uy papkasi 🏠 (`/home/<cPanel login>`, **public_html yoki domen papkasi emas** — ular internetga ochiq):
1. GitHub'dan tushgan arxiv ichidagi **`bilyart-1.0.0.zip`** ni oling (telefon arxivni o'zi ochib bergan bo'lishi mumkin; `.sha256` fayli kerak emas).
2. **Upload** → `bilyart-1.0.0.zip` → 100% bo'lguncha kuting.

## 5. O'rnatish — 1-marta ishga tushirish
cPanel → **Terminal** → quyidagini nusxalab qo'ying va Enter:
```
cd ~ && unzip -qo bilyart-1.0.0.zip && bash bilyart-1.0.0/activate.sh
```
Birinchi marta skript `~/billiard/shared/.env` faylini yaratadi, maxfiy kalitlarni o'zi yozadi va to'xtaydi ("Endi shu faylni oching…" degan xabar chiqadi). Bu normal.

## 6. Sozlamalar faylini to'ldirish (.env)
File Manager → o'ng yuqorida **Settings** → **Show Hidden Files (dotfiles)** ✓ → **Save**. Keyin `billiard/shared/.env` → **Edit**:

| Qator | Nima yoziladi |
|---|---|
| `APP_URL=` | `https://nbx.itcode.uz` (tanlangan domen; **oxirida `/` siz**) |
| `DB_CONNECTION=` | `mysql` (MariaDB bo'lsa `mariadb`, PostgreSQL bo'lsa `pgsql` va `DB_PORT=5432`) |
| `DB_DATABASE=` / `DB_USERNAME=` / `DB_PASSWORD=` | §2 dagi qiymatlar |
| `DEVICE_REGISTRATION_SECRET=` | GitHub'dagi bilan **aynan bir xil** qiymat |
| `TELEGRAM_WEBHOOK_BASE_URL=` | `APP_URL` bilan bir xil (oxirida `/` siz) |

Parolda `#` yoki bo'sh joy bo'lsa, qo'shtirnoqqa oling: `DB_PASSWORD="…"`. `DEVICE_REGISTRATION_SECRET` hali bo'lmasa, Terminalda yarating: `php -r 'echo bin2hex(random_bytes(20)), PHP_EOL;'` — chiqqan qatorni shu yerga va GitHub secret'ga qo'ying.
⚠️ `.env` faylini (yoki uning skrinshotini) hech kimga yubormang.

**Save Changes**. So'ng shu fayldagi `BACKUP_ENCRYPTION_KEY=` qiymatini nusxalab, parol menejeriga saqlang — busiz zaxira nusxalarni tiklab bo'lmaydi (BACKUP.md §2). Bu faylni hech kimga yubormang.

## 7. O'rnatish — 2-marta ishga tushirish
Terminal:
```
cd ~ && bash bilyart-1.0.0/activate.sh
```
Oxirida **"Faol versiya: 1.0.0"** va keyingi qadamlar chiqadi. Xato bo'lsa — xabarda nima qilish yozilgan (masalan PHP kengaytmasi yoki baza paroli).

## 8. Domenni ulash
cPanel → **Domains** (amaldagi o'rnatish: subdomen `nbx.itcode.uz`):
- domen yonida **Manage** → **New Document Root** maydonidagi eski yozuvni o'chirib → `billiard/current/apps/api/public` → **Update**. (**Remove Domain** ni bosmang.)
- `.env` yoki `APP_URL` keyin o'zgartirilsa: `cd ~/billiard/current/apps/api` va `php artisan config:cache`.
- "Forbidden … unable to read htaccess file" chiqsa (1.0.0 paketida shunday edi): Terminal → `chmod 711 ~/billiard`.
- Asosiy domenning ildizini o'zgartirib bo'lmasa: **Create A New Domain** → `billiard.itcode.uz` → Document Root: `billiard/current/apps/api/public` → **Submit**. Shunda §6 dagi `APP_URL` ni ham shu manzilga o'zgartiring va §7 ni qayta bajaring.

## 9. SSL (https)
cPanel → **SSL/TLS Status** → domenni belgilang → **Run AutoSSL** → yashil qulf chiqquncha kuting (bir necha daqiqa). HTTPS'siz tizim ishlamaydi (xavfsizlik).

## 10. Cron (fon vazifalari)
cPanel → **Cron Jobs** → **Common Settings: Once Per Minute (* * * * *)** → **Command** maydoniga §7 da skript chiqargan qatorni qo'ying (taxminan shunday):
```
/usr/local/bin/php /home/<cPanel login>/billiard/current/apps/api/artisan schedule:run >> /dev/null 2>&1
```
→ **Add New Cron Job**. Busiz sessiyalar yakunlanmaydi, Telegram hisobotlari va zaxira nusxalar ishlamaydi.

## 11. Super Admin hisobi
Terminal:
```
php ~/billiard/current/apps/api/artisan admin:create-super bakhrullo
```
Parolni ikki marta kiriting (ekranda ko'rinmaydi, kamida 12 belgi). Parol hech qayerda saqlanmaydi.

## 12. Tekshirish
1. Brauzer: `https://nbx.itcode.uz/health` → `{"status":"ok"}`. Birinchi soatlarda `warn`/`fail` bo'lishi mumkin: zaxira hali olinmagan. Darhol olish uchun Terminal: `php ~/billiard/current/apps/api/artisan backup:run`.
2. `https://nbx.itcode.uz/admin/` → login → **Hisobim → Ikki bosqichli kirish → Yoqish** (tavsiya etiladi).
3. **Tizim holati** — hamma bandlar yashil.
4. **Sozlamalar** → qo'llab-quvvatlash kontakti va to'lov ko'rsatmasini yozing.
5. **Mijozlar → Yangi mijoz** → birinchi zal (CLIENT_ADMIN_GUIDE.md ga o'ting).
6. Planshet: TABLET_SETUP.md. ESP32: ESP32_FLASHING.md.
7. Terminal'da o'rnatish qoldiqlarini o'chiring: `rm -f ~/bilyart-release-*.zip ~/bilyart-*.zip`.

## 13. Yangilash (keyingi versiyalar)
1. §3 → yangi versiya raqami (masalan `1.0.1`).
2. §4 → yuklash va **Extract**.
3. Terminal: `cd ~ && unzip -qo bilyart-1.0.1.zip && bash bilyart-1.0.1/activate.sh`
   - Yangilash paytida sayt bir necha soniya "texnik ishlar" rejimida bo'ladi. Migratsiya yoki tekshiruv o'tmasa, **eski versiya ishlashda qoladi**.
4. Muammo bo'lsa, oldingi versiyaga qaytish: `bash ~/billiard/current/rollback.sh`

## 14. (Ixtiyoriy) Avtomatik yangilash tugmasi
Sozlangandan keyin GitHub → Actions → **Deploy to hosting** → versiya + `DEPLOY` → paket yig'iladi va serverga o'zi o'rnatiladi.
1. cPanel → **SSH Access → Manage SSH Keys → Generate a New Key** (Key Name: `github-deploy`) → **Generate Key** → **Go Back** → kalit yonida **Manage → Authorize**.
2. **Private Keys** bo'limida `github-deploy` → **View/Download** → matnni nusxalang.
3. GitHub → **Settings → Secrets and variables → Actions → New repository secret**:
   - `DEPLOY_SSH_KEY` = shu private key matni;
   - `DEPLOY_HOST` = server nomi (cPanel bosh sahifasidagi "Server Name"/"Shared IP Address");
   - `DEPLOY_USER` = cPanel login (masalan `itcode`);
   - `DEPLOY_PORT` = SSH porti (SSH Access sahifasida; odatda 22);
   - `DEPLOY_KNOWN_HOSTS` = Terminal'da `ssh-keyscan -p <port> <DEPLOY_HOST>` natijasi (hamma qatorlar).
4. **Variables** yorlig'i → `DEPLOY_URL` = `https://nbx.itcode.uz`. Kerak bo'lsa `DEPLOY_PHP_BIN` = §10 dagi php yo'li.
5. GitHub → **Settings → Environments → New environment** → `production` → (imkoni bo'lsa) **Required reviewers**: o'zingiz — har deploy'dan oldin tasdiqlash so'raladi. Yopiq (private) repozitoriyda bu funksiya GitHub'ning pullik tarifida bo'ladi; bo'lmasa, `DEPLOY` so'zini yozish tasdiq vazifasini bajaradi.
