# AGENTS.md

> Allgemeine WordPress-Sicherheits-/Coding-Regeln (Escaping, Nonces, WPCS, i18n, Hooks-only) sind in
> [`.github/instructions/wordpress.instructions.md`](.github/instructions/wordpress.instructions.md) definiert und
> greifen automatisch für alle Dateien in diesem Plugin (`applyTo: wp-content/plugins/**`). Dieses Dokument
> ergänzt sie um projektspezifisches Architektur- und Workflow-Wissen.

## Zweck & Architektur
Dieses WordPress-Plugin ergänzt **W3 Total Cache (W3TC)**: Sobald W3TC ImageService ein Bild erfolgreich zu WebP
konvertiert hat, schreibt dieses Plugin die Umstellung **dauerhaft in die Datenbank**, statt sie nur zur Laufzeit
per Content-Filter vorzutäuschen (das macht das Schwester-Plugin `sev-rewrite-free-webp-for-w3tc`).

- `sev-replace-webp-for-w3tc.php` – Bootstrap. Hookt sich nur ein, wenn `defined('W3TC')` (Laufzeit-Check statt
  `Requires Plugins`, wichtig für mu-plugins) via `plugins_loaded`.
- `includes/class-attachment-urls.php` – `Attachment_Urls`: baut aus `wp_get_attachment_metadata()` die Liste aller
  Dateien eines Attachments (Originalgröße + alle registrierten Zwischengrößen) und paart jede mit ihrer
  `.webp`-Variante – einmal als URL (`url_pairs()`), einmal als Dateisystempfad (`path_pairs()`).
- `includes/class-content-replacer.php` – `Content_Replacer::replace()`: sucht per `$wpdb`-LIKE-Query gezielt nach
  Posts, die eine alte URL enthalten, und ersetzt sie direkt in `post_content` (kein Hook auf `the_content`, echte
  DB-Schreiboperation).
- `includes/class-attachment-migrator.php` – `Attachment_Migrator::migrate()`: aktualisiert das Attachment selbst
  (`_wp_attached_file`, `_wp_attachment_metadata`, `post_mime_type`), damit Mediathek/REST-API konsistent bleiben.
- `includes/class-source-cleaner.php` – `Source_Cleaner::delete_originals()`: löscht die alten Dateien nur, wenn
  das `.webp`-Gegenstück nachweislich existiert und der Pfad innerhalb des Uploads-Verzeichnisses liegt.
- `includes/class-processor.php` – `Processor::process()`: orchestriert obige Klassen für ein Attachment in der
  Reihenfolge Content ersetzen → Attachment migrieren → optional Quelldateien löschen. `already_processed()` prüft
  `post_mime_type === 'image/webp'` als Idempotenz-Marker (kein zusätzliches Postmeta nötig).
- `includes/class-conversion-listener.php` – hookt `added_post_meta`/`updated_post_meta`, reagiert nur auf
  `meta_key === 'w3tc_imageservice'` mit `status === 'converted'` und ruft dann `Processor::process()` auf.
- `includes/class-admin-settings.php` – Einstellungsseite unter **Settings → Replace WebP for W3TC**: Checkbox
  „Quellbilder löschen“ (Option `sevrwfw3tc_delete_originals`, Default aus) sowie ein manueller Batch-Trigger
  (`admin-post.php?action=sevrwfw3tc_process_batch`) für Bilder, die W3TC vor Plugin-Aktivierung konvertiert hat.

**Datenfluss:** W3TC konvertiert ein Bild → Postmeta-Hook feuert → `Processor` ersetzt URLs in allen Posts →
migriert das Attachment → löscht optional die Originaldateien.

**Wichtig:** Das Plugin konvertiert keine Bilder selbst – es reagiert ausschließlich auf bereits von W3TC erzeugte
`.webp`-Dateien. Der Ersetzungsschritt in Posts ist eine einmalige, permanente DB-Schreiboperation; das Löschen der
Quellbilder ist standardmäßig deaktiviert und unwiderruflich.

## Namespace & Konventionen
- Alle Klassen liegen im Namespace `SevReplaceWebPForW3TC` (kein globaler Namespace, keine Prefixe nötig).
- Jede Datei beginnt mit `if ( ! defined( 'ABSPATH' ) ) { die(); }`.
- Strikte Typisierung, Scalar-Type-Hints und Return-Types überall in `includes/`.
- Reihenfolge in `Processor::process()` ist absichtlich fix: `path_pairs()` **muss** vor `migrate()` erfasst werden,
  da `get_attached_file()` nach der Migration bereits den neuen `.webp`-Pfad zurückgibt.
- Löschoperationen (`Source_Cleaner`) prüfen immer zuerst `file_exists()` auf die `.webp`-Zieldatei und die
  Uploads-Verzeichnis-Zugehörigkeit, bevor eine Originaldatei angefasst wird.

## Tests (kein WP-Testsuite/wp-env!)
- `tests/bootstrap.php` definiert eigene, minimale Stubs für WP-Funktionen (nach demselben Muster wie im
  Schwester-Plugin `sev-rewrite-free-webp-for-w3tc`), lädt dann das echte Plugin und feuert `plugins_loaded`.
- Getestet wird primär reine Logik ohne Dateisystem-/DB-Interaktion: `Attachment_Urls` (URL-/Pfad-Paarbildung,
  Endungs-Erkennung) sowie `Content_Replacer` über einen minimalen In-Memory-`$wpdb`-Stub.
- `Attachment_Migrator` und `Source_Cleaner` greifen auf echtes Dateisystem/`$wpdb` zu und werden **nicht** durch
  Unit-Tests abgedeckt – bei Änderungen dort manuell gegen eine echte WP-Installation mit W3TC testen.
- Ausführen: `composer test` bzw. `vendor/bin/phpunit` (kein Docker/wp-env erforderlich).

## Weitere Dev-Workflows
- `composer lint:php` / `composer fix:php` – PHPCS/PHPCBF (WPCS).
- `composer make-pot` / `update-po` / `make-php` – i18n-Workflow via WP-CLI (`wp i18n ...`), Text-Domain
  `sev-replace-webp-for-w3tc`, Sprachdateien in `languages/`.
- Keine Build-Pipeline für JS/CSS – das Plugin enthält keine Assets.
- `uninstall.php` entfernt nur die Plugin-Option `sevrwfw3tc_delete_originals`. Bereits ersetzte Posts/Attachments
  bleiben bewusst unverändert (die Umstellung ist dauerhaft und unabhängig vom Plugin-Status).

## Beim Ändern von Code beachten
- Neue Hooks/Filter nur im `plugins_loaded`-Guard in `sev-replace-webp-for-w3tc.php` registrieren, damit sie
  inaktiv bleiben, wenn W3TC fehlt.
- Änderungen an `Attachment_Urls` (Endungs-Regex, Größen-Handling) immer mit Tests in
  `tests/AttachmentUrlsTest.php` absichern.
- Jede neue destruktive Operation (Datei-/DB-Löschung) muss vor der Ausführung prüfen, dass das Ziel der Operation
  bereits erfolgreich migriert wurde – niemals Originaldateien löschen, bevor der Ersatz bestätigt auf der Platte
  liegt.
