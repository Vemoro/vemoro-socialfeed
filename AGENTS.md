# Einstieg für Coding-Agenten

SocialFeed ist ein WordPress-Plugin. Die ausgelieferten PHP-, JavaScript- und CSS-Dateien sind die Quellen; es gibt keinen npm- oder Asset-Build. Der separate OAuth-Broker gehört zu `vemoro-platform`.

## Vor der Änderung lesen

- [CONTRIBUTING.md](CONTRIBUTING.md): Umfang, Befugnisse, Nachweise und Review.
- [README.md](README.md): Verhalten, Datenschutz, Tests und Voraussetzungen.
- [SECURITY.md](SECURITY.md) bei Sicherheitsfragen; [WORDPRESS-ORG.md](WORDPRESS-ORG.md) bei Releases.
- Den betroffenen Code und seine Aufrufer lesen. Widersprüche zwischen Doku und Code benennen, bevor sie als Grundlage dienen.

## Unverhandelbar

- Keine Besucheranfragen an Meta, externen Embeds oder Tracking ergänzen; das Datenflussmodell steht im README.
- OAuth-Bindungen, Berechtigungsprüfungen, Nonces, Downloadschutz und Datenlöschregeln nicht lockern, um eine Änderung durchzubekommen.
- Tests verwenden isolierte WordPress-Datenbanken und Fixtures, keine produktiven Daten oder echten Meta-Konten.
- Keine Tokens, Geheimnisse oder personenbezogenen Nutzungsdaten in Dateien, Logs, Screenshots oder PRs aufnehmen.
- Keine Tests abschwächen, überspringen oder löschen, um einen grünen Lauf zu erhalten; Übersprungenes als solches melden.
- Nicht direkt auf `main` pushen. Merge, Tags und Veröffentlichung benötigen einen dafür erteilten Auftrag. Eine konkrete bestehende Freigabe gilt weiter.

## Arbeitsabläufe

Die Skills liegen im nativen Codex-Suchpfad `.agents/skills/`. Andere Agenten lesen dieselben Dateien:

| Aufgabe | Skill |
| --- | --- |
| Änderung umsetzen und Draft-PR vorbereiten | [socialfeed-aenderung](.agents/skills/socialfeed-aenderung/SKILL.md) |
| Passende Prüfungen auswählen und ausführen | [socialfeed-checks](.agents/skills/socialfeed-checks/SKILL.md) |
| Code, Doku oder Konfiguration unabhängig prüfen | [socialfeed-review](.agents/skills/socialfeed-review/SKILL.md) |
| Eine Version vorbereiten oder freigegeben veröffentlichen | [socialfeed-release](.agents/skills/socialfeed-release/SKILL.md) |

Projektregeln gelten einmal in den verlinkten Dokumenten; die Skills beschreiben die Schritte. Neue Agentenunterlagen gehören in `.distignore`, damit sie weder im ZIP noch im WordPress.org-Paket landen.
