# Super Admin qo'llanmasi (platforma egasi)

Manzil: `https://<domen>/admin/` → login. Super Admin **mijozlarning** (billiard zallari) obunasi, to'lovlari va limitlarini boshqaradi. U mijozlarning mijoz suratlarini ko'rmaydi (maxfiylik).

## 1. Birinchi kirishdan keyin
1. **Hisobim → Ikki bosqichli kirish → Yoqish** — telefondagi autentifikator ilovasi bilan. 8 ta zaxira kodni yozib, xavfsiz joyda saqlang.
2. **Sozlamalar**: qo'llab-quvvatlash kontakti (telefon/Telegram), to'lov bo'yicha ko'rsatma (karta raqami va kimning nomiga — mijoz to'lov sahifasida shuni ko'radi), standart filial limiti, **1 filial uchun oylik narx** (0 bo'lsa ilovadan to'lov o'chiq).
3. **Tizim holati** — 4 ta band (baza, fayllar, cron va xabarlar, zaxira nusxalar) yashil bo'lishi kerak.

## 2. Yangi mijoz (zal) qo'shish
**Mijozlar → Yangi mijoz**:
- Nomi, mas'ul shaxs, telefon.
- Limitlar: filial, stol, qurilma, foydalanuvchi soni (keyin o'zgartirish mumkin).
- Egasining ismi, logini, paroli (kamida 10 belgi, harf va raqam) — egaga xavfsiz yo'l bilan bering; birinchi kirishda parolni **Hisobim**da almashtirsin.
- Birinchi to'lov (ixtiyoriy): summa, turi, necha kun. To'lovsiz yaratilgan mijozning muddati darhol "tugagan" bo'ladi.

## 3. To'lov va muddat
Mijoz sahifasida:
- **To'lov qayd etish** — summa (butun so'm), turi (naqd / bank / karta / boshqa), kunlar. Obuna faol bo'lsa — joriy tugash sanasidan, tugagan bo'lsa — bugundan uzaytiriladi.
- **Muddat qo'shish** — to'lovsiz (bonus, kompensatsiya).

### To'lov so'rovlari (mijoz ilovadan to'laganda)
1. Mijoz o'z panelida muddatni tanlaydi (1/3/6/12 oy). Summa = faol filiallar × 1 filial narxi × oylar. Pulni kartangizga o'tkazadi va chek rasmini yuboradi.
2. Sizga xabar keladi (qo'ng'iroqcha). **To'lov so'rovlari** bo'limini oching → **Tekshirish** → **Chekni ko'rish**.
3. **Avval pul hisobingizga tushganini bank ilovangizda tekshiring.** Chekning o'zi — dalil emas.
4. Tushgan bo'lsa: kerak bo'lsa summani to'g'rilang → **Tasdiqlash**. Obuna avtomatik uzayadi (1 oy = 30 kun), to'lov **To'lovlar** ro'yxatiga yoziladi, mijozga xabar boradi.
5. Tushmagan bo'lsa: **Rad etish** → sababni yozing (mijoz ko'radi).
- **Tugash sanasini belgilash** — xato to'g'rilash uchun.
- Hammasi **Audit jurnali**ga yoziladi (kim, qachon, eski → yangi qiymat).
- Tizim muddat tugashidan 5, 3, 1 kun oldin va tugash kuni mijozga va sizga eslatma yuboradi (panel 🔔; mijozning Telegram chatiga ham).

Muddati tugaganda mijoz tizimga kira oladi, ma'lumotlari saqlanadi, lekin yangi o'yin boshlab bo'lmaydi va boshqaruv bo'limlari yopiladi — faqat obuna holati va to'lov ko'rsatmasi ko'rinadi. Stol qurilmalari davom etayotgan o'yinlarni oxirigacha to'g'ri yakunlaydi.

## 4. Boshqa amallar
| Amal | Qachon | Natija |
|---|---|---|
| **To'xtatish** (sabab bilan) | to'lov kechiksa, qoidabuzarlik | muddati tugagandek ishlaydi; **Faollashtirish** bilan qaytadi |
| **O'chirish** | mijoz butunlay ketsa | kira olmaydi, ochiq kirishlari tizimdan chiqariladi; ma'lumotlar saqlanadi |
| **Limitlarni o'zgartirish** | tarif o'zgarsa | limitdan oshgan narsalar o'chmaydi, faqat yangisini qo'shib bo'lmaydi |
| **Egasi parolini tiklash** | ega parolini unutsa / telefonini yo'qotsa | vaqtinchalik parol faqat bir marta ko'rinadi; egasining 2FA si o'chadi |

## 5. Proshivka (ESP32 yangilash)
**Proshivka** bo'limi — ESP32_FLASHING.md §6 ga qarang. Qisqasi: GitHub'da versiyani yig'ish → `bilyart-esp32-X.Y.Z.bin` ni yuklash → **Nashr qilish** → **Qurilmalarga yuborish**. O'yin ketayotgan stollar keyinroq yangilanadi.

## 6. Kundalik nazorat
- **Bosh sahifa**: mijozlar soni, shu oy va jami tushum, onlayn qurilmalar, so'nggi hodisalar.
- **To'lovlar**: barcha to'lovlar ro'yxati.
- **Xabarlar (🔔)**: muddatlar, qurilma uzilishlari, zaxira nusxa xatolari.
- **Tizim holati**: har kuni bir marta ko'rib turing. Qizil bo'lsa — TROUBLESHOOTING.md.

## 7. Xavfsizlik qoidalari
- Super Admin parolini va zaxira kodlarini hech kimga bermang; faqat o'z telefoningizdan kiring.
- Telefon yo'qolsa: zaxira kod bilan kiring → **Hisobim**da 2FA ni o'chirib, qayta yoqing. Zaxira kodlar ham yo'q bo'lsa — cPanel → Terminal: `php ~/billiard/current/apps/api/artisan user:2fa-reset <login>`.
- `billiard/shared/.env` va zaxira shifrlash kalitini hech kimga yubormang.
