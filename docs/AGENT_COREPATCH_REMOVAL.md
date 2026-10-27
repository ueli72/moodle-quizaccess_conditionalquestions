# Agenten-Anweisung: Core-Patch entfernen und auf quizaccess_conditionalquestions migrieren

Diese Anweisung ist für einen Agenten, der auf einer **Online-Installation** den
alten `local_conditional_questions`-Core-Patch sauber entfernt und zugleich auf
das Nachfolge-Plugin `quizaccess_conditionalquestions` migriert.

**Kurzfassung:** Am einfachsten ist es, das Repo zu klonen und `install.sh`
laufen zu lassen – das erledigt alle Schritte automatisch und ist re-launch
sicher (idempotent). Die manuellen Schritte stehen darunter, falls das Script
nicht verwendet werden kann oder etwas schiefgeht.

---

## Hintergrund

Das Vorgänger-Plugin `local_conditional_questions` hat per Core-Patch
(`core_patch.patch`, angewandt mit `apply_core_patch.php`) **22 Dateien unter
`mod/quiz/`** geändert und die Version von `mod_quiz` in der Datenbank erhöht.
Das Nachfolge-Plugin braucht den Patch **nicht mehr** und migriert die Daten
beim Upgrade automatisch.

Die Reihenfolge der Schritte ist **wichtig**: Erst Patch reverten und
`mod_quiz`-Version zurücksetzen, dann erst das Upgrade laufen lassen (dadurch
installiert sich das neue Plugin und übernimmt die alten Regeln/attempt-Daten),
erst danach das alte Plugin deinstallieren.

## Annahmen

- Moodle-Installation als **Git-Checkout** mit `public/`-Layout: Das Webroot
  (mit `config.php`) liegt in `<root>/public/`, das Git-Repository-Root ist
  `<root>`. Wenn das Setup abweicht (kein Git, Standard-Layout ohne `public/`),
  sind die Stellen mit **[ABWEICHUNG]** markiert.

---

## Schnellweg: install.sh

```bash
# 1. Repo klonen (beliebiger Zielordner, z. B. /tmp/cq)
git clone https://github.com/ueli72/moodle-quizaccess_conditionalquestions.git /tmp/cq

# 2. Trockenlauf – zeigt nur, was passieren würde:
bash /tmp/cq/install.sh --webroot /pfad/zu/moodle/public --dry-run

# 3. Echte Ausführung (fragt nach; Backup inklusive):
bash /tmp/cq/install.sh --webroot /pfad/zu/moodle/public
```

Danach zwingend die **Verifikation** (unten, Schritt 7) durchführen.
Wenn `install.sh` scheitert, die Schritte in der Fehlermeldung bzw. hier
manuell durchführen.

---

## Manueller Weg (Schritt für Schritt)

In allen Beispielen: `$ROOT` = Moodle-Repository-Root, `$PUBLIC` = Webroot
(`$ROOT/public`), `$DBPREFIX` = Tabellenpräfix (Standard `mdl_`).

### 1. Befundaufnahme (nur lesen)

```bash
cd $ROOT
git status --porcelain public/mod/quiz          # viele Änderungen/Neue Dateien? -> Patch vermutlich angewandt
ls public/mod/quiz/classes/hook/ 2>/dev/null    # 7 Hook-Klassen vorhanden? -> Patch angewandt
ls public/local/conditional_questions 2>/dev/null

# Versionen in der DB (Prefix anpassen):
mysql -u <user> -p <dbname> -e \
  "SELECT plugin, value FROM ${DBPREFIX}config_plugins
   WHERE name='version'
     AND plugin IN ('mod_quiz','local_conditional_questions','quizaccess_conditionalquestions');"
```

Interpretation:
- `mod_quiz` > `2026042000` → Core-Patch hat die Version erhöht, muss
  zurückgesetzt werden.
- `local_conditional_questions` vorhanden → altes Plugin ist installiert.
- Patch-Marker prüfen (alle drei müssen zutreffen, wenn der Patch angewandt ist):

```bash
ls public/mod/quiz/classes/hook/edit_menu_actions.php && \
grep -Fq 'mod_quiz\hook\edit_menu_actions' public/mod/quiz/classes/output/edit_renderer.php && \
grep -Fq 'before_attempt_questions_created' public/mod/quiz/locallib.php && \
echo "PATCH ANGEWANDT"
```

### 2. Wartungsmodus einschalten

```bash
cd $PUBLIC
php admin/cli/maintenance.php --enable
```

### 3. Backup

```bash
mkdir -p /root/cq-migration-backup
# Datenbank (Zugangsdaten stehen in $PUBLIC/config.php):
mysqldump --host=<host> --user=<user> -p --single-transaction <dbname> \
  > /root/cq-migration-backup/db-$(date +%F-%H%M).sql
# Code (mindestens mod/quiz + altes Plugin):
tar czf /root/cq-migration-backup/code-$(date +%F-%H%M).tgz -C $PUBLIC mod/quiz local/conditional_questions
```

Zusätzlich empfohlen (falls noch nicht vorhanden): Backup von `moodledata`.

### 4. Neues Plugin besorgen

```bash
git clone https://github.com/ueli72/moodle-quizaccess_conditionalquestions.git /tmp/cq
```

*(Alternative: `install.sh` wie im Schnellweg beschrieben verwenden, dann sind
die Schritte 4–6 automatisch erledigt.)*

### 5. Core-Patch reverten

