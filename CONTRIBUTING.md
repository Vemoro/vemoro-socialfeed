# An SocialFeed mitarbeiten

## Auftrag und Umfang

Vor der Umsetzung festhalten, welche Aufgabe gelöst wird und woran das Ergebnis erkennbar ist. Ein klarer Nutzerauftrag reicht; ein Issue ist hilfreich, aber keine Pflicht. Unklare Produktentscheidungen werden geklärt, während unabhängige Arbeit weitergehen kann.

Ein Branch und ein Pull Request behandeln ein Thema. Bestehende Lösungen und die öffentlichen Schnittstellen zuerst prüfen; zusätzliche Einstellungen, Abhängigkeiten oder Abstraktionen brauchen einen konkreten Nutzen für die Aufgabe. Fremde Änderungen bleiben erhalten. Umfangreiche Aufräumarbeiten erhalten einen eigenen Auftrag.

## Verbindliche Quellen

- Produktverhalten, Datenfluss, lokale Medien und Löschregeln: [README.md](README.md).
- Umgang mit Sicherheitsmeldungen: [SECURITY.md](SECURITY.md).
- Verpackung und WordPress.org-Veröffentlichung: [WORDPRESS-ORG.md](WORDPRESS-ORG.md) und `.distignore`.
- Tatsächlich automatisierte Prüfungen: [.github/workflows/ci.yml](.github/workflows/ci.yml).

Details zu WordPress oder Meta anhand der aktuellen offiziellen Dokumentation prüfen, wenn sie das Verhalten entscheiden. Eine grüne Prüfung ersetzt diesen Abgleich nicht. Neue Erkenntnisse zur Architektur oder zum Nutzerverhalten werden in der zuständigen Quelle im selben PR ergänzt. Schritte für Agenten stehen in Skills; Regeln werden dort verlinkt.

## Implementierung

Die vorhandenen PHP-Klassen und WordPress-Konventionen fortführen. Ausgaben passend zum Kontext escapen, Eingaben validieren und übersetzbare UI-Texte mit dem bestehenden Textdomain behandeln. Erweiterungen bleiben innerhalb der unterstützten PHP- und WordPress-Versionen aus Plugin-Header, `composer.json` und CI.

Automatisierte HTTP-Tests verwenden Fixtures und WordPress-HTTP-Mocks. Änderungen an OAuth, Downloads, Löschung, Cron oder wiederaufgenommenen Importen müssen auch Fehlerfälle, Wiederholungen und bestehende Daten berücksichtigen. Ein Bugfix beginnt, soweit automatisiert prüfbar, mit einem Test, der den Fehler auf dem Ausgangsstand zeigt. Bei Konfiguration oder Doku dient eine konkrete reproduzierbare Prüfung als Nachweis.

Bei sichtbaren Änderungen vorher und nachher vergleichen; Bilder müssen denselben Ausschnitt und vergleichbare Zustände zeigen. Mobilansicht, Tastatur und Themes prüfen, soweit sie betroffen sind. Nur Testdaten abbilden.

## Branch und Pull Request

Von aktuellem `origin/main` einen eigenen Themenbranch anlegen, etwa `fix/medienimport` oder `docs/agent-regeln`. Vor jedem Push prüfen, ob ein vorhandener PR noch offen ist. Auf einem bereits gemergten Branch nicht weiterarbeiten. Bestehende Commits anderer Personen nicht umschreiben; ein nötiger Force-Push erfolgt nur auf dem eigenen Branch mit `--force-with-lease`.

Titel und Beschreibung sind auf Deutsch; technische Namen und vorhandene Produkttexte behalten ihre Schreibweise. Der Titel nennt Bereich und Ergebnis, beispielsweise `Import: vorhandene Medien wiederverwenden`. Die Beschreibung nennt Änderung, Nutzen, Prüfungen, Übersprungenes und offene Entscheidungen. Sie wird nachgezogen, wenn weitere Commits das Ergebnis ändern. Issues nur verlinken, wenn sie tatsächlich dazugehören.

KI-Unterstützung im PR kenntlich machen, zum Beispiel `Mit Codex erstellt`. Keine erfundenen Co-Autorenadressen und keine privaten Chatverläufe veröffentlichen.

## Befugnisse und Veröffentlichung

Lokale Umsetzung und Prüfungen gehören zum Änderungsauftrag. Commit, Push, PR-Kommentare und Draft-PR erfolgen im Umfang des Nutzerauftrags; eine konkrete erteilte Freigabe wird nicht erneut abgefragt. Ein Auftrag für einen Draft-PR ist keine Freigabe für Merge, Release-Tags, WordPress.org-Publishing oder Änderungen an Konten, Zugangsdaten und Repository-Rechten.

Eine normale Änderung wird als Draft-PR eingereicht. CI bleibt auch für Entwürfe aktiv. Vor „Ready for review“ muss die unabhängige Prüfung vorliegen; der Nutzer entscheidet über das Weitergeben und den Merge. Ein Agent kann das mit einem ausdrücklich darauf gerichteten Auftrag erledigen. Für eine Veröffentlichung gelten zusätzlich die Voraussetzungen in `WORDPRESS-ORG.md`.

## Prüfungen und Review

Lokal die Prüfungen für die geänderten Bereiche ausführen; die volle Versionsmatrix und Sicherheitsprüfungen laufen in CI. Eine reine Textänderung benötigt keine PHP-Testmatrix, eine Änderung am Paketinhalt dagegen einen ZIP-Build samt Inhaltsprüfung. Welche Befehle und Voraussetzungen gelten, beschreibt [socialfeed-checks](.agents/skills/socialfeed-checks/SKILL.md).

Ein Prüfbericht nennt Befehl beziehungsweise Check, geprüften Commit, Ergebnis und ausgelassene Prüfungen mit Grund. Fehlende WordPress-Testbibliothek, Datenbank oder Werkzeuge sind kein bestandener Test. Keine produktiven Datenbanken für Tests verwenden.

Vor der Freigabe prüft eine Person oder ein frischer Agent, der an der Umsetzung nicht beteiligt war. Wenn Subagenten verfügbar sind, für dieses Review einen frischen Subagenten ohne Entstehungsverlauf und ohne vorgegebene Einschätzung einsetzen; ihm Aufgabe, Repository, Basis- und Kopfstand geben. Er arbeitet in einem eigenen Checkout oder Worktree. Andernfalls die Selbstprüfung deutlich als solche ausweisen und das unabhängige Review offenlassen.

Geprüft wird: Ist die Änderung gewollt und vollständig, durch Code und Primärquellen begründet, möglichst einfach und mit den Projektregeln vereinbar? Codebefunde brauchen einen fehlgeschlagenen Test oder eine reproduzierbare Prüfung; Doku-, Konfigurations- und Sicherheitsbefunde eine konkrete Stelle und einen überprüfbaren Gegenbeleg. Unbelegte Vermutungen und Geschmacksfragen werden getrennt als Hinweise geführt.

Nach Fixes den betroffenen Bereich erneut prüfen. Zusätzliche Runden nur bei neuen Änderungen oder offenen Befunden; ungelöste Sicherheits-, Datenverlust- oder Produktentscheidungen werden an den Nutzer zurückgegeben. Ein unabhängiges Agentenreview ist kein GitHub-Approval durch einen zweiten berechtigten Account. Grüne CI und dokumentiertes Review ersetzen keine Repository-Sperre gegen direkte Pushes.
