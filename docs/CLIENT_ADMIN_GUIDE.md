# Zal egasi va xodimlar uchun qo'llanma

Manzil: `https://<domen>/admin/` → Super Admin bergan login va parol. Birinchi kirishda **Hisobim → Parolni o'zgartirish** va (tavsiya) **Ikki bosqichli kirish**ni yoqing.

## 1. Rollar
| Rol | Nima qila oladi |
|---|---|
| **Egasi** | hammasi: filiallar, Telegram, zal sozlamalari, ma'lumotlarni yuklab olish + menejer huquqlari |
| **Menejer** | stollar, narxlar, ish vaqti, qurilmalar, xodimlar, hisobotlar, suratlarni ko'rish/o'chirish + operator huquqlari |
| **Operator** | stollar va sessiyalarni ko'rish, o'yinni to'xtatish, to'lovni belgilash (suratlar — sozlamada ruxsat berilsa) |
Xodimni faqat ayrim filiallarga bog'lash mumkin (**Xodimlar → Filiallar**; bo'sh = hammasi).

## 2. Birinchi sozlash (tartib bilan)
1. **Filiallar → Filial qo'shish**: nomi, manzil, telefon, vaqt zonasi (Asia/Tashkent), kunlik hisobot vaqti.
2. Filial ichida **Ish vaqti**: har kun uchun ochilish/yopilish. `00:00–00:00` = kun bo'yi ochiq. Yarim tundan keyin yopilsa (masalan 10:00–02:00) — shunday yozing. **Dam olish kunlari** — alohida sanalar.
3. **Narxlar → Narx rejasi qo'shish**: 1 soat narxi (so'm), yaxlitlash qadami (masalan 1000), tanlanadigan vaqtlar (daqiqada, vergul bilan: `30, 60, 90, 120`). Pastda har bir vaqt uchun hisoblangan narx ko'rinadi.
4. **Stollar → Stol qo'shish**: raqam, nom, filial, narx rejasi.
5. **Qurilmalar → ESP32 ulash**: qurilma Wi-Fi sahifasidagi 6 xonali kod + **filial** (har bir filialga bitta ESP32, ESP32_FLASHING.md §3). Keyin **Stollar**da har bir stolning **Chiroq** tanlovidan `ESP32-… · N-kanal` ni tanlang — elektrik qaysi kanalni shu stol chirog'iga ulagan bo'lsa.
6. **Qurilmalar → Planshet ulash**: planshet ekranidagi kod + filial (TABLET_SETUP.md §3).
7. **Sozlamalar**:
   - *Suratlarni saqlash muddati (kun)* — muddat o'tgach suratlar avtomatik o'chiriladi.
   - *Mijozlarga surat haqida xabar* — planshetda ko'rsatiladi (qonun bo'yicha mijozni ogohlantirish zal egasining vazifasi).
   - *Operatorlar suratlarni ko'ra oladi* — ixtiyoriy.
   - *Ogohlantirish* — tugashiga necha daqiqa qolganda, va matn (`{table}` = stol raqami), masalan: `{table}-stol, sizda 5 daqiqa vaqtingiz qoldi.`
8. **Telegram** — §5.
9. **Xodimlar → Xodim qo'shish**: ism, login, parol, rol, filiallar.

## 3. Kundalik ish
- **Bosh sahifa**: bo'sh / o'ynalayotgan stollar (qachongacha), bugungi sessiyalar, o'yin vaqti, summa, to'lanmaganlar, qurilmalar onlayn/oflayn. Har necha soniyada yangilanadi.
- Mijoz o'yinni **planshetda o'zi** boshlaydi: stol → vaqt → narx → surat → chiroq yonadi. Surat **majburiy**: kamera yuzni aniqlagandagina avtomatik olinadi, suratsiz o'yin boshlanmaydi (surat — dalil). To'lov **kassada** olinadi.
- **Sessiyalar**: ro'yxat (holat va to'lov bo'yicha filtr). Sessiyani oching:
  - **To'landi** — kassada pul olinganda belgilang (yoki "Bepul").
  - **To'xtatish** — o'yinni muddatidan oldin tugatish; chiroq bir necha soniyada o'chadi.
  - **Surat** — ruxsati bor xodimga ko'rinadi; har bir ko'rish jurnalga yoziladi. Kerak bo'lmasa **o'chirish** mumkin.
  - **Tarix** — sessiyaning barcha bosqichlari (kim, qachon).
- **Hisobotlar → Kunlik / Oylik**: sessiyalar soni, o'yin vaqti, summa, to'lanmagan, stollar bandligi (filial bo'yicha).

## 4. Obuna
Bosh sahifada obuna holati va tugash sanasi. Tugashidan 5, 3, 1 kun oldin va tugash kuni eslatma keladi. Muddat tugasa: ma'lumotlar saqlanadi, lekin yangi o'yin boshlanmaydi — **Bog'lanish**dagi kontaktga murojaat qiling va to'lov qiling.

## 5. Telegram hisobotlari
**Telegram** bo'limi:
1. Telegram'da **@BotFather** → `/newbot` → nom → token oling.
2. Tokenni **Bot token** maydoniga kiriting → **Botni ulash** (token keyin hech qachon ko'rsatilmaydi).
3. **Chatni ulash kodi** → botga (yoki bot qo'shilgan guruhga) `/start KOD` yuboring (15 daqiqa amal qiladi).
4. **Ulangan chatlar**da har chat uchun: filial, *Kunlik hisobot*, *Ogohlantirishlar* (qurilma uzilishi, ishga tushmagan o'yin va h.k.).
5. **Test xabar yuborish** bilan tekshiring. Bot faqat sizning zalingiz ma'lumotini yuboradi.

## 6. Ma'lumotlarni yuklab olish
Egasi: **Bosh sahifa → ⬇ Ma'lumotlarni yuklab olish (JSON)** — zalingizning barcha ma'lumotlari bitta faylda (buxgalteriya/arxiv uchun). Suratlar kirmaydi.

## 7. Tez-tez uchraydigan holatlar
| Holat | Nima qilish kerak |
|---|---|
| Planshetda stol "Aloqa yo'q" | Filial ESP32 si oflayn (unda filialning barcha stollari "Aloqa yo'q" bo'ladi) yoki stolga kanal tanlanmagan (**Stollar → Chiroq**). **Qurilmalar**da oxirgi aloqa vaqtini ko'ring, qurilma quvvati va Wi-Fi ni tekshiring |
| Mijoz ketib qoldi, chiroq yonib turibdi | **Sessiyalar** → sessiya → **To'xtatish** |
| "Litsenziya limitiga yetdingiz" | Filial/stol/qurilma/xodim limiti tugagan — platforma administratori bilan bog'laning |
| Xodim parolini unutdi | **Xodimlar** → xodim → yangi parol (uning 2FA si ham o'chadi) |
| Planshetni almashtirish | **Qurilmalar → Planshetlar → O'chirish**, yangi planshetni ulash |
| Stol chirog'ini boshqa kanalga o'tkazish | **Stollar** → stol → **Chiroq** → yangi kanal (o'yin ketayotganda o'zgartirib bo'lmaydi) |
| ESP32 ni boshqa filialga ko'chirish | Avval uning stollarini **Stollar**da "Ulanmagan" qiling → **Qurilmalar** → qurilma → **Boshqa filialga ko'chirish** |
