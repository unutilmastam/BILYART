# Planshetni sozlash (TABLET SETUP)

Bu hujjat zal egasi / xodim uchun: Android planshetni mijozlar uchun kioskka aylantirish. Hammasi telefondan / planshetning o'zidan bajariladi, kompyuter kerak emas.

> Technical summary (EN): the kiosk is the tablet PWA at `https://<domain>/tablet/`. Two ways to lock it: **(A, recommended, owner request 2026-10-02)** the NBX Kiosk APK (`apps/android-kiosk`) installed as **Device Owner** by QR provisioning on a factory-reset tablet — Lock Task with no Home/Recents/status bar, launcher + auto-start, screen always on, exit only by hidden gesture + admin PIN; or **(B)** the PWA from Chrome + Android **App pinning** (§1–§7). Pairing = code on the tablet → admin binds it to a branch.

## A. To'liq qulflangan kiosk ilova (NBX Kiosk APK) — tavsiya
Planshet faqat NBX ekranini ko'rsatadi: **Orqaga, Bosh ekran, So'nggi ilovalar, tepadagi panel ishlamaydi**, ekran o'chmaydi (zaryadda), planshet yonganda ilova o'zi ochiladi.

**Bir marta (platforma egasi):** GitHub → Settings → Secrets and variables → Actions → ikkita secret: `KIOSK_KEYSTORE_BASE64` va `KIOSK_KEYSTORE_PASSWORD` (imzo kaliti; uni yo'qotmang — keyingi yangilanishlar shu kalit bilan bo'lishi shart). Keyin **Release package** dan yangi versiya yig'ing — APK paket ichida bo'ladi (`/kiosk/`).

**Har bir planshet (5–10 daqiqa):**
1. Planshetni **zavod holatiga qaytaring** (Sozlamalar → Tizim → Tiklash → Barcha ma'lumotlarni o'chirish). Bu shart: Android kiosk huquqini faqat yangi holatdagi planshetga beradi.
2. Telefonda admin panel → **Qurilmalar → Kiosk ilova** → zal Wi-Fi nomi va paroli (ixtiyoriy) → **QR kodni ko'rsatish**.
3. Planshetning birinchi **"Salom / Welcome"** ekranida bo'sh joyni ketma-ket **6 marta** bosing → QR skaner ochiladi → telefondagi QR ni skanerlang.
4. Planshet Wi-Fi ga ulanadi, ilovani serverdan o'zi yuklab o'rnatadi va kiosk rejimiga o'tadi (bir necha daqiqa).
5. Ilova ochilganda **administrator PIN** (4–8 raqam) o'rnating — faqat mas'ul xodim bilsin.
6. Ekrandagi 6 raqamli kodni admin panel → **Qurilmalar → Planshet ulash** ga kiriting (§3).

**Xodim uchun chiqish:** ekranning **chap yuqori burchagini 4 soniya ichida 7 marta** bosing → PIN → menyu: sahifani yangilash, PIN ni o'zgartirish, Android sozlamalari, vaqtincha chiqish (ilova yana ochilganda qayta qulflanadi), kiosk rejimini butunlay o'chirish. 5 marta xato PIN → 5 daqiqa kutish.

**Yangilanishlar:** ekran va funksiyalar serverdan keladi (APK yangilash shart emas). APK ning o'zini yangilash juda kam kerak bo'ladi.

**Cheklov:** yoqish/o'chirish (power) tugmasini hech qaysi dastur to'liq bloklay olmaydi — planshetni tugmalari yopiladigan qulflanadigan stendga o'rnating. Planshet o'chib-yonsa, ilova o'zi ochiladi.

