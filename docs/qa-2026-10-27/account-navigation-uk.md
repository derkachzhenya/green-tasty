# Навігація акаунта та бронювань

Виправлено змішування стандартного шаблону Laravel у налаштуваннях зі сторінками Green Tasty.

- Список бронювань тепер має адресу `/reservations` та видимий заголовок My reservations. Фільтри, пагінація, посилання після входу та повернення з гостьового меню використовують цю адресу.
- Після уточнення користувача `/dashboard` відкриває той самий екран карток бронювань і предзамовлень із заголовком Dashboard. Перенаправлення скасоване. Фільтри та пагінація цього екрана залишаються на `/dashboard`; список Reservations доступний за `/reservations`. Внутрішнє ім'я маршруту dashboard для `/reservations` збережене для сумісності; маршрут екрана акаунта — account.dashboard.
- Іконка акаунта відкриває меню Dashboard, Account settings, Log out. Вихід зберігає очищення приватної історії Inertia.
- Profile, Security та Appearance використовують шапку, кольори, шрифт і контейнер Green Tasty. Прибрано стандартну бічну панель із Repository і Documentation. Назва розділу — Account settings.
- Reservations позначається активним також на сторінках окремих бронювань; у налаштуваннях цей пункт не активний.
- Повідомлення про перезавантаження деталей бронювання називають сторінку reservation замість dashboard.

Перевірено у Chrome: старий URL, меню акаунта, переходи до Profile та Appearance, повернення до бронювань, завантаження списку, відкриття деталей та aria-current для Reservations. Записи бази під час перевірки не змінювалися.

Перевірки: усі 242 Vue-тести пройшли; цільові PHP — 65 пройшли, 4 пропущені, 826 assertions, ізольована SQLite :memory:. TypeScript, PHPStan, форматування/lint змінених файлів, клієнтська та SSR-збірки пройшли.

Після уточнення Dashboard: 28 цільових Vue-тестів і 25 PHP-тестів (483 assertions) пройшли. Перевірено окремий маршрут, доступ тільки до власних бронювань, пагінацію, пункт меню та відкриття сторінки в Chrome. TypeScript, PHPStan і клієнтська/SSR-збірки пройшли.

Знімки: [Dashboard](navigation-evidence/dashboard-orders.jpg), [Account settings](navigation-evidence/account-settings.jpg), [My reservations](navigation-evidence/my-reservations.jpg).
