# Regeln

Die Regeln eines Typs sind die Gruppe `[Type_<label>]` der `statichtmlimport.ini`. Mit `RulesFormat=yaml` und
`RulesFile=<Datei>`, oder `RulesFormat=php` und `RulesClass=<Klasse, die expStaticImportRulesProvider umsetzt>`, kommen
sie stattdessen aus dieser Datei oder Klasse; was Datei oder Klasse offenlassen, kommt aus der Gruppe. Die vollständige
Liste, mit einer Zeile zu jeder Regel, steht im Kommentar am Anfang der `settings/statichtmlimport.ini.append.php` der
Erweiterung.

- **Quelle und Ort:** `SourceRepository`, `SourceBranch`, `SourceLocation`, `ParentNode` oder `ParentRemoteID`,
  `MediaParentNode` oder `MediaParentRemoteID`, `ImportUser`, `Language`, `BatchSize`.
- **Seiten:**
  - welche Dateien: `Suffixes[]`, `IncludePaths[]`, `ExcludePaths[]`, `HiddenPaths[]`, `IndexFiles[]`;
  - welche Klasse: `ClassRules[]` (`<Shell-Muster>;<Klasse>`, der erste Treffer gilt), `DefaultClass`;
  - welches Attribut welches Feld bekommt: `AttributeMap[<Klasse>/<Attribut>]=<Feld>` (`*` für jede Klasse).
- **Felder:**
  - `title`, `short_title`, `body` (das XML des bereinigten Inhalts), `original_html`, `source_path`, `page_id`;
  - `description`, `keywords`, `metadata_xml`, `labels_tags`;
  - die Felder der Autorenzeile: `written_by`, `written_at`, `last_updated_by`, `last_updated_at`, `maintainers`,
    `contributors`;
  - `history_of`, `import_hash`.
- **Den Inhalt bereinigen:** `RemoveSelectors[]`, `UnwrapSelectors[]`, `PanelSelectors[<Typ>]`, `CodeSelectors[]`,
  `LiteralSelectors[]`, `CodeLineSelector`, `CodeLanguagePatterns[]`, `ExpandSelectors[]`, `ChildrenSelectors[]`,
  `EmbedAlignSelectors[<Ausrichtung>]`.
- **Autorenzeile:** `BylineSelectors[]`, `BylineFields[<Feld>]=<Beschriftung>`, `BylineListFields[]`,
  `BylineDateFormats[]`, `BylineTimezone`, `ContributorClass`, `ContributorNameAttribute`, `ContributorParentRemoteID`.
- **Kopien und Weiterleitungen:** `DuplicatePatterns[]`, `DeduplicateAll`, `CopyOfPatterns[]`, `CreateRedirects`,
  `RedirectPrefix`, `NotFoundTitlePatterns[]`.
- **Medien:** `AssetSuffixes[]`, `AssetClassRules[]`, `AssetAttributeMap[<Klasse>/<Attribut>]`.

## Wohin eine Seite kommt

Die Elternseite einer Seite ist die Seite ihres Ordners: `<Ordner>.html` neben dem Ordner, oder die Indexdatei des
Ordners. Manche Exporte sind jedoch flach: Confluence speichert jede Seite eines Bereichs in einem Ordner,
`display/<BEREICH>/<Seite>.html`, und nur die Brotkrumennavigation sagt, wohin eine Seite gehört.

- `BreadcrumbSelector` (z. B. `#breadcrumbs a`) liest die Brotkrumen der Seiten, die zu `BreadcrumbPaths[]` passen. Der
  nächste Link, der eine Seite des Imports ist, ist die Elternseite. Brotkrumen, die im Kreis führen, werden nicht
  befolgt.
