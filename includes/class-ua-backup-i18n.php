<?php
defined('ABSPATH') || exit;

final class UA_Backup_I18n {
    private static function language(): string {
        $locale = get_locale();
        $locale = strtolower(str_replace('_', '-', (string) $locale));
        $language = substr($locale, 0, 2);
        return in_array($language, ['uk', 'pl', 'de'], true) ? $language : 'en';
    }

    public static function t(string $key): string {
        $strings = [
            'stage_db' => ['uk' => 'Експорт бази даних', 'en' => 'Exporting database', 'pl' => 'Eksport bazy danych', 'de' => 'Datenbank wird exportiert'],
            'stage_files' => ['uk' => 'Базу даних збережено. Підготовка файлів сайту', 'en' => 'Database saved. Preparing website files', 'pl' => 'Baza danych zapisana. Przygotowywanie plików witryny', 'de' => 'Datenbank gespeichert. Website-Dateien werden vorbereitet'],
            'stage_files_count' => ['uk' => 'Архівація файлів', 'en' => 'Archiving files', 'pl' => 'Archiwizowanie plików', 'de' => 'Dateien werden archiviert'],
            'stage_zip' => ['uk' => 'Перевірка цілісності ZIP-архіву', 'en' => 'Verifying ZIP archive integrity', 'pl' => 'Sprawdzanie integralności archiwum ZIP', 'de' => 'ZIP-Archiv wird auf Integrität geprüft'],
            'backup_created' => ['uk' => 'Бекап успішно створено.', 'en' => 'Backup created successfully.', 'pl' => 'Kopia zapasowa została utworzona.', 'de' => 'Backup erfolgreich erstellt.'],
            'error_prefix' => ['uk' => 'Помилка:', 'en' => 'Error:', 'pl' => 'Błąd:', 'de' => 'Fehler:'],
            'author' => ['uk' => 'Автор:', 'en' => 'Author:', 'pl' => 'Autor:', 'de' => 'Autor:'],
            'page_title' => ['uk' => 'UA Backup', 'en' => 'UA Backup', 'pl' => 'UA Backup', 'de' => 'UA Backup'],
            'version' => ['uk' => 'Версія', 'en' => 'Version', 'pl' => 'Wersja', 'de' => 'Version'],
            'description' => ['uk' => 'Повна резервна копія WordPress: файли сайту та база даних в одному ZIP-архіві.', 'en' => 'Full WordPress backup: website files and database in one ZIP archive.', 'pl' => 'Pełna kopia zapasowa WordPress: pliki witryny i baza danych w jednym archiwum ZIP.', 'de' => 'Vollständiges WordPress-Backup: Website-Dateien und Datenbank in einem ZIP-Archiv.'],
            'create_title' => ['uk' => 'Створити бекап', 'en' => 'Create backup', 'pl' => 'Utwórz kopię', 'de' => 'Backup erstellen'],
            'create_desc' => ['uk' => 'Зберегти повну копію сайту разом із базою даних.', 'en' => 'Save a complete copy of the website and its database.', 'pl' => 'Zapisz pełną kopię witryny wraz z bazą danych.', 'de' => 'Eine vollständige Kopie der Website einschließlich Datenbank speichern.'],
            'create_button' => ['uk' => 'Створити бекап', 'en' => 'Create backup', 'pl' => 'Utwórz kopię', 'de' => 'Backup erstellen'],
            'creating' => ['uk' => 'Створення...', 'en' => 'Creating...', 'pl' => 'Tworzenie...', 'de' => 'Wird erstellt...'],
            'try_again' => ['uk' => 'Спробувати ще раз', 'en' => 'Try again', 'pl' => 'Spróbuj ponownie', 'de' => 'Erneut versuchen'],
            'preparing' => ['uk' => 'Підготовка резервної копії...', 'en' => 'Preparing backup...', 'pl' => 'Przygotowywanie kopii...', 'de' => 'Backup wird vorbereitet...'],
            'starting' => ['uk' => 'Запуск резервного копіювання...', 'en' => 'Starting backup...', 'pl' => 'Uruchamianie kopii...', 'de' => 'Backup wird gestartet...'],
            'finishing' => ['uk' => 'Завершення та перевірка архіву...', 'en' => 'Finishing and verifying archive...', 'pl' => 'Kończenie i sprawdzanie archiwum...', 'de' => 'Archiv wird abgeschlossen und geprüft...'],
            'ready' => ['uk' => 'Готово! Архів створено. Оновлюємо список...', 'en' => 'Done! Archive created. Refreshing the list...', 'pl' => 'Gotowe! Archiwum utworzone. Odświeżanie listy...', 'de' => 'Fertig! Archiv erstellt. Liste wird aktualisiert...'],
            'connection_error' => ['uk' => 'Помилка з’єднання. Оновіть сторінку та перевірте список бекапів.', 'en' => 'Connection error. Refresh the page and check the backup list.', 'pl' => 'Błąd połączenia. Odśwież stronę i sprawdź listę kopii.', 'de' => 'Verbindungsfehler. Aktualisieren Sie die Seite und prüfen Sie die Backup-Liste.'],
            'generic_error' => ['uk' => 'Не вдалося створити бекап.', 'en' => 'The backup could not be created.', 'pl' => 'Nie udało się utworzyć kopii zapasowej.', 'de' => 'Das Backup konnte nicht erstellt werden.'],
            'install_title' => ['uk' => 'Встановити сайт', 'en' => 'Restore website', 'pl' => 'Przywróć witrynę', 'de' => 'Website wiederherstellen'],
            'install_desc' => ['uk' => 'Перенести резервну копію на інший хостинг.', 'en' => 'Move a backup to another hosting provider.', 'pl' => 'Przenieś kopię zapasową na inny hosting.', 'de' => 'Ein Backup auf ein anderes Hosting übertragen.'],
            'install_button' => ['uk' => 'Встановити сайт', 'en' => 'Restore website', 'pl' => 'Przywróć witrynę', 'de' => 'Website wiederherstellen'],
            'restore_title' => ['uk' => 'Встановлення на новому хостингу', 'en' => 'Restore on new hosting', 'pl' => 'Przywracanie na nowym hostingu', 'de' => 'Wiederherstellung auf neuem Hosting'],
            'step1' => ['uk' => 'Створіть резервну копію та скачайте ZIP-архів нижче.', 'en' => 'Create a backup and download the ZIP archive below.', 'pl' => 'Utwórz kopię zapasową i pobierz poniżej archiwum ZIP.', 'de' => 'Erstellen Sie ein Backup und laden Sie das ZIP-Archiv unten herunter.'],
            'step2' => ['uk' => 'Завантажте архів у кореневу папку нового сайту та розпакуйте його.', 'en' => 'Upload the archive to the new website root folder and extract it.', 'pl' => 'Prześlij archiwum do katalogu głównego nowej witryny i rozpakuj je.', 'de' => 'Laden Sie das Archiv in das Stammverzeichnis der neuen Website hoch und entpacken Sie es.'],
            'step3' => ['uk' => 'Відкрийте https://ваш-домен/installer.php.', 'en' => 'Open https://your-domain/installer.php.', 'pl' => 'Otwórz https://twoja-domena/installer.php.', 'de' => 'Öffnen Sie https://ihre-domain/installer.php.'],
            'step4' => ['uk' => 'Заповніть параметри бази даних, перевірте підключення та завершіть встановлення.', 'en' => 'Enter database details, test the connection, and complete the restore.', 'pl' => 'Wprowadź dane bazy, sprawdź połączenie i zakończ przywracanie.', 'de' => 'Geben Sie die Datenbankdaten ein, testen Sie die Verbindung und schließen Sie die Wiederherstellung ab.'],
            'installer_note' => ['uk' => 'Інсталятор автоматично перевіряє службові дані всередині ZIP-архіву. Після успішного перенесення він сам видалить службові файли.', 'en' => 'The installer automatically verifies the archive data and removes its temporary files after a successful restore.', 'pl' => 'Instalator automatycznie weryfikuje dane archiwum i usuwa pliki tymczasowe po pomyślnym przywróceniu.', 'de' => 'Das Installationsprogramm überprüft die Archivdaten automatisch und entfernt temporäre Dateien nach erfolgreicher Wiederherstellung.'],
            'backups_title' => ['uk' => 'Резервні копії', 'en' => 'Backups', 'pl' => 'Kopie zapasowe', 'de' => 'Backups'],
            'archive' => ['uk' => 'Архів', 'en' => 'Archive', 'pl' => 'Archiwum', 'de' => 'Archiv'],
            'date' => ['uk' => 'Дата', 'en' => 'Date', 'pl' => 'Data', 'de' => 'Datum'],
            'size' => ['uk' => 'Розмір', 'en' => 'Size', 'pl' => 'Rozmiar', 'de' => 'Größe'],
            'actions' => ['uk' => 'Дії', 'en' => 'Actions', 'pl' => 'Działania', 'de' => 'Aktionen'],
            'no_backups' => ['uk' => 'Резервних копій ще немає.', 'en' => 'No backups yet.', 'pl' => 'Brak kopii zapasowych.', 'de' => 'Noch keine Backups vorhanden.'],
            'download' => ['uk' => 'Скачати', 'en' => 'Download', 'pl' => 'Pobierz', 'de' => 'Herunterladen'],
            'delete' => ['uk' => 'Видалити', 'en' => 'Delete', 'pl' => 'Usuń', 'de' => 'Löschen'],
            'confirm_delete' => ['uk' => 'Видалити архів?', 'en' => 'Delete this archive?', 'pl' => 'Usunąć to archiwum?', 'de' => 'Dieses Archiv löschen?'],
            'delete_done' => ['uk' => 'Архів видалено.', 'en' => 'Archive deleted.', 'pl' => 'Archiwum usunięte.', 'de' => 'Archiv gelöscht.'],
            'delete_error' => ['uk' => 'Не вдалося видалити архів.', 'en' => 'Could not delete the archive.', 'pl' => 'Nie można usunąć archiwum.', 'de' => 'Das Archiv konnte nicht gelöscht werden.'],
            'permission_error' => ['uk' => 'Недостатньо прав.', 'en' => 'Insufficient permissions.', 'pl' => 'Brak uprawnień.', 'de' => 'Unzureichende Berechtigungen.'],
            'file_missing' => ['uk' => 'Файл не знайдено.', 'en' => 'File not found.', 'pl' => 'Nie znaleziono pliku.', 'de' => 'Datei nicht gefunden.'],
        ];
        $language = self::language();
        return isset($strings[$key][$language]) ? $strings[$key][$language] : ($strings[$key]['en'] ?? $key);
    }
}
