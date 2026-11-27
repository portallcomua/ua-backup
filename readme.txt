=== UA Backup ===
Contributors: portallcomua
Tags: backup, restore, migration, database
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.2.4
License: GPLv2 or later

== Description ==

UA Backup створює повну резервну копію WordPress-сайту (файли та база даних) в одному ZIP-архіві та містить окремий інсталятор для перенесення сайту на інший хостинг.

== Features ==

* Повний ZIP-архів файлів сайту та бази даних.
* Відображення перебігу створення архіву без передчасного показу 100%.
* Автоматичне оновлення безпосередньо з GitHub.
* Українська, англійська, польська та німецька мови адмінпанелі відповідно до мови WordPress.
* Відображення поточної версії плагіна в адмінпанелі.
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
3. Завантажте ZIP-архів на комп'ютер.

== Restoring a site ==

1. Створіть порожню базу даних на новому хостингу.
2. Завантажте ZIP-архів у кореневу папку домену та розпакуйте його.
3. Відкрийте https://ваш-домен/installer.php.
4. Введіть параметри нової бази та адресу сайту. Службові дані архіву перевіряються автоматично.
5. Натисніть «Перевірити підключення», потім «Відновити базу даних».
6. Виберіть адміністратора або створіть нового та завершіть встановлення.
7. Після успіху перевірте сайт і вхід до адмінки.

Important: installer must be run only on a new/empty target database. Make an additional copy of the destination hosting files before migration. Do not delete the original backup archive until the restored site has been checked.

== Changelog ==

= 2.2.4 =
* Updates WP_HOME and WP_SITEURL constants to the destination URL during restore.
* Disables FORCE_SSL_ADMIN and removes explicit HTTPS redirects from .htaccess when restoring to an HTTP address.

= 2.2.3 =
* Starts the progress indicator immediately and refreshes the archive list as soon as the completed ZIP is ready.
* Removes the redundant full filesystem scan, avoids recompressing the already-compressed nested ZIP, and makes final ZIP verification faster.

= 2.2.2 =
* Made creating a new administrator an expandable optional section using a plus button.
* Existing backup administrator remains selected by default; new account fields are ignored unless expanded and completed.

= 2.2.1 =
* Clarified that the existing administrator is used by default and password changes are optional.
* Made creation of an additional administrator explicitly optional.
* Added show/hide password controls to administrator password fields.

= 2.2.0 =
* Fixed progress reporting with a unique run ID; 100% appears only after the ZIP is saved and verified.
* Added automatic updates from the GitHub main branch.
* Added version display and Ukrainian, English, Polish and German admin translations.
* Updated plugin author and project links.

= 2.1.0 =
* Hardened ZIP creation and database export.
* Added live progress reporting for backup creation.
* Added a token-protected staged restoration installer.
* Added database prefix migration and administrator selection/creation.
* Added serialized-aware URL replacement and installer cleanup.
* Added PHP 7.4 syntax validation workflow.

= 2.0.0 =
* Initial modular plugin build.