- `GroupFolders[]` (Shell-Muster von Ordnern, z. B. `display` und `display/*`) gibt jedem passenden Ordner ohne eigene
  Seite eine Abschnittsseite, die Elternseite der Seiten des Ordners ohne andere Elternseite. Ihr Titel ist
  `GroupTitles[<Ordner>]`, sonst der Text von `GroupTitleSelector` (der erste Eintrag der Brotkrumen, der Name des
  Bereichs) in der ersten Seite des Ordners, sonst der Name des Ordners. `GroupClass` ist ihre Klasse, `GroupBody` ein
  optionaler Text (`{title}` ist der Titel). Ihr Pfad ist der Ordner mit Schrägstrich (`display/EZP/`), und die alte
  Adresse des Ordners leitet auf sie weiter.
- Jede Seite wird nach ihrer Elternseite importiert. Ein Lauf weniger Pfade (`--path`) kennt trotzdem jede Seite der
  Quelle, eine Elternseite außerhalb des Laufs bleibt also die Elternseite.
- Eine Seite, die ein früherer Lauf oder eine frühere Regel unter den falschen Knoten gelegt hat, wird beim nächsten
  Lauf verschoben.

## Die Spitze des Baums, Produkte und feste Elternseiten

- `TopParentRules[]=<Shell-Muster>;<Elternpfad>`: wohin eine Seite kommt, die eine oberste Seite wäre. Der erste
  Treffer gilt; ein leerer Elternpfad lässt sie oben. Eine letzte Zeile `**;<Seite>` hält die Spitze klein: nur die
  davor genannten Seiten bleiben oben.
- `ParentRules[]=<Shell-Muster>;<Elternpfad>`: eine feste Elternseite vor jeder anderen Regel (ein Bereich unter seinem
  Produkt).
- `VirtualPages[<Pfad>/]=<Klasse>;<Titel>;<Kurztitel>;<HTML-Datei>`: eine Abschnittsseite, die die Quelle nicht hat,
  etwa die Einstiegsseite eines Produkts; der Kurztitel ist ihre Adresse, die Datei (unterhalb des Wurzelverzeichnisses
  der Installation) ihr Text. Sie gibt auch einer Gruppenseite neue Werte.
- `RetiredParent`: wohin `route --all` die verborgenen Reste früherer Läufe an der Spitze legt.

## Was keine Seite ist

- `NotFoundTitlePatterns[]`: reguläre Ausdrücke auf den `<title>` der Fehlerseiten des Exports (`/^404\b/`,
  `/^kernel \(\d+\) \/ Error\b/`).
- `SkipBinaryPages` (standardmäßig eingeschaltet): eine Datei mit Seitenendung, die kein HTML enthält, etwa eine
  Schrift oder eine gzip-Datei, die der Export als `.html` gespeichert hat.
- `AssetPages` (standardmäßig ausgeschaltet): eine Seite, deren Inhalt ein einziges Bild oder ein einziger Link auf eine
  Mediendatei ist, ohne eigenen Text (höchstens `AssetPageMaxText` Zeichen, Standard 0), ist die Ansicht dieser Datei.
  Die Datei wird als Medium importiert, und der Pfad der Ansicht leitet auf sie weiter. `AssetPagePaths[]` nennt Seiten,
  die immer solche Ansichten sind.
- Eine Seite, die ein früherer Lauf importiert hat und die nach diesen Regeln keine Seite ist, wird beim nächsten Lauf
  verborgen (mit `--apply-removals` in den Papierkorb gelegt). `./console exp:static-import:hide-non-pages
  --type=<label>` verbirgt sie sofort, ohne Lauf.

## Titel, Überschriften und Links

- `TitleSelectorScope=document`: jede Alternative von `TitleSelector` in ihrer Reihenfolge, in der ganzen Seite; so
  gewinnt eine Überschrift außerhalb des Inhalts (Confluence: `#title-text`) vor der ersten Überschrift des Textes.
- `NormalizeHeadings` (standardmäßig ausgeschaltet): die höchste Überschrift des Textes wird zur ersten Ebene.
- `CopyOfPatterns[]`: reguläre Ausdrücke auf den Pfad, deren erste Gruppe die Adresse der kopierten Seite ist (ein
  Ansichtsparameter wie `.../(language)/eng-GB.html`): immer eine Weiterleitung auf diese Seite.
