# Einen Import erneut ausführen

Ein Import kann jederzeit erneut laufen; so bleibt eine Website mit ihrer Quelle auf dem gleichen Stand.

- **Identität.** Jede Seite ist das Objekt mit der Remote-ID `staticimport-<Typ>-<md5 ihres Quellpfads>`, wohin auch
  immer es verschoben wurde.
- **Geänderte Quelle.** Die Seite wird aktualisiert: eine neue Version, veröffentlicht.
- **Unveränderte Quelle.** Nichts wird geschrieben.
- **Im CMS bearbeitet.** Der Import merkt sich einen Hash dessen, was er geschrieben hat. Hat das Objekt diesen Inhalt
  nicht mehr, wird die Seite markiert und in Ruhe gelassen. `--overwrite-flagged` schreibt sie trotzdem, und die
  Änderung geht verloren.
- **Nicht mehr in der Quelle.** Der Knoten wird verborgen und gemeldet. `--apply-removals` legt ihn stattdessen in den
  Papierkorb.
- **Kopien.** Eine Seite mit demselben Inhalt wie eine bereits importierte (`DuplicatePatterns[]`), eine Kopie unter
  einem Ansichtsparameter (`CopyOfPatterns[]`) und eine Weiterleitungsseite werden zu Weiterleitungen auf diese Seite;
  Fehlerseiten werden übersprungen.
- **Alte Pfade.** Der Quellpfad jeder Seite, mit und ohne `.html`, leitet auf ihren Knoten weiter.
- **Falscher Ort.** Eine Seite, die ein früherer Lauf oder eine frühere Regel unter den falschen Knoten gelegt hat,
  wird beim nächsten Lauf verschoben.
