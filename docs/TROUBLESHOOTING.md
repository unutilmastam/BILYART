# Muammolar va yechimlar (TROUBLESHOOTING)

Birinchi qadam har doim: Super Admin → **Tizim holati** (yoki `https://<domen>/health`). Qaysi band qizil — shu bo'limga o'ting. Terminal buyruqlari: cPanel → **Terminal**.

## 1. Sayt ochilmaydi
| Belgi | Sabab / yechim |
|---|---|
| "Index of /" yoki cPanel sahifasi | Domen hujjat ildizi noto'g'ri → cPanel → Domains → Document Root = `billiard/current/apps/api/public` (DEPLOY_UZ.md §8) |
| 500 xato, oq sahifa | `tail -n 50 ~/billiard/shared/storage/logs/laravel-$(date +%F).log` → xato kodi bo'yicha. Ko'pincha `.env` dagi baza paroli yoki PHP versiyasi |
| "Texnik ishlar" sahifasi uzoq turib qoldi | Yangilash yarim yo'lda to'xtagan: `php ~/billiard/current/apps/api/artisan up` |
| Yangilashdan keyin nimadir buzildi | Oldingi versiyaga: `bash ~/billiard/current/rollback.sh` |
| https ishlamaydi | cPanel → SSL/TLS Status → Run AutoSSL |

## 2. Tizim holati bandlari
| Band | Qizil bo'lsa |
|---|---|
| **Ma'lumotlar bazasi** | cPanel → Manage My Databases: baza va user bormi, parol `.env` dagi bilan bir xilmi; xosting limitini (Resource Usage) tekshiring |
| **Fayl saqlash** | Disk to'lgan (cPanel → Disk Usage). Eski zaxiralar avtomatik o'chiriladi; suratlarni saqlash muddatini kamaytiring (zal Sozlamalari) |
| **Cron va xabarlar** | Cron ishlamayapti: cPanel → Cron Jobs'da har daqiqalik qator bormi (DEPLOY_UZ.md §10). Qo'lda sinash: `php ~/billiard/current/apps/api/artisan schedule:run` |
| **Zaxira nusxalar** | So'nggi zaxira 30 soatdan eski yoki xato. Qo'lda: `php ~/billiard/current/apps/api/artisan backup:run`; xato matni Tizim holatida. `BACKUP_ENCRYPTION_KEY` bo'sh bo'lmasin |

## 3. Stol va chiroq
| Belgi | Sabab / yechim |
|---|---|
| Qurilma "oflayn" | Quvvat, Wi-Fi (signal −75 dBm dan yaxshi), router. Qurilmadagi LED: tez miltillash = sozlanmagan, sekin = server bilan aloqa yo'q. O'yinlar baribir o'z vaqtida tugaydi |
| O'yin "FAILED" (boshlanmadi) | Qurilma buyruqni 3 urinishda tasdiqlamadi → chiroq yonmagan, pul olinmasin. Qurilmani tekshiring |
| Chiroq o'yin tugaganda o'chmadi | Rele/kontaktor yopishib qolgan bo'lishi mumkin — elektrikka. Qurilma "OFF" deb hisobot berayotganini **Qurilmalar → Chiroq** ustunidan ko'ring |
| Chiroq umuman yonmaydi | Kontaktor g'altagi, sug'urta, rele moduli (HARDWARE.md). Stol boshqa stolning qurilmasiga ulanmaganmi — **Stollar → Qurilma** |
| Qurilmani qayta ulash kerak | Admin → Qurilmalar → **Uzish**; qurilmada BOOT 3 s → sozlash sahifasi → yangi kod (ESP32_FLASHING.md §5) |

## 4. Planshet
| Belgi | Yechim |
|---|---|
| "Aloqa yo'q. Iltimos, kuting." | Planshet Wi-Fi'si; internet qaytsa o'zi davom etadi |
| Ulash kodi ekraniga qaytib qoldi | Planshet admin panelda o'chirilgan yoki Chrome ma'lumotlari tozalangan → qayta ulang (TABLET_SETUP.md §3) |
| Kamera qora / surat olinmaydi | Chrome → Sayt sozlamalari → Kamera → Ruxsat. Planshetni qayta yoqing |
| Ogohlantirish eshitilmaydi | Media ovozi; Chrome'da sayt ovozi o'chirilmaganmi |
| Ilovadan chiqib ketishdi | App pinning qayta yoqilsin (TABLET_SETUP.md §5) |

## 5. Kirish (login)
| Belgi | Yechim |
|---|---|
| "Hisob vaqtincha bloklangan" | 5 marta noto'g'ri parol → 15 daqiqa kuting |
| Parol unutildi | Xodim → egasi yangi parol beradi; egasi → Super Admin "Egasi parolini tiklash"; Super Admin → `php ~/billiard/current/apps/api/artisan admin:create-super <yangi_login>` bilan yangi hisob |
| 2FA telefoni yo'qoldi | Zaxira kod bilan kiring. Super Admin'da zaxira kod ham yo'q bo'lsa: `php ~/billiard/current/apps/api/artisan user:2fa-reset <login>` |
| "Obuna faol emas" | Mijoz obunasi tugagan/to'xtatilgan → Super Admin to'lov qayd etadi |

## 6. Telegram
| Belgi | Yechim |
|---|---|
| Hisobot kelmayapti | Telegram bo'limida **So'nggi xato**; **Test xabar yuborish**. Chat ulangan va "Kunlik hisobot" yoqilganmi; filialning hisobot vaqti. Cron ishlayaptimi (§2) |
| Token noto'g'ri | @BotFather → `/token` → yangi token → qayta ulang |

## 7. Ma'lumot yo'qolsa / buzilsa
Zaxiradan tiklash — BACKUP.md §4 (avval hozirgi holatning zaxirasini oling). Tiklash barcha ma'lumotlarni zaxiradagi holatga qaytaradi — shoshilmang, kerak bo'lsa yordam so'rang.

## 8. Yordam so'raganda nimani yuborish kerak
- Tizim holati sahifasining skrinshoti;
- xato chiqqan ekran skrinshoti (unda "So'rov ID" bo'lsa — shu raqam);
- taxminiy vaqt (Toshkent vaqti).
**Hech qachon yubormang**: `.env` fayli, parollar, bot tokeni, zaxira kaliti.