- Links: eine Adresse ohne Endung führt auf ihre Seite, ein Link mit `pageId=` auf die Seite mit dieser Seiten-ID, eine
  vollständige Adresse eines Hosts aus `LinkHosts[]` auf ihren Pfad.

## Autorenzeilen: wer eine Seite geschrieben hat, und jede Person, die sie nennt

- Beschriftete Zeilen (`BylineSelectors[]`, `BylineFields[<Feld>]=<Beschriftung>`, `BylineListFields[]`): „Written by:
  A (14/09/2010 10:14 am)", „Last updated by: B (...)", „Maintainers: C, D".
- Sätze (`BylineSentenceSelectors[]`, überall in der Seite, und `BylineSentences[]`): reguläre Ausdrücke mit den
  benannten Gruppen `written`, `written_at`, `updated` und `updated_at`, der Reihe nach versucht, etwa Confluences
  „Created by A, last modified by B on Oct 28, 2016". Ein Satz mit dem Datum der letzten Änderung, aber ohne eigenen
  Namen, schreibt die Änderung dem Verfasser zu. Ein Datumslink, dessen `title` ein genaueres Datum ist, zählt mit
  diesem. Ein relatives Datum („yesterday at 7:42 PM") zählt ab dem Tag, an dem der Export die Seite gespeichert hat
  (`BylineSavedAtPattern`).
- Kommentarautoren (`BylineCommentAuthorSelectors[]`): das Listenfeld `commenters`.
- Das Feld `contributors` enthält jede Person jedes Felds der Autorenzeile, jede einmal (Namen ohne Groß- und
  Kleinschreibung und überzählige Leerzeichen verglichen): einer Objektbeziehungsliste zuordnen, z. B.
  `AttributeMap[*/contributors]=contributors`.
- Text einer Autorenzeile, den keine Regel liest, wird pro Seite gemeldet (`byline_failures`), nie verworfen.
- `./console exp:static-import:bylines --type=<label>` ist die Prüfung auf Datenverlust: jede Person und jedes Datum der
  Autorenzeilen der Quelle wird in den gespeicherten Attributen gesucht, pro Seite und insgesamt, mit den unbekannten
  Texten. `--fill` schreibt das Fehlende in die veröffentlichten Versionen (ein Rollenattribut nur, wenn es leer ist,
  die Liste der Mitwirkenden vollständig) samt Objektbeziehungen; `--replace-roles` ersetzt dabei Personen, die die
  Quelle nicht nennt (Seiten, die jemand im CMS bearbeitet hat, bleiben unberührt).

## Kommentare

`CommentsSelector` (`#comments-content` der alten Handbuchseiten) und `ConfluenceCommentsSelector` (`#page-comments`
von Confluence) lesen die Kommentare der Leser; jeder wird eine Seite der Klasse `CommentClass` unter seiner Seite, eine
Antwort unter dem Kommentar, auf den sie antwortet, mit Autor (als Mitwirkender und wie geschrieben), Datum, ID, der ID
des beantworteten Kommentars, Adresse und eigenem Markup. `ClassChange=replace` (der Standard) verbirgt ein Objekt,
dessen Klasse die Regeln geändert haben, und legt ein neues an. `CommentMarkers[]` nennen den Text, an dem eine Seite
mit Kommentaren erkennbar ist, für `relink --comments`. `./console exp:static-import:comments --type=<label>` prüft
jeden Kommentar der Quelle gegen das gespeicherte Objekt.

## Git-Autoren

`GitAuthors=enabled` hält den Verlauf von `SourceRepository` vor (ein Bare-Klon ohne Dateiinhalte, `history.git` im
Arbeitsordner). Für die Seiten aus `GitAuthorPaths[]` ist jeder Autor eines Commits ein Mitwirkender; wo die Quelle
niemanden nennt, ist der erste Autor der Verfasser und der letzte der Bearbeiter, mit den Daten der Commits.
`GitAuthorMap[<E-Mail oder Name>]=<Name>` fasst mehrere Identitäten zu einer Person zusammen (gezeigt wird nur der
Name), `GitAuthorSkip[]` lässt Bots aus.

## Routen: eine Familie von Seiten in einem eigenen Container

Manche Exporte enthalten Hunderte Ansichten ihrer Seiten mit jeweils demselben Namen, etwa Confluences „Page History",
„Page Comparison", „Page Information" und alte Versionen. Im Baum wären das Hunderte gleichnamige Geschwister.
`RouteRules[]` schickt eine solche Familie in Container, mit eigenen Namen:

    RouteMeta[page_id]=/name="ajs-page-id" content="(\d+)"/
    RouteRules[]=pages/viewpreviousversions*;historical/page-history/{page_id}/;{page_title}: page history;;{page_id}
    RouteContainers[historical]=exp_doc_historical;Historical
    RouteContainers[historical/page-history/*]=exp_doc_historical;{page_title}

- Eine Regel ist `<Shell-Muster des Pfads>;<Containerpfad>;<Namensmuster>[;<Bedingung>[;<ID der aktuellen Seite>]]`;
  die erste passende gilt.
- Die Muster nehmen `{key}`-Werte: `RouteMeta[<key>]` (ein regulärer Ausdruck auf das HTML der Seite, seine erste
  Gruppe), `{title}` (der `<title>` ohne `TitleSuffixPatterns[]`) und `{path}`; `{a|b}` ist der erste nicht leere Wert.
- Die Bedingung ist `<key>!=<key>` oder `<key>=<key>`, z. B. `latest_id!=page_id` für eine alte Version.
- Die ID der aktuellen Seite füllt das Feld `history_of`: das Objekt der aktuellen Seite mit dieser Seiten-ID (einer
  Objektbeziehung zuordnen, `AttributeMap[*/history_of]=history_of`). Ein Container der Ansichten einer Seite bekommt
  sie ebenfalls.
- `RouteContainers[<Containerpfad>]=<Klasse>;<Titelmuster>` legt die Container an, nach ihren Pfaden verschachtelt;
  `*` steht für einen Ordner beliebigen Namens. Der Titel eines Containers nimmt die Werte seiner ersten Seite.

## Namen von Geschwistern

Keine zwei Seiten gleichen Namens unter einer Elternseite: `SiblingNames=unique` (der Standard) gibt der späteren
`SiblingNameSuffix` (Standard `({n})`, ein Zähler ab 2; er kann `{page_id}` und die Werte von `RouteMeta[]` nutzen und
bekommt zusätzlich den Zähler, wenn auch er vergeben ist), `refuse` überspringt die Seite (fehlgeschlagen, „muss
geprüft werden"), `warn` meldet sie nur, `off` schaltet die Prüfung aus. Jede Umbenennung steht in den Warnungen des
Berichts und wird als `renamed` gezählt.

## Konsolenschritte neben einem Lauf

Sie schreiben nie die Statusdatei (ein laufender Import würde sie überschreiben): was der nächste Lauf wissen muss,
kommt in einen Status-Patch in `state-patches/` des Arbeitsordners, den dieser Lauf als Erstes übernimmt.

| Befehl | Was er tut |
|---|---|
| `exp:static-import:route --type=<label> [--all]` | geroutete Seiten in ihre Container; mit `--all` jede Seite unter die Elternseite, die ihr die Regeln heute geben |
| `exp:static-import:relink --type=<label> [--comments]` | Seiten, deren statische Links sich jetzt auflösen lassen, oder jede Seite mit Kommentaren, erneut importiert |
| `exp:static-import:hide-non-pages --type=<label>` | verbirgt importierte Seiten, die heute keine Seiten sind (Fehlerseiten, Binärdateien, Ansichten, Kopien, ausgeschlossene) |
| `exp:static-import:bylines --type=<label> [--fill [--replace-roles]]` | die Prüfung der Autorenzeilen auf Datenverlust, und das Auffüllen |
| `exp:static-import:comments --type=<label>` | die Prüfung der Kommentare auf Datenverlust |