> Quyidagi §1–§7 — APK siz, Chrome + App pinning bilan (oddiyroq, lekin to'liq qulf emas).

## 0. Nima kerak
- Android 9 yoki yangiroq planshet, **old kamerasi** bilan (10" yoki kattaroq tavsiya etiladi).
- Doimiy quvvat (zaryadlovchi doim ulangan) va zalning Wi-Fi'si.
- Google Chrome (yangi versiya). Play Market → Chrome → **Yangilash**.
- Admin panelga kirish (Client Owner yoki Manager).

## 1. Android sozlamalari (bir marta)
1. **Sozlamalar → Ekran → Ekranni o'chirish vaqti** → eng uzun qiymat (30 daqiqa yoki "Hech qachon").
   - Yaxshiroq: **Sozlamalar → Telefon haqida → Build raqami** ga 7 marta bosing → **Dasturchi sozlamalari → "Zaryadlanayotganda ekran o'chmasin"** ni yoqing.
2. **Sozlamalar → Sana va vaqt → Avtomatik** (vaqt baribir serverdan olinadi, lekin yoqib qo'ying).
3. **Sozlamalar → Tovush → Media ovozi** → baland (5 daqiqa qolganda ogohlantirish shu ovozda aytiladi).
4. **Sozlamalar → Batareya → Chrome → Cheklanmagan** (batareyani tejash Chrome'ni to'xtatmasin).
5. **Ovozli ogohlantirish uchun:** **Sozlamalar → Tizim → Tillar → Matnni nutqqa aylantirish (Text-to-speech)** → "Google nutq xizmatlari" tanlangan bo'lsin. O'zbek ovozi bo'lmasa ham ishlaydi: avval qo'ng'iroq ovozi chalinadi, keyin matn eng yaqin ovozda o'qiladi (§7).

## 2. Ilovani o'rnatish
1. Chrome'ni oching va manzilga kiring: `https://<sizning domeningiz>/tablet/`
2. Chrome menyusi **⋮** → **"Ilovani o'rnatish"** (yoki "Bosh ekranga qo'shish") → **O'rnatish**.
3. Bosh ekranda **Bilyart** belgisi paydo bo'ladi. Endi har doim shu belgidan oching — ilova to'liq ekranda ochiladi.

## 3. Planshetni zalga ulash
1. Planshetda **Bilyart** ni oching. Ekranda katta **6 xonali kod** chiqadi (15 daqiqa amal qiladi, keyin avtomatik yangilanadi).
2. Telefoningizda admin panelni oching: **Qurilmalar → Planshet ulash**.
3. **Ulash kodi** ga planshetdagi kodni yozing, **filialni** tanlang, xohlasangiz **Planshet nomi** ("Kirish oldidagi planshet") → **Ulash**.
4. 3–5 soniyada planshet o'zi stollar ro'yxatiga o'tadi. Tayyor.
   - Planshet faqat tanlangan filialning stollarini ko'radi. Boshqa mijoz yoki filial ma'lumotini ko'ra olmaydi.

## 4. Kamerani ruxsat berish
1. Birinchi marta mijoz stol tanlab **Davom etish** ni bosganda Chrome kamerani so'raydi → **"Ilovadan foydalanishda ruxsat berish"**.
2. Agar tasodifan "Rad etish" bosilgan bo'lsa: Chrome → **⋮ → Sozlamalar → Sayt sozlamalari → Kamera** → sizning domeningiz → **Ruxsat berish**.
3. Kamera faqat bitta surat uchun ochiladi, surat olingach darhol o'chadi. Video yozilmaydi. Yuz faqat *aniqlanadi* (surat vaqtini tanlash uchun), hech kim *tanilmaydi*.
4. Birinchi marta yuzni aniqlash moduli (~12 MB) yuklanadi — Wi-Fi'da bir necha soniya. Keyin planshetda saqlanib qoladi.

## 5. Kiosk rejimi: App pinning (ekranni mahkamlash)
Mijoz ilovadan chiqib ketmasligi uchun:
1. **Sozlamalar → Xavfsizlik (yoki "Xavfsizlik va maxfiylik") → Qo'shimcha → Ilovani mahkamlash (App pinning / Screen pinning)** → **Yoqish**.
2. Shu yerda **"Chiqishdan oldin PIN so'rash"** ni yoqing (planshetga PIN-kod qo'yilgan bo'lishi kerak: Sozlamalar → Xavfsizlik → Ekran qulfi → PIN).
3. **Bilyart** ni oching → **So'nggi ilovalar** tugmasi (□ yoki ekran pastidan tepaga surib ushlab turing) → Bilyart oynasi tepasidagi belgiga bosing → **Mahkamlash (Pin)**.
4. Chiqish (faqat xodim): **Orqaga** va **So'nggi ilovalar** tugmalarini birga bosib turing (yoki pastdan surib ushlab turing) → PIN kiriting.

## 6. Kundalik ishlash
- Planshet doim yoqilgan va zaryadda turadi. Ekranning yuqorisida zal nomi va vaqt ko'rinadi.
- **"Aloqa yo'q. Iltimos, kuting."** — internet uzilgan. Stollar va qolgan vaqt ko'rinib turadi, lekin yangi o'yin boshlanmaydi (server tasdig'isiz sessiya ochilmaydi). Internet qaytsa o'zi davom etadi. Stol chiroqlari ESP32 da o'z vaqtida o'zi o'chadi.
- **"Hozir ish vaqti emas"** — filial ish vaqti tashqarisida.
- **"Xizmat vaqtincha to'xtatilgan"** — obuna muddati tugagan yoki to'xtatilgan (platforma administratori bilan bog'laning).
- Mijoz 60 soniya hech narsa bosmasa, planshet stollar ro'yxatiga qaytadi va band qilingan stolni bo'shatadi.
- Planshetni o'chirish/almashtirish: admin panel → **Qurilmalar → Planshetlar → O'chirish**. Planshet darhol ulash ekraniga qaytadi.

## 7. Cheklovlar (ochiq aytamiz)
| Cheklov | Nima qilish kerak |
|---|---|
| Planshet qayta yoqilganda (reboot) ilova avtomatik ochilmaydi va mahkamlash yo'qoladi. | Xodim Bilyart ni ochib, §5.3 bo'yicha qayta mahkamlaydi. |
| PIN va chiqish ishorasini biladigan odam ilovadan chiqa oladi (App pinning — to'liq "Device Owner" kiosk emas). | PIN ni faqat mas'ul xodim bilsin. To'liq qulf kerak bo'lsa — **A bo'lim** (NBX Kiosk APK). |
| Chrome ma'lumotlari tozalansa, planshet ulanishi o'chadi. | §3 bo'yicha qayta ulang (1 daqiqa). |
| O'zbek TTS ovozi ko'p planshetlarda yo'q. | Ogohlantirishdan oldin baland qo'ng'iroq chalinadi, matn eng yaqin ovozda o'qiladi. Stol chirog'i ham 3 marta miltillaydi (ESP32). |
| Surat faqat yuz aniqlanganda avtomatik olinadi; suratsiz o'yin boshlanmaydi (egasining qarori — surat dalil). Yuzni aniqlash moduli ishlamasa (juda eski planshet, xotira yetishmasa), o'yin boshlab bo'lmaydi. | Ekranda "Yuzni aniqlash ishga tushmadi" chiqadi → **Qayta urinish**. Takrorlansa — planshetni qayta yoqing; bo'lmasa kuchliroq planshet kerak (Android 9+, 3 GB+ RAM tavsiya). |

## 8. Muammolar
| Belgi | Sabab / yechim |
|---|---|
| Kod chiqmayapti, aylanib turadi | Internet yo'q yoki domen noto'g'ri. Chrome'da `https://<domen>/health` ochilishini tekshiring. |
| "Ulash kodi noto'g'ri" (admin panelda) | Kod eskirgan — planshetdagi yangi kodni kiriting. 15 daqiqada 5 tadan ko'p urinish bloklanadi, biroz kuting. |
| Kamera qora | Boshqa ilova kamerani band qilgan yoki ruxsat yo'q (§4.2). Planshetni qayta ishga tushiring. |
| "Stol qurilmasi bilan aloqa yo'q" | Shu stoldagi ESP32 oflayn. Admin panel → Qurilmalar da holatini ko'ring. |
| Ogohlantirish eshitilmaydi | Media ovozi (§1.3); Chrome'da sayt ovozi o'chirilmaganini tekshiring. |
