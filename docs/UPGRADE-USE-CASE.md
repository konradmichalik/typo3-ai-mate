# Use Case: TYPO3-Upgrades mit `typo3_ai_mate`

> Plan-Dokument. Beschreibt, wie die Extension den Anwendungsfall „TYPO3 Major-Upgrade
> (v13 → v14)" für einen AI-Assistenten nutzbar macht. Status: **Konzept / noch nicht
> implementiert.** Technische Behauptungen gegen `typo3/cms-core` + `typo3/cms-install`
> (v13.4) in `vendor/` verifiziert.

## 1. Idee

Ein Major-Upgrade besteht aus drei wiederkehrenden Fragen, die der Assistent heute nur
durch Raten aus Quellcode + Doku beantworten kann:

1. **Welche DB-/Config-Migrationen stehen aus?** → Upgrade Wizards
2. **Welcher eigene Code ist mit der Zielversion inkompatibel?** → Extension Scanner
3. **Was hat die Installation zur Laufzeit bereits als veraltet gemeldet?** → Deprecation-Logs

Das ist exakt die Kombination, die das Install-Tool-Upgrade-Modul im Backend abdeckt.
`typo3_ai_mate` kann dieselben Fakten **headless über MCP** bereitstellen, sodass der
Assistent aus dem realen Zustand der Installation argumentiert statt aus dem Core-Changelog
zu raten.

Das passt zur Kern-Philosophie der Extension: *resolved runtime state statt Quellcode raten.*

## 2. Architektur-Muster (Wiederholung)

Jedes Tool folgt dem etablierten Rezept (siehe `INSTRUCTIONS.md`):

```
Console Command (bootet IN TYPO3, druckt rohes JSON)
        ▲  shell-out via Typo3CliRunner (TYPO3_CONTEXT=Development)
        │
#[McpTool]-Klasse (läuft im Mate-Prozess, parst stdout-JSON)
        │  registriert in
Configuration/Mate.php  +  dokumentiert in INSTRUCTIONS.md  +  Unit-/Functional-Test
```

**Wichtige Einschränkung vorab:** Die Core-Commands `upgrade:list` / `upgrade:run` geben
eine **Tabelle** (SymfonyStyle) aus, kein JSON, und booten speziell (BootService +
`initializeBackendAuthentication()`). Sie lassen sich **nicht** direkt via
`Typo3CliRunner::json()` wrappen. Beide Upgrade-Tools brauchen daher einen **eigenen
JSON-Command**, der die zugrunde liegenden Core-Services direkt anspricht.

## 3. Die drei Säulen

### Säule 1 — Upgrade Wizards (`typo3-upgrade-wizards`)

**Ziel:** ausstehende und erledigte Upgrade-Wizards mit Identifier, Titel, Beschreibung
und Status auflisten.

**Command** `typo3-ai-mate:upgrade:wizards` (neu, modelliert nach
`TYPO3\CMS\Core\Command\UpgradeWizardListCommand`):

- `BootService` injizieren und `loadExtLocalconfDatabaseAndExtTables(false, false)` +
  `Bootstrap::initializeBackendAuthentication()` ausführen — Wizards brauchen vollständig
  geladene `ext_localconf`/`ext_tables` und einen authentifizierten BE-Kontext.
- `UpgradeWizardsService` ziehen, über `getUpgradeWizardIdentifiers()` iterieren; pro
  Wizard `isWizardDone()`, `getUpgradeWizard()`, `updateNecessary()`.
- **Vereinfachung (verifiziert):** Der Service bietet `getUpgradeWizardsList()` und
  `getWizardInformationByIdentifier()` als High-Level-API — ggf. direkt nutzbar statt der
  Einzelaufrufe.
- **Immer alle** Wizards ausgeben (analog `--all`), Status mitliefern — der Assistent
  filtert selbst.
- Output als rohes JSON.

**McpTool** `UpgradeWizardsTool::list()` → wrappt den Command via `Typo3CliRunner::json()`.

**JSON-Schema:**

```json
{
  "wizards": [
    {
      "identifier": "pagesLanguageOverlayBeGroupsAccessRights",
      "title": "Migrate backend groups…",
      "description": "…",
      "status": "AVAILABLE",        // AVAILABLE | DONE
      "updateNecessary": true
    }
  ]
}
```

**Caveats:** `UpgradeWizardsService` / die Commands sind `@internal`. Bootstrap ist
schwerer als bei den bestehenden Tools (DB-Zugriff nötig). Read-only — `upgrade:run`
wird **bewusst nicht** exponiert (verändert die DB; ein Assistent soll Migrationen nicht
autonom ausführen).

### Säule 2 — Extension Scanner (`typo3-extension-scanner`)

**Ziel:** statische Analyse des eigenen Extension-Codes gegen die im Core hinterlegten
Breaking-/Deprecation-Matcher — also „diese Stellen in *deinem* Code brechen in v14".

