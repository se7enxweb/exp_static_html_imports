# Eine statische Website importieren

1. **Den Typ wählen** (`./console exp:static-import:types`):
   - `--html-generic` für einen Ordner mit HTML-Dateien;
   - `--markdown` für Markdown-Dateien;
   - `--sitemap-crawl` für eine laufende Website;
   - einen eigenen Typ.
2. **Seine Regeln festlegen** in `settings/override/statichtmlimport.ini.append.php`, in der Gruppe `[Type_<label>]`:
   - die Quelle: `SourceLocation`, oder `SourceRepository` für ein Git-Repository, das der Import klont und aktualisiert;
   - den Ort: `ParentNode` oder `ParentRemoteID`;
   - die Klassen: `ClassRules[]`, `DefaultClass` und `AttributeMap[]`.

   Siehe [Regeln](rules.md).
3. **Erst ansehen:** `./console exp:static-import:run --<label> --dry-run`. Der Probelauf zählt, was angelegt,
   aktualisiert, markiert und entfernt würde, und schreibt einen Bericht, ohne etwas zu ändern.
4. **Importieren:** `./console exp:static-import:run --<label>`.
   - Ein langer Import läuft in Stapeln und speichert nach jedem Stapel seine Position; derselbe Befehl setzt ihn fort.
   - `--path=<Muster>` und `--limit=<n>` importieren zuerst einen Teil.
5. **Prüfen:** `./console exp:static-import:status`, oder Einrichtung > Import statischer Websites in der
   Administration. Dort startet ein Lauf auch als Hintergrundauftrag, und der Bericht jedes Laufs ist einen Klick entfernt.
