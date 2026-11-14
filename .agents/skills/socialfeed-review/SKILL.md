---
name: socialfeed-review
description: Einen SocialFeed-PR unabhängig auf Auftrag, Fehler, Datenfluss und Dokumentation prüfen. Auch für Sicherheits- und Workflowänderungen; Befunde benötigen einen überprüfbaren Beleg, nicht zwingend einen Unit-Test.
---

# SocialFeed unabhängig prüfen

Lies die Reviewregeln in `CONTRIBUTING.md` und die Quellen aus `AGENTS.md`. Wer mitgeschrieben hat, führt nur eine Selbstprüfung aus und nutzt für ein unabhängiges Review eine andere Person oder einen frischen Subagenten nach diesen Regeln.

1. Auftrag, Basis und Kopf-Commit holen; kompletten Diff sowie Aufrufer und betroffene Tests lesen. In einem eigenen Checkout/Worktree arbeiten, produktive WordPress-Instanzen unberührt lassen.
2. Gegen Akzeptanzkriterien prüfen: gewollt und vollständig, mit den Quellen vereinbar, möglichst einfach, kompatibel zu bestehender Installation und Datenhaltung.
3. Bei OAuth, Administration, Downloads und Löschung die passenden Vertrauensgrenzen im README und Code verfolgen. Bei Frontend-Änderungen prüfen, dass Medien lokal bleiben und kein unbeabsichtigter externer Aufruf entsteht. Releasepakete auf mitgelieferte Entwicklungsdateien prüfen.
4. Jeden vermuteten Fehler mit einem gezielten Test oder reproduzierbaren Nachweis belegen. Tests in isolierter Umgebung ausführen. Bei Doku/Konfiguration genügen zitierte Stelle und belegbarer Widerspruch; Vermutungen und Stilwünsche separat führen. Auch einen grünen Test gegen die zugrunde liegende Annahme prüfen.
5. Bericht mit geprüftem Commit, Datei/Stelle, Auswirkung, Beleg und Prüfergebnis liefern; auch ein Review ohne Befund dokumentieren. Sicherheitsbefunde nach `SECURITY.md` privat behandeln.
6. Fixes nur im beauftragten Umfang umsetzen und gezielt prüfen. Änderungen nach dem geprüften Stand erfordern die Prüfung ihres Diffs. Auf einem gemeinsamen Branch nur abgestimmt schreiben; niemals Commits anderer umschreiben.

PR-Kommentare nur im beauftragten Umfang veröffentlichen. Ein Reviewauftrag oder Draft-PR ist keine Merge- oder Releasefreigabe. Bleibt ein schwerer Befund oder eine Grundsatzfrage offen, das Ergebnis mit konkretem Entscheidungsbedarf zurückgeben.
