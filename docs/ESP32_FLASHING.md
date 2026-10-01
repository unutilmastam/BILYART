# ESP32 ni dasturlash va ulash (ESP32 FLASHING)

Birinchi yuklash **kompyuterdan** qilinadi (egasining qarori, 2026-09-30) — hech narsa o'rnatish shart emas, faqat Chrome yoki Edge brauzeri va USB kabel. Keyingi yangilanishlar Wi-Fi orqali (OTA), kompyutersiz.

> EN summary: first flash = the merged `*-factory.bin` at offset 0x0 with the browser-based esptool (espressif.github.io/esptool-js). Later updates = OTA from Super Admin → Proshivka. Builds come only from GitHub Actions (`esp32-firmware` artifact).

## 0. Bir marta: ro'yxatdan o'tish siri (registration secret)
Qurilmalar serverga faqat shu sir bilan ro'yxatdan o'ta oladi (begona qurilmalar spam qilmasligi uchun).
1. Parol generatoridan **40 belgili** tasodifiy qator oling (harf + raqam). Uni xavfsiz joyda saqlang.
2. GitHub → repozitoriy → **Settings → Secrets and variables → Actions → New repository secret**.
3. Name: `DEVICE_REGISTRATION_SECRET`, Secret: shu qator → **Add secret**.
4. Server o'rnatilganda (Phase 16) aynan shu qiymat serverning `.env` fayliga ham yoziladi (`DEVICE_REGISTRATION_SECRET=`).

