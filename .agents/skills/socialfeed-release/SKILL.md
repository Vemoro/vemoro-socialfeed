---
name: socialfeed-release
description: Ein SocialFeed-Release anhand der WordPress.org-Checkliste vorbereiten oder eine konkret freigegebene Version veröffentlichen. Ein normaler Änderungs- oder Draft-PR-Auftrag reicht für das Publishing nicht aus.
---

# SocialFeed-Release vorbereiten

`WORDPRESS-ORG.md` ist die verbindliche Veröffentlichungscheckliste; außerdem `.github/workflows/wordpress-org-release.yml`, `.distignore` und `tools/build-release.ps1` lesen. Voraussetzungen daraus prüfen, nicht stillschweigend als erfüllt markieren.

1. Gewünschte Version und Ziel unterscheiden: lokales Paket, GitHub-Tag oder WordPress.org. Vorhandene konkrete Freigaben beachten; ohne Freigabe bis zum prüfbaren Paket und Releasevorschlag arbeiten.
2. Ausgangscommit und CI-Ergebnis feststellen. Version im Plugin-Header, `VEMORO_VERSION`, `readme.txt` und Changelog abgleichen. Keine Version allein wegen einer Doku- oder Skilländerung erhöhen.
3. Prüfungen nach `socialfeed-checks` und Checkliste ausführen; Installation, Upgrade und Datenaufbewahrung berücksichtigen. ZIP bauen, Verzeichnisnamen, Inhalt und Prüfsumme kontrollieren. Nicht verfügbare Prüfungen benennen.
4. Version, Commit, Paket, Prüfsumme, Ergebnisse und offene Voraussetzungen zur Entscheidung vorlegen. Bei fehlender Freigabe hier stoppen; vorhandene Freigaben nicht erneut abfragen.
5. Nur für die konkret freigegebene Version den vorgesehenen Tag-/Publishing-Ablauf nutzen. Der WordPress.org-Workflow verlangt zusätzlich bestätigte Plugin-Zulassung und das Eingabefeld `confirm_approved`; ohne belegte Zulassung nicht starten. Keine Credentials anlegen, Rechte ändern oder Freigaben durch Änderungen am Workflow ersetzen.
6. Nach dem Start Ergebnis des Workflows und veröffentlichte Version prüfen. Bei Fehlschlag zuerst feststellen, was bereits veröffentlicht wurde; keine blinde Wiederholung eines SVN-Commits oder Neuerstellung eines Tags. Unklare Teilveröffentlichungen mit Status und nächstem notwendigen Schritt zurückmelden.