**Hintergrund:** Der Scanner hat **keinen** Core-Command (nur Backend-Modul via AJAX),
aber die Scan-Logik in `UpgradeController::extensionScannerScanFileAction()` ist reines
PHP ohne GUI-/AJAX-Abhängigkeit und damit nachbaubar.

**Command** `typo3-ai-mate:upgrade:scan` (neu), Argument `extension=<key>`:

1. **Datei-Liste:** `PackageManager` → Extension-Pfad, dann `Symfony\Finder` über `*.php`
   (1:1 aus `extensionScannerFilesAction()`).
2. **Scan pro Datei** (Pipeline aus `extensionScannerScanFileAction()`):
   ```
   (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2))->parse(...)
     → NodeTraverser + NameResolver            (use → FQCN auflösen, separater Pass!)
     → NodeTraverser + GeneratorClassesResolver + CodeStatistics + alle Matcher
     → pro Matcher getMatches()
   ```
3. **Matcher-Konfiguration:** die `$this->matchers`-Liste aus dem `UpgradeController`
   (~25 Paare `['class' => …Matcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/…']`).
   **Entscheidung offen** (siehe §6): Liste hart kopieren vs. zur Laufzeit aus dem Core lesen.
4. Output als rohes JSON, pro Treffer `message`, `line`, `indicator` (`strong`/`weak`),
   `lineContent`, plus `effectiveCodeLines` / `ignoredLines` aus `CodeStatistics`.

**McpTool** `ExtensionScannerTool::scan(string $extension)`.

**JSON-Schema:**

```json
{
  "extension": "my_ext",
  "statistics": { "effectiveCodeLines": 1234, "ignoredLines": 12, "filesScanned": 47 },
  "matches": [
    {
      "file": "Classes/Controller/FooController.php",
      "line": 88,
      "indicator": "strong",
      "message": "Call to GeneralUtility::…() …",
      "lineContent": "$x = GeneralUtility::…();"
    }
  ]
}
```

**Scope-Entscheidung für v1:** Die ReST-File-Anreicherung (Changelog-Verweise, Versions-
Zuordnung) macht ~die Hälfte des Controller-Codes aus und wird **bewusst weggelassen** —
`message` + `indicator` reichen dem Assistenten. Optional als zweiter Schritt nachrüstbar
(`restFiles[]` im Schema ergänzen).

**Caveats (verifiziert/präzisiert):**
- `ExtensionScanner\*`-Klassen sind core-intern (`@internal`); benötigt installiertes
  `typo3/cms-install` (System-Extension, in Composer-Mode i. d. R. vorhanden).
- **Versions-Drift geringer als gedacht:** Die `configurationFile`-Pfade in der
  `$this->matchers`-Liste sind `EXT:install/...` und werden **zur Laufzeit** aufgelöst —
  die eigentlichen Matcher-Daten (welche APIs deprecated/breaking sind) liest der Core
  also aus der installierten Version. Nur die 24-Zeilen-Mapping-Liste (ConfigFile →
  Matcher-Klasse) wird kopiert; sie ändert sich selten. Functional-Test gegen Fixture
  sichert ab.
- Findet nur, wofür Matcher existieren (dokumentierte Changes mit ReST-File) — **keine**
  Vollständigkeitsgarantie.

### Säule 3 — Deprecations (`typo3-deprecations`)

**Ziel:** zur Laufzeit gemeldete Deprecations dedupliziert und nach Häufigkeit/Komponente
gruppiert — komplementär zur statischen Analyse (Säule 2).

**Status (verifiziert): Infrastruktur vorhanden, Datenquelle aber per Default AUS.**
`LogSearchCommand::logFiles()` globt `var/log/typo3_*.log`; der Deprecation-Logfile heißt
`typo3_deprecations_<hash>.log` (`logFileInfix => 'deprecations'`) und wird vom Glob
**korrekt erfasst** ✓. ABER: In `DefaultConfiguration.php` ist der `deprecations`-Channel
`disabled => true` — **out-of-the-box landet nichts im Log.** Der User muss
`[LOG][TYPO3][CMS][deprecations][writerConfiguration]` erst aktivieren.

Format (verifiziert): Channel `TYPO3.CMS.deprecations`, Level NOTICE, Nachricht beginnt
mit `TYPO3 Deprecation Notice:`. Korrekter Filter ist daher **`component`**, nicht ein
Message-Substring:

```
typo3-logs-search component="TYPO3.CMS.deprecations"
```

(`query="is deprecated"` ist unzuverlässig — die Nachricht enthält den String nicht
garantiert.)

**Mehrwert eines dedizierten Tools:** Eine Deprecation wird pro Request x-fach geloggt.
Ein `typo3-deprecations`-Tool soll:

- nur Deprecation-Einträge zurückgeben (Filter `component="TYPO3.CMS.deprecations"`),
- **deduplizieren** (gleiche Meldung = ein Eintrag),
- nach Häufigkeit **gruppieren** (`count` pro Meldung),
- **erkennen + melden, wenn das Deprecation-Logging deaktiviert ist** (Default-Fall) —
  sonst interpretiert der Assistent ein leeres Ergebnis fälschlich als „keine
  Deprecations",