```bash
cd $ROOT
git apply -R --check /tmp/cq/docs/core_patch.patch   # muss ohne Fehler durchgehen
git apply -R /tmp/cq/docs/core_patch.patch
git status --porcelain public/mod/quiz               # danach: leer
```

Der `--check`-Lauf darf **keine** Fehler melden. Falls er welche meldet,
weichen die Core-Dateien vom Patch ab → **nicht forcen**, stattdessen:
`git diff public/mod/quiz` ansehen und klären, was zusätzlich geändert wurde.

**[ABWEICHUNG] Kein Git-Checkout?** Dann die betroffenen Dateien aus dem
offiziellen Moodle-5.2.x-Paket (exakt die gleiche Nebenversion!) wiederherstellen.
Geänderte Dateien laut Patch (unter `mod/quiz/`):

```
backup/moodle2/backup_quiz_stepslib.php
classes/external.php
classes/grade_calculator.php
classes/hook/after_all_final_grades_recomputed.php
classes/hook/before_attempt_grade_rescaled.php
classes/hook/before_attempt_questions_created.php
classes/hook/before_attempt_saved.php
classes/hook/before_final_grade_calculated.php
classes/hook/edit_menu_actions.php
classes/hook/edit_question_actions.php
classes/local/reports/attempts_report_table.php
classes/output/attempt_summary_information.php
classes/output/edit_renderer.php
classes/output/renderer.php
classes/privacy/provider.php
db/install.xml
db/upgrade.php
locallib.php
report/overview/overview_table.php
report/responses/last_responses_table.php
version.php
view.php
```

Alle `classes/hook/*.php`-Dateien sind neu hinzugekommen und müssen danach
**gelöscht** werden; alle anderen Dateien durch die Originaldatei ersetzen.

### 6. `mod_quiz`-Version in der Datenbank zurücksetzen

Damit Moodle nach dem Datei-Revert keine "Downgrade"-Situation erkennt:

```bash
mysql -u <user> -p <dbname> -e \
  "UPDATE ${DBPREFIX}config_plugins SET value='2026042000'
   WHERE plugin='mod_quiz' AND name='version';"
```

### 7. Upgrade ausführen (installiert das neue Plugin, migriert die Daten)

```bash
cd $PUBLIC
php admin/cli/upgrade.php --non-interactive --allow-unstable
```

`--allow-unstable` ist nötig, weil das neue Plugin Maturity ALPHA hat. Während
des Upgrades passiert automatisch:
- `quizaccess_conditionalquestions` wird installiert;
- Regeln aus `local_conditional_questions_rule` (bzw. `local_cq_rule`) werden
  in `quizaccess_condq_rule` kopiert;
- die vom Core-Patch eingeführte Spalte `quiz_attempts.maxmark` wird in die
  Tabelle `quizaccess_condq_attempt` überführt, die Versuchs-Sumgrades werden
  normalisiert, die Spalte wird wieder entfernt.

### 8. Altes Plugin deinstallieren

```bash
cd $PUBLIC
php admin/cli/uninstall_plugins.php --plugins=local_conditional_questions --run
rm -rf $PUBLIC/local/conditional_questions
```

### 9. Caches leeren und verifizieren

```bash
php admin/cli/purge_caches.php

# DB-Prüfung (Prefix anpassen):
mysql -u <user> -p <dbname> -e \
  "SELECT plugin, value FROM ${DBPREFIX}config_plugins
   WHERE name='version'
     AND plugin IN ('mod_quiz','quizaccess_conditionalquestions');"
# Erwartung: mod_quiz = 2026042000, quizaccess_conditionalquestions = 2026100302

mysql -u <user> -p <dbname> -e "SHOW TABLES LIKE '%condq%';"
# Erwartung: quizaccess_condq_rule, quizaccess_condq_attempt, quizaccess_condq_hidden
```

Funktionale Verifikation im UI:
1. Als Admin: Website funktioniert ohne Fehlermeldungen.
2. Einen Test-Quiz-Versuch durchführen: alle Fragen sichtbar, Noten korrekt
   (Bewertung relativ zu den tatsächlich angezeigten Fragen).
3. Bei einem Quiz mit conditional questions: Konfigurationsseite öffnen
   (Quiz-Einstellungen → "Configure conditional questions") und prüfen, dass
   die alten Regeln übernommen wurden.
4. Wartungsmodus ausschalten:

```bash
php admin/cli/maintenance.php --disable
```

---

## Rollback (Notplan)

Wenn das Upgrade fehlschlägt und die Site nicht mehr funktioniert:

```bash
cd $ROOT
git apply -R --check /tmp/cq/docs/core_patch.patch 2>/dev/null || git apply -R /tmp/cq/docs/core_patch.patch
# Kern-Dateien sind damit wieder im gepatchten Zustand.
mysql -u <user> -p <dbname> -e \
  "UPDATE ${DBPREFIX}config_plugins SET value='2026100100'
   WHERE plugin='mod_quiz' AND name='version';"   # Version des GEPATCHTEN mod_quiz einsetzen
cd $PUBLIC
php admin/cli/upgrade.php --non-interactive --allow-unstable
```

Zuletzt auswegweise: Backup zurückspielen (Datenbank + `mod/quiz`-Code-Archiv)
und den Zustand vor der Migration wiederherstellen. Vor jedem Rollback
`moodledata` und die Datenbank sichern, damit keine Versuchsdaten verloren
gehen.
