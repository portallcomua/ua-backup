=== UA Backup ===
Contributors: portallcomua
Tags: backup, restore, migration, database
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later

== Description ==

UA Backup створює повну резервну копію WordPress-сайту (файли та база даних) в одному ZIP-архіві та містить окремий інсталятор для перенесення сайту на інший хостинг.

== Features ==

* Повний ZIP-архів файлів сайту та бази даних.
* Відображення перебігу створення архіву.
* Перевірка цілісності ZIP перед збереженням.
* Захист від паралельного запуску створення копій.
* Одноразовий ключ інсталятора для захисту процесу відновлення.
* Перевірка підключення до нової бази даних.
* Можливість задати новий префікс таблиць.
* Вибір адміністратора з резервної копії або створення нового адміністратора.
* Збереження старого пароля адміністратора, якщо новий пароль не задано.
* Оновлення адреси сайту зі збереженням PHP serialized data.
* Оновлення параметрів бази даних у wp-config.php.
* Очищення службових файлів інсталятора після успішного відновлення.

== Requirements ==

* WordPress 6.0 або новіший.
* PHP 7.4 або новіший.
* PHP ZipArchive для створення та перевірки архіву.
* Доступ до MySQL/MariaDB через mysqli на хостингу призначення.
* Достатньо вільного дискового простору та часу виконання PHP для розміру сайту.

== Installation ==

1. Завантажте ZIP плагіна через адмінку WordPress: Плагіни -> Додати новий -> Завантажити плагін.
2. Активуйте UA Backup.
3. Відкрийте Інструменти -> UA Backup.

== Creating a backup ==

1. Натисніть «Створити бекап».
2. Дочекайтеся завершення індикатора прогресу.
3. Скопіюйте одноразовий ключ інсталятора з повідомлення про успіх.
4. Завантажте ZIP-архів на комп'ютер.

== Restoring a site ==

1. Створіть порожню базу даних на новому хостингу.
2. Завантажте ZIP-архів у кореневу папку домену та розпакуйте його.
3. Відкрийте https://ваш-домен/installer.php.
4. Введіть одноразовий ключ, параметри нової бази та адресу сайту.
5. Натисніть «Перевірити підключення», потім «Відновити базу даних».
6. Виберіть адміністратора або створіть нового та завершіть встановлення.
7. Після успіху перевірте сайт і вхід до адмінки.

Important: installer must be run only on a new/empty target database. Make an additional copy of the destination hosting files before migration. Do not delete the original backup archive until the restored site has been checked.

== Changelog ==

= 2.1.0 =
* Hardened ZIP creation and database export.
* Added live progress reporting for backup creation.
* Added a token-protected staged restoration installer.
* Added database prefix migration and administrator selection/creation.
* Added serialized-aware URL replacement and installer cleanup.
* Added PHP 7.4 syntax validation workflow.

= 2.0.0 =
* Initial modular plugin build.
