# Die Importtypen

| Typ | Quelle | Was er liest |
|---|---|---|
| `--html-generic` | ein Ordner mit `.html`-Dateien | Titel, Inhalt und Metadaten über CSS-Selektoren (`TitleSelector`, `BodySelector`, `MetaSelectors[]`); die Ordner ergeben den Baum |
| `--doc-html` | der statische Export der Exponential-Dokumentation | alles, was `--html-generic` liest, dazu die umbenannten Versionsseiten des Exports (`FolderPagePatterns[]`), seine Weiterleitungsseiten und Fehlerseiten, die Kommentare der Leser (die alten Handbuchseiten und Confluence) und die Autorenzeilen von Confluence |
| `--markdown` | ein Ordner mit `.md`-Dateien | Front Matter (Titel, Reihenfolge, Schlüsselwörter, Zusammenfassung), das Markdown des Hilfecenters, `README.md` und `index.md` als Seite eines Ordners |
| `--sitemap-crawl` | die Basisadresse einer laufenden Website | die Seiten ihrer `sitemap.xml` oder von `Urls[]`, höflich abgerufen: robots.txt des Hosts wird beachtet, eine Anfrage pro Sekunde (`RequestInterval`), ein eigener `UserAgent`, ein Spiegel, damit jede Seite nur einmal abgerufen wird |

Jeder Typ nutzt dieselbe Engine: Remote-IDs aus den Quellpfaden, Aktualisierungen, Erkennung von Änderungen im CMS,
Entfernungen, Weiterleitungen alter Pfade und Kopien, Medien als Objekte, Knotenlinks, Autorenzeilen und Berichte.
