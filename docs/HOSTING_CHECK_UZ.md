# Hostingni tekshirish (Phase 1) — qadam-baqadam

Bu yo'riqnoma telefon yoki iPad uchun. Kompyuter kerak emas.
Maqsad: Hostmaster hostingingiz nimalarni qo'llab-quvvatlashini aniq bilish.

Ikki qism bor: **A** — cPanel'dan skrinshotlar, **B** — avtomatik tekshirish fayli.

---

## A. cPanel skrinshotlari

cPanel'ga kiring (Hostmaster → Kabinet → cPanel). Har bir punkt uchun skrinshot oling:

1. **Select PHP Version** (yoki "MultiPHP Manager") → PHP versiyalari ro'yxati va "Extensions" sahifasi.
2. **MySQL® Databases** bor-yo'qligi. Shu sahifada **PostgreSQL Databases** ham bormi — qidiruv maydoniga `postgres` deb yozing.
3. **Terminal** yoki **SSH Access** bormi — qidiruvga `terminal`, keyin `ssh` deb yozing.
4. **Cron Jobs** bormi.
5. **Git™ Version Control** bormi.
6. **Setup Node.js App** bormi (faqat ma'lumot uchun).
7. **SSL/TLS Status** — domeningiz yonida yashil qulf (AutoSSL) bormi.
8. **Domains** — platforma qaysi domen/subdomen'da ishlaydi (masalan `billiard.sizningdomen.uz`). Agar hali yo'q bo'lsa — shunchaki yozing.
9. cPanel bosh sahifasining o'ng tomonidagi **Statistics** paneli: Disk Usage, File Usage (inodes), Physical Memory, Entry Processes, Number of Processes.
10. **FTP Accounts** bor-yo'qligi (parol yubormang!).

> Hech qachon parol, token yoki kalitlarni chatga yubormang.

---

## B. Avtomatik tekshirish fayli

Bu fayl hosting ichidagi haqiqiy qiymatlarni (PHP kengaytmalari, limitlar, Telegram'ga ulanish va h.k.) ko'rsatadi.
Xavfsizlik: fayl tasodifiy nom va maxfiy kalit bilan yaratiladi, **birinchi ochilishdan keyin o'zini o'chiradi**, 24 soatdan keyin ishlamaydi.

### B1. Faylni GitHub'da yaratish
1. Telefonda **github.com** → repozitoriy **bilyart** ni oching.
2. Yuqoridagi **Actions** bo'limini bosing (ko'rinmasa — `...` menyusida).
3. Chap ro'yxatdan **"Hosting check (build file)"** ni tanlang.
4. O'ngdagi **Run workflow** → branch `main` → yashil **Run workflow** tugmasini bosing.
5. 1 daqiqa kuting, sahifani yangilang. Yashil ✓ belgili qatorni oching.
6. Pastda **Artifacts** bo'limida **hosting-check** ni bosing → `hosting-check.zip` yuklanadi.

### B2. Faylni hostingga yuklash
1. cPanel → **File Manager** → **public_html** papkasini oching (yoki platforma subdomeni papkasini).
2. Yuqoridagi **Upload** → `hosting-check.zip` ni tanlang.
3. File Manager'ga qayting, zip ustiga bosib **Extract** ni tanlang.
4. Ichidagi `OCHISH.txt` faylini oching (ustiga bosing → **View**). Unda tayyor havola bor.

### B3. Natijani olish
1. `OCHISH.txt` dagi havolani telefon brauzerida oching (`DOMEN` o'rniga saytingiz manzilini yozing).
2. Sahifa ochiladi. Pastdagi **JSON** maydonidagi matnni to'liq nusxalang (bosib turing → Select all → Copy).
3. Matnni Claude'ga yuboring.
4. File Manager'da `hosting-check.zip` va `OCHISH.txt` ni o'chiring (**Delete**).

Agar sahifa **"Not found"** desa — havola noto'g'ri nusxalangan. **"Expired"** desa — B1 dan qaytadan boshlang.
