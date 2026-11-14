---
name: socialfeed-aenderung
description: Eine Änderung am Vemoro-SocialFeed-Plugin umsetzen und als Draft-PR vorbereiten. Für Funktionen, Bugfixes und Projektregeln; nicht für den separaten OAuth-Broker oder eine Veröffentlichung.
---

# SocialFeed ändern

1. `AGENTS.md` und `CONTRIBUTING.md` im Repository lesen. Auftrag und Akzeptanzkriterien festhalten; Produktfragen klären, ohne bereits erlaubte Arbeit erneut freigeben zu lassen.
2. Betroffene Einstiegspunkte und Tests untersuchen: Plugin-Bootstrap, `includes/`, `assets/`, `uninstall.php`. Den Datenfluss im README gegen die betroffenen Stellen prüfen. Der Broker ist ein anderes Repository.
3. Eigenen Themenbranch vom aktuellen `origin/main` anlegen. Vorhandene Änderungen und offene PRs berücksichtigen.
4. Bei Bugfixes die Regression möglichst zunächst mit einem gezielten Test nachweisen. Minimal umsetzen, betroffene Nutzer-Doku aktualisieren. Bei sichtbaren Änderungen den Ausgangszustand vor der Bearbeitung erfassen.
5. `socialfeed-checks` für die betroffenen Bereiche ausführen. Ändert sich `.distignore`, ein Verpackungsskript oder kommen Entwicklungsdateien hinzu, den Paketinhalt kontrollieren.
6. Diff gegen Auftrag, Datenfluss und Schnittstellen selbst prüfen. Für das unabhängige Review nach `socialfeed-review` vorgehen; fehlt es noch, den Status im Draft benennen.
7. Im beauftragten Umfang committen, pushen und einen Draft-PR gegen `main` anlegen. Die PR-Vorlage mit tatsächlichen Ergebnissen ausfüllen. Bei CLI-Nutzung den mehrzeiligen Text aus einer Datei mit `--body-file` übergeben. CI auf dem gepushten Stand prüfen und relevante Fehler bearbeiten.
8. PR-Link, Kopf-Commit, Prüfungen und offene Punkte zurückmelden. Vor weiteren Pushes prüfen, dass der PR offen ist; Beschreibung bei geändertem Ergebnis nachziehen. Merge und Release sind eigene Aufträge.