- damit aus „4000 Logzeilen" → „12 Stellen, die vor v14 angefasst werden müssen".

**Umsetzung:** dünner Filter-/Aggregations-Layer über `LogSearchCommand`. Entweder
neuer Command `typo3-ai-mate:upgrade:deprecations` oder Aggregation im McpTool.

**JSON-Schema:**

```json
{
  "deprecations": [
    { "message": "… is deprecated …", "component": "…", "count": 37, "lastSeen": "…", "exampleRequestId": "…" }
  ]
}
```

**Caveat:** Schwächste der drei Säulen. Per Default deaktiviert (s. o.); liefert nur, was
zur Laufzeit tatsächlich durchlaufen wurde (abhängig von getriggerten Code-Pfaden) →
unvollständig und teils redundant zu Säule 2. Wert nur als **Laufzeit-Ergänzung** zur
statischen Analyse — und nur, wenn aktiviert.

## 4. Betroffene/neue Dateien

```
Classes/Command/UpgradeWizardsCommand.php        (neu)
Classes/Command/ExtensionScanCommand.php         (neu)
Classes/Command/DeprecationsCommand.php          (neu, oder Aggregation im Tool)
Classes/Mcp/UpgradeWizardsTool.php               (neu)
Classes/Mcp/ExtensionScannerTool.php             (neu)
Classes/Mcp/DeprecationsTool.php                 (neu)
Configuration/Mate.php                           (3 Tool-Registrierungen ergänzen)
INSTRUCTIONS.md                                  (Tool-Liste + Upgrade-Workflow-Abschnitt)
README.md                                        ("What it exposes" erweitern)
Tests/Unit/Mcp/McpToolWrappersTest.php           (Wrapper-Tests erweitern)
Tests/Unit/Command/…                             (Command-Logik testen)
Tests/Functional/…                               (Scanner gegen Fixture, falls FT-Setup)
```

`Configuration/Mate.php` — neue Registrierungen analog `EventsTool`:

```php
$services->set(UpgradeWizardsTool::class);
$services->set(ExtensionScannerTool::class);
$services->set(DeprecationsTool::class);
```

## 5. Vorgehen (Phasen)

**Wert-Einordnung (ehrlich):** Säule 2 (Extension Scanner) ist der größte Hebel —
vollständige statische Analyse, läuft sofort, headless. Säule 1 (Wizards) ist solide.
Säule 3 (Deprecations) ist die schwächste (Default aus, unvollständig, redundant zu 2).

Empfohlene Reihenfolge — **nach Wert, nicht nach Aufwand**:

1. **Säule 2 (Extension Scanner)** — größter Aufwand (Controller-Logik nachbauen,
   Matcher-Mapping kopieren, Functional-Test), aber der größte Mehrwert. Zuerst.
2. **Säule 1 (Upgrade Wizards)** — mittlerer Aufwand, eigener Command mit BootService-
   Bootstrap, klar abgegrenzte Core-API (`UpgradeWizardsService`).
3. **Säule 3 (Deprecations)** — geringster Aufwand (dünner Layer über `LogSearchCommand`),
   aber geringster Wert; optional, zuletzt.

Jede Säule ist eigenständig auslieferbar (TDD: Test zuerst, dann Command, dann Tool).

## 6. Offene Entscheidungen

- **Matcher-Konfiguration (Säule 2):** Core-Liste hart kopieren (stabil, aber driftet
  zwischen Versionen) **vs.** zur Laufzeit aus `EXT:install/Configuration/ExtensionScanner/Php/`
  lesen (versionsrobust, etwas mehr Code). *Empfehlung: zur Laufzeit lesen.*
- **JSON-Output der Upgrade-Commands:** eigener Command (empfohlen) vs. Output von
  `upgrade:list` parsen (fragil, Tabelle). → eigener Command.
- **Deprecations:** eigener Command vs. Aggregation im McpTool. → vermutlich Command,
  damit die Dedup-Logik testbar in TYPO3 sitzt.
- **`upgrade:run` exponieren?** → **Nein** (verändernde Aktion; Assistent soll nicht
  autonom migrieren). Nur lesende Tools.

## 7. Einordnung

Drei Säulen = die drei Bausteine, die auch das Backend-Upgrade-Modul kombiniert:

| Tool                      | Quelle              | Art      | Frage                                  |
|---------------------------|---------------------|----------|----------------------------------------|
| `typo3-upgrade-wizards`   | UpgradeWizardsService | DB/Config | Welche Migrationen stehen aus?         |
| `typo3-extension-scanner` | ExtensionScanner    | statisch | Welcher Code bricht in der Zielversion?|
| `typo3-deprecations`      | Logs (Laufzeit)     | Laufzeit | Was wurde real als veraltet gemeldet?  |
