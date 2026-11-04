# Einen Importtyp schreiben

1. **Eine Klasse schreiben**, die `expStaticImportTypeInterface` umsetzt, meist als Erweiterung von
   `expStaticImportHtmlType`. Ein Typ beantwortet fünf Fragen:
   - `discover()`: welche Pfade Seiten sind, Eltern zuerst;
   - `parse()`: das Seiten-Array (title, body_html, original_html, meta, hidden ...), oder `null` für keine Seite;
   - `map()`: Klasse, parent_path und Attribute;
   - `assets()`: die Medien, die die Seite verwendet;
   - `links()`: ihre Links auf andere Seiten.
2. **Sie registrieren** in der `statichtmlimport.ini.append.php` einer Erweiterung:

   ```ini
   [ImportTypes]
   TypeList[my-site]=myImportType
   ```

3. **Ihr eine Gruppe geben** `[Type_my-site]` mit ihren Regeln, dann `./console exp:static-import:run --my-site --dry-run`
   ausführen.

Den Rest erledigt die Engine: Stapel, die Statusdatei, Remote-IDs, Aktualisierungen, Erkennung von Änderungen,
Entfernungen, Weiterleitungen, Medien, Links, Mitwirkende und Berichte. Die Tests in `tests/` zeigen einen Typ bei der
Arbeit an einer Beispielsite, ohne Datenbank; `tests/integration/` importiert sie wirklich, in eine Wegwerfkopie einer
SQLite-Datenbank.