## 1. Proshivka faylini olish
1. GitHub → **Actions → CI** → yuqorida **Run workflow** → (versiya maydonini bo'sh qoldiring) → **Run workflow**.
   - Sir qo'shilgandan keyin albatta yangi build qiling — eski buildlarda sir yo'q.
2. Build tugagach (yashil ✓) uni oching → pastda **Artifacts → esp32-firmware** → yuklab oling (zip).
3. Zip ichida:
   - `bilyart-esp32-<versiya>-factory.bin` — **birinchi yuklash uchun** (shu kerak),
   - `bilyart-esp32-<versiya>.bin` — keyingi OTA yangilash uchun,
   - `SHA256SUMS.txt` — nazorat summalari.

## 2. Kompyuterdan yuklash (5 daqiqa)
1. ESP32 ni **ma'lumot uzatadigan** USB kabel bilan kompyuterga ulang.
2. Chrome/Edge'da oching: **https://espressif.github.io/esptool-js/**
3. **Baudrate: 460800** → **Connect** → ro'yxatdan port tanlang (`CP210x` yoki `USB Serial`) → **Ulanish**.
   - Port ko'rinmasa: drayver o'rnating (CP210x yoki CH340 — plata ustidagi mikrosxemaga qarab), boshqa kabel sinab ko'ring.
   - "Failed to connect" chiqsa: platadagi **BOOT** tugmasini bosib turing va yana **Connect** bosing.
4. **Flash Address: `0x0`**, fayl: `bilyart-esp32-...-factory.bin` → **Program**. 100% bo'lguncha kuting.
5. **Disconnect** → sahifadagi **Console** bo'limi → Baudrate **115200** → **Connect** → platadagi **EN (RST)** tugmasini bir marta bosing.
6. Konsolda shunday qator chiqadi:
   `Setup Wi-Fi: BILLIARD-3F2A  password: 48213907  (write this on the device label)`
   Qurilma kodi (`device ESP32-...`) va shu parolni qutiga yozib qo'ying (HARDWARE.md §6).

## 3. Filialda ulash (telefondan)
Har bir **filialga bitta ESP32** — u filialdagi stollarning chiroqlarini boshqaradi (har bir stolga bitta rele kanali; standart buildda 4 ta kanal, ko'pi bilan 8 ta). Filialda 8 tadan ko'p stol bo'lsa — ikkinchi ESP32.
1. Qurilmani HARDWARE.md bo'yicha elektrik o'rnatadi va quvvat beradi. Ko'k LED tez miltillaydi.
2. Telefon: **Wi-Fi → BILLIARD-XXXX** → yorliqdagi parol. Sozlash sahifasi o'zi ochiladi (ochilmasa brauzerda `http://192.168.4.1`).
3. Zal Wi-Fi nomi va parolini kiriting. **Server manzili**: `https://itcode.uz` (subdomen tanlansa — o'sha manzil). **Saqlash**.
4. 10–20 soniyadan keyin sahifani yangilang: **Ulash kodi** (6 raqam) chiqadi.
5. Admin panel → **Qurilmalar → ESP32 ulash** → kod, **filial** → **Ulash**.
6. LED doimiy yonib qoladi. Admin panelda qurilma "onlayn" ko'rinadi, ostida kanallar ro'yxati (hammasi "bo'sh"). Telefon Wi-Fi'ni avvalgi tarmoqqa qaytaring.
7. Admin panel → **Stollar** → har bir stol qatoridagi **Chiroq** tanlovida: `ESP32-XXXXXX · 1-kanal` (elektrik qaysi kanalni qaysi stol kontaktoriga ulagan bo'lsa — o'sha). Bitta kanalga faqat bitta stol.
8. **Qurilmalar** sahifasida har bir kanal yonida stol nomi ko'rinadi — elektrikning yorlig'i bilan solishtiring.

## 4. Tekshirish
HARDWARE.md §5 dagi sinovlarni bajaring: yoqilganda hamma chiroq o'chiq; har bir stol faqat **o'z** chirog'ini yoqadi; 5 daqiqa ogohlantirish; tugashda o'chish — internet uzilgan holda ham; quvvat uzilib qaytganda davom etish; erta to'xtatish.

## 5. Keyinchalik: Wi-Fi o'zgarsa yoki qurilmani boshqa joyga ko'chirish
- **BOOT tugmasini 3 soniya** bosib turing → sozlash Wi-Fi'si 10 daqiqaga yoqiladi → yangi Wi-Fi'ni kiriting.
- **10 soniya** bosib turing → zavod holatiga qaytadi (Wi-Fi va ulanish o'chadi; yorliqdagi sozlash paroli o'zgarmaydi). Boshqa mijozga ulashdan oldin admin panelda eski ulanishni **Uzish** kerak (Qurilmalar → qurilma → Uzish; stollar avtomatik ajratiladi). O'yin ketayotganda uzib bo'lmaydi.
- Shu mijozning **boshqa filialiga** ko'chirish: avval **Stollar**da bu qurilmaga ulangan stollarni "Ulanmagan" qiling, so'ng **Qurilmalar** → qurilma → **Boshqa filialga ko'chirish**.

## 6. Yangilash (OTA, kompyutersiz)
1. GitHub → **Actions → CI → Run workflow** → versiya: masalan `1.1.0` → **Run**. Tugagach `esp32-firmware` ni yuklab oling.
2. Super Admin → **Proshivka** → versiya `1.1.0`, fayl `bilyart-esp32-1.1.0.bin` (**factory emas**) → **Yuklash**. Server fayl ichidagi versiyani tekshiradi — mos kelmasa rad etadi.
3. **Nashr qilish** → **Qurilmalarga yuborish**. O'yin ketayotgan qurilmalar keyinga qoladi — keyinroq yana bosing.
4. Qurilma faylni yuklab, **SHA-256** ni tekshiradi, qayta yuklanadi. Yangi versiya 10 daqiqa ichida serverga ulana olmasa, **o'zi eski versiyaga qaytadi**.

## 7. Muammolar
| Belgi | Yechim |
|---|---|
| Sozlash sahifasida "ro'yxatdan o'tmadi (401)" yoki "(422)" | Buildda `DEVICE_REGISTRATION_SECRET` yo'q yoki serverdagidan farq qiladi (§0, §1). |
| "Admin panelda qurilmani uzib, qayta ulang" | Bu qurilma serverda hali ulangan deb turibdi: Admin → Qurilmalar → **Uzish**, keyin qayta ulang. |
| LED sekin miltillaydi | Server bilan aloqa yo'q. Stol chiroqlari baribir o'z vaqtida o'chadi. Wi-Fi signalini tekshiring (−75 dBm dan yaxshi). |
| Boshqa stolning chirog'i yondi | Stollar sahifasidagi kanal va elektrik ulagan kontaktor mos emas — kanalni to'g'rilang yoki kontaktor yorlig'ini tekshiring. |
| O'yin "FAILED", qurilma "BAD_CHANNEL" | Stolga platada yo'q kanal tanlangan (masalan 4 kanalli platada 6-kanal). To'g'ri kanalni tanlang. |
| Serverga ulanmaydi, lekin internet bor | Server sertifikati Let's Encrypt yoki Sectigo (cPanel AutoSSL) bo'lishi kerak — boshqa sertifikat bilan qurilma xavfsizlik uchun ulanmaydi. |
