---
name: socialfeed-checks
description: Passende lokale Prüfungen für Änderungen am Vemoro-SocialFeed-Plugin auswählen, ausführen und berichten. Für PHP, JavaScript, Doku und ZIP-Inhalt; führt keine Veröffentlichung aus.
---

# SocialFeed prüfen

Lies `CONTRIBUTING.md`, im README „Tests und Entwicklung“ und für die vollständige Matrix `.github/workflows/ci.yml`. Vom Repository-Stamm arbeiten. Vorhandene Befehle verwenden; es gibt keinen npm-Installations- oder Asset-Build-Schritt.

| Änderung | Lokaler Nachweis |
| --- | --- |
| PHP | `composer install`, `composer lint`; PHP-Syntax der geänderten Dateien mit `php -l`; passende PHPUnit-Tests, bei breiten Änderungen `composer test` |
| JavaScript | Passende `node tests/js/*.test.js` einzeln ausführen; bei gemeinsamem Frontend-Code alle Dateien dieses Musters |
| CSS oder UI | Betroffene Darstellung mit Testdaten vor/nachher, mobil und per Tastatur prüfen; passende Renderer-/JS-Tests |
| Paketinhalt oder Entwicklungsdateien | `./tools/build-release.ps1 -OutputDirectory artifacts`; ZIP öffnen und gegen `.distignore` prüfen; Laufzeitdateien müssen enthalten sein, Entwicklungsunterlagen ausgeschlossen |
| Nur Dokumentation oder Skills | Referenzierte Dateien und Befehle gegen Repository und CI prüfen; SKILL-Frontmatter und Auslöser prüfen; `git diff --check` |

PHPUnit benötigt die WordPress-Testbibliothek über `WP_TESTS_DIR` und eine isolierte Datenbank. `tests/bootstrap.php`, `phpunit.xml.dist` und der CI-Job `phpunit` beschreiben den Aufbau. Der Docker-Helfer `tools/run-phpunit-in-docker.sh` setzt eine vorbereitete Umgebung mit dem Hostnamen `vemoro-wp-ci-mysql` und gemountetem Projekt voraus; er startet diese Umgebung nicht selbst. Keine vorhandenen Container der Person anhalten oder als Testdatenbank verwenden.

Ist PHP/Composer oder die Testumgebung nicht verfügbar, ausführbare Teilprüfungen erledigen und den fehlenden Teil konkret melden; ein übersprungener Lauf zählt nicht als bestanden. Die PHP-/WordPress-Matrix, Plugin Check und Sicherheitsprüfungen aus CI nicht als lokal ausgeführt darstellen.

CI muss zum aktuellen PR-Kopf gehören. Relevant rote Checks untersuchen; breitere Prüfungen nur bei neuen Änderungen, Fehlschlägen oder ungeklärten Abhängigkeiten wiederholen. Im Bericht Befehl, Stand, Ergebnis und Übersprungenes nennen.
