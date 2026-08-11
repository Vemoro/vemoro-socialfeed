# Vemoro SocialFeed for WP

Vemoro SocialFeed for WP synchronisiert Beiträge eines eigenen professionellen Instagram-Kontos serverseitig in WordPress. Bilder, Poster, optionale Videos, Captions und Metadaten werden lokal gespeichert. Beim bloßen Anzeigen des Feeds muss der Browser des Besuchers deshalb keine Verbindung zu Instagram oder Meta aufbauen.

> Das Plugin ist so konzipiert, dass beim bloßen Anzeigen des lokal gespeicherten Feeds keine Verbindung des Besucher-Browsers zu Instagram oder Meta erforderlich ist. Die rechtliche Zulässigkeit der veröffentlichten Inhalte, insbesondere Bildrechte und personenbezogene Daten, bleibt vom Websitebetreiber zu prüfen.

[![GitHub Sponsors](https://img.shields.io/badge/GitHub%20Sponsors-Unterstützen-ea4aaa?logo=githubsponsors)](https://github.com/sponsors/vemoro)
[![Liberapay](https://img.shields.io/badge/Liberapay-Unterstützen-f6c915?logo=liberapay&logoColor=black)](https://liberapay.com/vemoro/donate)

## Voraussetzungen

- WordPress 6.5 oder neuer
- PHP 8.1 oder neuer mit JSON, Fileinfo und entweder Sodium oder OpenSSL
- Schreibbares WordPress-Uploadverzeichnis
- Instagram Business- oder Creator-Konto
- Für den empfohlenen Vemoro Login: eine öffentlich erreichbare WordPress-Website mit HTTPS
- Nur für den Expertenmodus: eigene Meta-App mit „Instagram API with Instagram Login“

Das Plugin benötigt weder Composer noch npm zur Laufzeit. Die in `composer.json` aufgeführten Pakete dienen ausschließlich der Entwicklung und den Tests.

## Installation

1. Den Ordner `vemoro-socialfeed` nach `wp-content/plugins/` kopieren.
2. „Vemoro SocialFeed for WP“ in WordPress aktivieren.
3. Im Adminmenü „Vemoro Login“ auswählen, Nutzungsbedingungen und Datenschutzhinweise öffnen und deren Kenntnisnahme bestätigen.
4. „Mit Instagram verbinden“ anklicken.
4. Nach der Rückkehr zu WordPress die erste Synchronisierung starten.

Der Vemoro Login benötigt keine App-ID und kein App-Secret in WordPress. Der zentrale Dienst unter `connect.vemoro.de` besitzt eine feste Meta-Callback-URL und gibt das Long-Lived Token über einen verschlüsselten, kurzlebigen Einmalcode an WordPress zurück. Das dauerhafte Token liegt anschließend ausschließlich verschlüsselt in der WordPress-Installation.

Beim bewusst gestarteten Login übermittelt WordPress Callback-URL, zufälligen Sicherheitsstatus, Plugin-Version und Website-URL an den Vemoro-Verbindungsdienst. Der Dienst hält den OAuth-Vorgang höchstens zehn Minuten und den verschlüsselten Einmal-Grant höchstens zwei Minuten vor. Datenschutzhinweise stehen unter [vemoro.de/socialfeed/datenschutz](https://vemoro.de/socialfeed/datenschutz/), die Nutzungsbedingungen unter [vemoro.de/nutzungsbedingungen](https://vemoro.de/nutzungsbedingungen/) und die Datenlöschungsanleitung unter [vemoro.de/datenloeschung](https://vemoro.de/datenloeschung/).

Das vorhandene Smash-Balloon-Plugin wird weder verändert noch migriert und kann parallel installiert bleiben.

## Instagram Login und eigener Meta-App-Expertenmodus

Die Einrichtung in Meta kann sich ändern. Maßgeblich ist immer die aktuelle offizielle Dokumentation zur [Instagram API with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/). Für dieses Plugin gilt:

- Login-Typ: Business Login for Instagram
- Konto: Business oder Creator; ein privates Consumer-Konto wird nicht unterstützt
- Scope: `instagram_business_basic`
- Standard Access reicht für eigene oder verwaltete Konten, die in der App hinterlegt sind
- Advanced Access und gegebenenfalls App Review sind nötig, wenn die App fremde professionelle Konten bedienen soll
- Eine verknüpfte Facebook-Seite ist für diese API-Variante nicht erforderlich

Wer eine eigene Meta-App betreiben möchte, wählt den Expertenmodus. Als Redirect URI wird dabei standardmäßig verwendet:

```text
https://example.org/wp-admin/admin.php
```

Die Callback-URI enthält bewusst keine Query-Parameter, da Instagram diese beim OAuth-Rücksprung entfernt. Produktion verlangt HTTPS. HTTP wird nur akzeptiert, wenn WordPress die Umgebung als `local` ausweist oder ein Loopback-Host verwendet wird.

### Secrets über wp-config.php

Die sicherste betriebliche Variante ist:

```php
define('VEMORO_INSTAGRAM_APP_ID', '123456789');
define('VEMORO_INSTAGRAM_APP_SECRET', 'replace-with-the-real-secret');
```

Konstanten haben Vorrang vor Datenbankwerten. Ohne Konstanten verschlüsselt das Plugin das App Secret und Token mit Sodium `secretbox`, ersatzweise AES-256-GCM. Der Schlüssel wird aus WordPress-Salts abgeleitet. Steht keine sichere Verschlüsselung bereit, verweigert das Plugin die Secret-Speicherung in der Datenbank.

Für einen selbst betriebenen oder lokalen Vemoro-Verbindungsdienst kann dessen Basis-URL gesetzt werden:

```php
define('VEMORO_CONNECT_URL', 'https://connect.example.org');
```

## API-Endpunkte und Token

Der zentral konfigurierbare Standard ist Graph API `v25.0`. Vor einem späteren Versionswechsel sollte der Betreiber Metas Changelog und Feldverfügbarkeit prüfen.

Serverseitig werden verwendet:

```text
GET  https://www.instagram.com/oauth/authorize
POST https://api.instagram.com/oauth/access_token
GET  https://graph.instagram.com/access_token
GET  https://graph.instagram.com/refresh_access_token
GET  https://graph.instagram.com/v25.0/{ig-user-id}
GET  https://graph.instagram.com/v25.0/{ig-user-id}/media
```

Der OAuth-Code wird gegen ein Short-Lived Token getauscht, anschließend wird ein Long-Lived Token bezogen. Sieben Tage vor dem erwarteten Ablauf versucht das Plugin die Erneuerung. Fehler lösen Wiederholungen nach 15 Minuten, einer Stunde, sechs Stunden und anschließend täglich aus. Token, App Secret, Authorization-Header und OAuth-Codes werden nie geloggt oder an Browser-Responses ausgegeben.

## Synchronisierung und lokale Speicherung

Der Standardlauf lädt die neuesten 12 Beiträge alle zwei Stunden. Unterstützt werden `IMAGE`, `VIDEO`, `CAROUSEL_ALBUM` und Reels über `media_product_type=REELS`.

- WordPress-CPT: `vemoro_socialfeed`
- Eindeutiger Index: `{$wpdb->prefix}vemoro_instagram_media`
- Begrenztes Log: `{$wpdb->prefix}vemoro_logs`
- Cron-Hook: `vemoro_sync_instagram_feed`
- Lock: `vemoro_sync_lock`

Temporäre Media-URLs allein ändern den semantischen Beitrags-Hash nicht. Vorhandene, vollständige Attachments werden wiederverwendet. Karussellkinder werden separat gespeichert; ein defektes Kind bricht den übrigen Lauf nicht ab. Manuell gepflegte WordPress-Alt-Texte werden nicht überschrieben.

Werden Darstellungsoptionen geändert, markiert das Plugin den nächsten Lauf als Vollaktualisierung. Dabei werden alle bereits vorhandenen Beiträge im abgerufenen Bestand erneut verarbeitet, bestehende gültige Attachments aber weiterhin wiederverwendet. Der Synchronisierungs-Tab zeigt an, ob eine Vollaktualisierung aussteht.

Ein fehlender Beitrag gilt nur innerhalb eines vollständig und erfolgreich abgerufenen aktuellen Zeitfensters als abwesend. Erst drei autoritative Läufe lösen die konfigurierte Aktion aus. Optional kann zusätzlich eine Karenzzeit von 12, 24 oder höchstens 48 Stunden verlangt werden. API-, Token- und Pagingfehler sind kein Löschsignal und erhöhen weder den Zähler noch die Karenzzeit. Wird ein Beitrag nach dieser Regel als dauerhaft nicht mehr vorhanden bestätigt, wird er in jedem Aufbewahrungsmodus aus dem öffentlichen Feed entfernt; er kann anschließend lokal inaktiv bleiben, in den Papierkorb verschoben oder endgültig gelöscht werden.

Für Beiträge oberhalb des konfigurierten Synchronisierungslimits gibt es eine getrennte Aufbewahrungsregel: dauerhaft behalten, sofort oder nach 7, 30, 90, 180 beziehungsweise 365 Tagen löschen. Die Frist beginnt beim ersten vollständigen und fehlerfreien Lauf, in dem ein Beitrag außerhalb des Limits liegt. Beim Löschen werden Plugin-Zuordnungen und nicht anderweitig verwendete Plugin-Medien einschließlich lokaler Videos entfernt. Wird das Limit später erhöht, wird die laufende Frist für wieder eingeschlossene Beiträge zurückgesetzt.

Videos werden lokal als MP4 gespiegelt und mit lokalem Poster sowie `preload="metadata"` ausgegeben, damit die nativen Videosteuerungen zuverlässig initialisiert werden. Beim Darüberfahren startet ein Video stumm; startet ein anderes, pausiert das zuvor aktive Video. Bei aktivierter Systemoption `prefers-reduced-motion` findet kein automatischer Start statt, die Wiedergabe bleibt aber per Schaltfläche bedienbar. `controlsList="nodownload"` blendet den Download-Eintrag der Browsersteuerung aus; zusätzlich wird das Kontextmenü direkt auf dem Video unterdrückt. Da die Videodatei technisch an den Browser übertragen werden muss, ist dies kein absoluter Kopierschutz gegen Entwicklerwerkzeuge oder Netzwerkzugriffe.

Alle Medien erscheinen standardmäßig in einer einheitlichen 9:16-Reel-Fläche. `object-fit: contain` verhindert Zuschnitt; Querformatbilder und -videos erhalten deshalb freie Flächen oberhalb und unterhalb. Vollständige Beitragstexte bleiben im HTML erhalten und können bei längeren Captions auf- und zugeklappt werden.

Der Feed wird mit JavaScript responsiv nach ungefähr 25 Prozent seiner zweiten Beitragszeile ausgeblendet. Die lokale Schaltfläche „Mehr anzeigen“ fügt die weiteren Beiträge erst beim Klick aus einem inaktiven HTML-Template ein und klappt sie mit einer ruhigen Aufslide-Animation auf. Deren Bilder und Videos werden daher vorher nicht angefordert. Bilder verwenden zusätzlich natives Lazy-Loading und Videos laden nach dem Einfügen zunächst nur Metadaten. Ohne JavaScript bleiben die ersten zwei Zeilen zugänglich. Bei reduzierter Bewegung wird ohne Animation geöffnet.

Likes und Kommentaranzahl werden mit dem normalen Medienabruf synchronisiert und lokal angezeigt. Kommentartexte werden weder geladen noch ausgegeben. Das Plugin fordert weiterhin ausschließlich `instagram_business_basic` an. Share-, View-, Save- oder Repost-Statistiken werden nicht abgerufen, da sie zusätzliche Insights-Berechtigungen erfordern können.

Synchronisiert wird ausschließlich der Medienbestand, den Meta für das verbundene professionelle Konto über `/{ig-user-id}/media` zurückgibt. Native Instagram-Reposts und Collab-Beiträge, deren ursprünglicher Eigentümer ein anderes Konto ist, gehören nicht zuverlässig zu diesem Bestand und werden deshalb nicht unterstützt. Ist das verbundene Konto selbst ursprünglicher Eigentümer eines Collab-Beitrags, kann Meta ihn als normalen eigenen Beitrag liefern; die API stellt dabei kein verlässliches Collab-Merkmal bereit. Das Plugin bietet daher keine Repost- oder Collab-Filter an und behauptet keine Erkennung, die sich mit der offiziellen API nicht belastbar umsetzen lässt.

## Ausgabe

### Gutenberg

Im Blockeditor den dynamischen Block „Vemoro SocialFeed“ einfügen. Die Vorschau und das Frontend werden serverseitig aus lokalen Daten erzeugt. Der Block ist als `vemoro-socialfeed/feed` registriert.

### Shortcode

```text
[vemoro_socialfeed]
[vemoro_socialfeed posts="9" columns="3" columns_tablet="2" columns_mobile="1" show_caption="true"]
[vemoro_socialfeed posts="6" aspect_ratio="4/5" show_date="false" order="ASC" class="startseite-feed"]
```

Unterstützt werden `posts`, `columns`, `columns_tablet`, `columns_mobile`, `show_caption`, `show_date`, `show_username`, `show_metrics`, `show_link`, `caption_length`, `aspect_ratio`, `order` und `class`.

Der Shortcode lautet `[vemoro_socialfeed]`.

### Theme-Funktion

```php
if (function_exists('vemoro_socialfeed_render')) {
    echo vemoro_socialfeed_render(array('posts' => 9, 'columns' => 3)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
```

Themes können den Feed mit `vemoro_socialfeed_render()` ausgeben.

Die Funktion liefert bereits kontextbezogen escaptes Plugin-Markup.

Sind externe Instagram-Links aktiviert, verlinkt der Benutzername auf das Instagram-Profil. Ein rechtsbündiges Instagram-Symbol unter dem Medium verlinkt den zugehörigen Instagram-Beitrag; Bild, Karussell und Video selbst bleiben unverlinkt und lokal bedienbar. Vor jeder Weiterleitung erscheint ein lokaler Datenschutzhinweis. Erst nach Bestätigung wird Instagram im selben oder – entsprechend der Darstellungseinstellung – in einem neuen Tab geöffnet. Bei deaktivierten externen Links werden weder Profil- noch Beitragslinks ausgegeben.

## WP-Cron, WP-CLI und echter Server-Cron

WP-Cron wird normalerweise erst durch Websiteaufrufe angestoßen. Action Scheduler wird genutzt, wenn seine öffentliche API bereits geladen ist; andernfalls plant das Plugin WP-Cron.

```bash
wp vemoro-socialfeed sync
wp vemoro-socialfeed status
wp vemoro-socialfeed refresh-token
wp vemoro-socialfeed clear-cache
```

Dieselben Unterbefehle stehen zusätzlich unter `wp vemoro-socialfeed …` bereit.

Beispiel für einen echten Server-Cron:

```cron
*/30 * * * * cd /pfad/zu/wordpress && wp vemoro-socialfeed sync --quiet
```

## Datenschutz- und Sicherheitskonzept

- Keine Cookies, Besucher-IDs, IP-Protokollierung, Fingerprints oder Telemetrie
- Keine Meta-Skripte, SDKs, iframes, Pixel, Fonts oder Styles im Frontend
- Besucher bauen beim Seitenaufruf keine Verbindung zu Meta auf; es gibt keine Browseraufrufe an die Instagram API
- Renderer akzeptiert nur Attachment-URLs mit demselben Host wie `home_url()`
- Ausschließlich der Server des Websitebetreibers kommuniziert während OAuth, Synchronisierung und Tokenpflege mit der Instagram API
- OAuth-State ist zufällig, benutzergebunden, gehasht, zehn Minuten gültig und nur einmal verwendbar
- Adminaktionen verwenden `manage_options` und WordPress-Nonces
- Mediendownloads erlauben HTTPS und Meta-Medienhosts, prüfen DNS/IP gegen private Netze, jeden Redirect, Dateigröße und echten MIME-Typ
- Logs entfernen Schlüssel mit Bezeichnungen wie Token, Secret, Authorization oder Code

Normale Instagram-Links werden nur bei aktivierter Einstellung ausgegeben, als externe Links gekennzeichnet und erst durch einen bewussten Klick aufgerufen.

Im Datenschutz-Tab können alle synchronisierten Beiträge und Zuordnungen gelöscht werden. Plugin-eigene Medien werden nur entfernt, wenn sie nicht als Beitragsbild oder Inhalt außerhalb des Instagram-Feeds referenziert sind. Verbindung und Einstellungen bleiben erhalten, sodass anschließend sofort neu synchronisiert werden kann.

Vorübergehende API-Fehler oder Tokenprobleme lösen keine sofortige Löschung aus. Der Administrator wird über Status und Protokolle informiert und kann das Konto erneut verbinden. Kann Meta nicht eindeutig zwischen einer vorübergehenden Störung und einem dauerhaften Widerruf unterscheiden, trifft das Plugin keine automatische destruktive Annahme. Sobald der Administrator eine dauerhaft getrennte oder widerrufene Verbindung über „Verbindung trennen und Instagram-Daten löschen“ bestätigt, entfernt das Plugin Token sowie sämtliche über die Instagram API bezogenen Platform-Daten: Beiträge, Captions, Metadaten, Eltern-/Kind-Zuordnungen und ausschließlich die vom Plugin angelegten, nirgendwo sonst in WordPress referenzierten Medien.

## Browser-Abnahme ohne Meta-Requests

Nach einer erfolgreichen Synchronisierung:

1. Eine Seite mit Block oder Shortcode öffnen.
2. Browser-Entwicklertools öffnen und den Network-Tab leeren.
3. Die Seite vollständig neu laden.
4. Nacheinander nach `instagram`, `facebook`, `meta`, `fbcdn`, `cdninstagram`, `scontent` und `graph.facebook` filtern.
5. Erwartung: keine Requests an diese Domains. Ein normaler externer Link darf im HTML stehen, wird aber nicht angefordert.
6. Den HTML-Quelltext zusätzlich nach `instagram.com/embed`, `instagram-media`, `embed.js`, `fbcdn`, `cdninstagram`, `scontent` und `graph.facebook.com` durchsuchen.
7. Alle Bild-, Poster-, `srcset`-, Script- und Stylesheet-URLs müssen auf die eigene Website zeigen.

## Tests und Entwicklung

Der vollständige Quellcode wird öffentlich unter [github.com/Vemoro/vemoro-socialfeed](https://github.com/Vemoro/vemoro-socialfeed) gepflegt. Die JavaScript-Dateien in `assets/js/` und die CSS-Dateien in `assets/css/` sind die vollständigen, menschenlesbaren Quelldateien, die das Plugin ausführt. Sie werden direkt gepflegt und weder generiert noch gebündelt, minifiziert oder kompiliert. Ein npm-, webpack- oder sonstiger Asset-Build ist daher nicht erforderlich.

```bash
composer install
composer test
composer lint
```

`WP_TESTS_DIR` muss auf die WordPress-PHPUnit-Testbibliothek zeigen. HTTP-Integrationstests verwenden `pre_http_request` und Fixtures; automatisierte Tests senden keine echten Meta-Anfragen.

Die JavaScript-Tests benötigen nur Node.js und können einzeln ausgeführt werden:

```bash
node tests/js/block-editor.test.js
node tests/js/frontend-audio.test.js
node tests/js/frontend-caption.test.js
node tests/js/frontend-reveal.test.js
node tests/js/frontend-row-height.test.js
```

Eine veröffentlichungsfertige ZIP-Datei ohne Tests und Entwicklungswerkzeuge lässt sich aus dem Repository-Stamm erstellen:

```powershell
./tools/build-release.ps1 -OutputDirectory artifacts
```

## Fehlerbehebung

- **Nicht verbunden:** App-ID, App Secret, exakte Redirect URI und professionellen Kontotyp prüfen.
- **OAuth-State ungültig:** Verbindung erneut aus demselben eingeloggten WordPress-Adminfenster starten; alte Links laufen nach zehn Minuten ab.
- **Token kann nicht gespeichert werden:** Sodium/OpenSSL aktivieren oder Secrets in `wp-config.php` setzen.
- **Cron läuft nicht:** Diagnose und `DISABLE_WP_CRON` prüfen oder echten Server-Cron verwenden.
- **Uploadfehler:** Schreibrechte, WordPress-Limits sowie Bild-/Videolimit im Plugin prüfen.
- **Kein Bild im Feed:** Die Diagnose auf fremde CDN-Filter prüfen. Das Plugin lehnt absichtlich URLs außerhalb der eigenen Domain ab.
- **Teilweise fehlendes Karussell:** Logs zeigen das betroffene Kind; andere Beiträge und Kinder werden weiterverarbeitet.

## Deaktivierung und Deinstallation

Das Trennen einer dauerhaft widerrufenen Verbindung ist eine bestätigungspflichtige Löschaktion: Token, Posts, Captions, Metadaten und Zuordnungen werden entfernt. Ausschließlich plugin-eigene Attachments werden gelöscht, und auch diese nur, wenn sie nicht anderweitig in WordPress referenziert sind. Vorübergehende Verbindungsfehler führen dagegen nicht zur Löschung und können durch erneutes Verbinden behoben werden.

Deaktivieren entfernt Zeitpläne und Locks, aber keine Inhalte. Beim Löschen des Plugins bleiben Daten standardmäßig erhalten. Nur wenn zuvor „Alle Plugin-Daten bei Deinstallation löschen“ aktiviert wurde, entfernt `uninstall.php` Plugin-Beiträge, plugin-eigene Attachments, Tabellen, Optionen, Secrets, Transients und Zeitpläne endgültig. Wer die Installation bei bestehender Verbindung vollständig außer Betrieb nimmt, sollte daher vorher entweder die Verbindung mit Datenlöschung trennen oder die Deinstallationslöschung aktivieren.

Im Datenschutz-Tab steht zusätzlich „Verwaiste Mediendateien bereinigen“ zur Verfügung. Die Funktion berücksichtigt ausschließlich plugin-eigene Attachments ohne aktuelle Instagram-Zuordnung. Medien, die als Beitragsbild, in Inhalten, Metadaten, Theme-Einstellungen, Website-Icon, Logo oder anderen WordPress-Daten verwendet werden, bleiben erhalten. Vor der Ausführung sind Administratorberechtigung, Nonce und eine ausdrückliche Bestätigung erforderlich; parallel laufende Synchronisierungen werden durch denselben Lock ausgeschlossen.

## Grenzen

- Genau ein professionelles Instagram-Konto pro WordPress-Installation
- Keine Beiträge fremder oder privater Konten, keine Hashtagfeeds und kein Scraping
- Keine Garantie, dass Meta unveränderte Endpunkte, Felder oder Review-Regeln beibehält
- Externe Instagram-Links verlassen beim Anklicken bewusst die lokale Website
- Die Nutzung entbindet den Betreiber nicht von der Prüfung von Bildrechten, Einwilligungen, Löschpflichten und Datenschutzerklärung

## Freiwillige Unterstützung

Vemoro SocialFeed for WP bleibt kostenlos, werbefrei und ohne Tracking. Freiwillige Beiträge über [Liberapay](https://liberapay.com/vemoro/donate) oder [GitHub Sponsors](https://github.com/sponsors/vemoro) helfen bei Wartung, Sicherheitsupdates, Hosting und Betrieb des Vemoro-Verbindungsdienstes.

Administratoren sehen den optionalen Hinweis ausschließlich auf den Verwaltungsseiten von Vemoro SocialFeed. Er lässt sich für 120 Tage zurückstellen oder pro Benutzer dauerhaft ausblenden. Zusätzlich enthält die Plugin-Verwaltung einen unaufdringlichen Unterstützungsbereich. Beim Anzeigen der Hinweise werden keine externen Ressourcen geladen; eine Verbindung zu Liberapay oder GitHub entsteht erst nach dem bewussten Anklicken des jeweiligen Links. Eine Unterstützung ist vollständig freiwillig und verändert den Funktionsumfang nicht.

Technische Fragen und Probleme können an [support@vemoro.de](mailto:support@vemoro.de) gesendet werden. Die Adresse wird sowohl im Backend-Hinweis als auch in der Plugin-Verwaltung angezeigt.

## Changelog

### 2.2.2

- Offizielle Vemoro-Markenassets für Banner und Plugin-Icon auf WordPress.org ergänzt.
- GitHub-Finanzierungslinks für Liberapay und GitHub Sponsors eingerichtet.
- Ungenutzten Altcode und zugehörige Dokumentation entfernt.
- Übersetzbare Metadaten auf die englische WordPress.org-Quellsprache vereinheitlicht.

### 2.2.1

- Vollständiges öffentliches Quell-Repository verlinkt und dokumentiert, dass die ausgelieferten JavaScript- und CSS-Dateien direkt gepflegte, menschenlesbare Quelldateien ohne erforderlichen Asset-Build sind.
- Verbliebene Browser-Bezeichner auf den eindeutigen Präfix `vemoro` umgestellt.

### 2.2.0

- Sämtliche Deklarationen und gespeicherten Daten verwenden den eindeutigen Präfix `vemoro`.
- Vemoro Connect und Instagram/Meta einschließlich Datenübertragung, Nutzungsbedingungen und Datenschutzrichtlinien vollständig in `readme.txt` offengelegt.
- Adminhinweis auf Pluginseiten begrenzt und mitgelieferte Übersetzungsdateien für die WordPress.org-Ausgabe entfernt.

### 2.1.6

- Veralteten manuellen Übersetzungsloader entfernt; Übersetzungen werden über die WordPress-Sprachpakete geladen.
- Laufzeitkonstanten und Variablen der Deinstallationsroutine vollständig mit dem Plugin-Präfix versehen.
- Absichtliche, nicht zwischengespeicherte Zugriffe auf die plugin-eigenen Medien- und Diagnosetabellen für Plugin Check dokumentiert.

### 2.1.5

- Fehlerprotokolle um Phase, API-Operation, HTTP-Status, Meta-Fehlercode und Meta-Request-ID ergänzt, ohne Token oder Queryparameter zu speichern.
- Eine dauerhafte Warnung erscheint erst, wenn derselbe Fehler in zwei aufeinanderfolgenden Prüfungen oder Synchronisierungen auftritt; ein erfolgreicher Lauf setzt sie zurück.

### 2.1.4

- Profil- und Medienabfragen verwenden die an das Zugriffstoken gebundenen `/me`-Endpunkte der Instagram API.

### 2.1.3

- Verbindungsschaltflächen auf die normale WordPress-Buttonhöhe vereinheitlicht und sauber ausgerichtet.

### 2.1.2

- Versionierte Zustimmung zu Nutzungsbedingungen und Datenschutzhinweisen dauerhaft in den WordPress-Einstellungen gespeichert.
- Zustimmung direkt bei der Auswahl „Vemoro Login“ platziert.
- Zugangsdaten der eigenen Meta-App werden erst nach Auswahl des Expertenmodus aufgeklappt.
- Einmalige OAuth-Grants werden als JSON übertragen; der Broker bleibt während des Übergangs mit formularbasierten Plugin-Versionen kompatibel.

### 2.1.1

- Fehlende Synchronisierungspläne werden nach der Initialisierung von WordPress beziehungsweise Action Scheduler automatisch repariert.
- Ein vorhandener WP-Cron-Ersatztermin wird auch bei installiertem Action Scheduler korrekt erkannt und angezeigt.
- Ist Action Scheduler zwar geladen, aber noch nicht einsatzbereit, bleibt WP-Cron als sicherer Rückfall aktiv.
- OAuth-Rücksprünge des Vemoro-Verbindungsdienstes sind unabhängig von alten WordPress-Admin-Seiten-Slugs.

### 2.1.0

- WordPress.org-Slug, Pluginordner, Hauptdatei, Textdomain und Sprachdateien auf `vemoro-socialfeed` migriert.
- Vorschlag für die WordPress-Datenschutzerklärung über `wp_add_privacy_policy_content()` ergänzt.
- Externen Vemoro-OAuth-Dienst, übertragene Daten, Laufzeiten, Datenschutzseite und Nutzungsbedingungen vollständig dokumentiert.
- Vor jedem Vemoro Login eine serverseitig geprüfte Zustimmung zu den verlinkten Nutzungsbedingungen ergänzt.
- WordPress.org-Metadaten für Contributor, Donate-Link und Upgrade ergänzt.

### 2.0.3

- Kontaktmöglichkeit `support@vemoro.de` im Backend-Hinweis, im Unterstützungsbereich und in der Dokumentation ergänzt.

### 2.0.2

- Unterstützungshinweis ohne Wartefrist für alle angemeldeten Benutzer im WordPress-Backend freigeschaltet; Zurückstellen und dauerhaftes Ausblenden bleiben benutzerbezogen.

### 2.0.1

- Nicht belastbare Collab- und Repost-Filter entfernt; das Plugin verarbeitet ausschließlich den von Meta gelieferten kontoeigenen Medienbestand.
- Drei-Sync-Regel um eine optionale Karenzzeit bis 48 Stunden erweitert; dauerhaft fehlende Beiträge bleiben nicht mehr öffentlich sichtbar.
- Dauerhaftes Trennen löscht nach ausdrücklicher Bestätigung Token und API-bezogene Platform-Daten; vorübergehende API- oder Tokenfehler lösen keine Löschung aus.
- Datenschutz- und Deinstallationsdokumentation zum serverseitigen Meta-Zugriff und Lebenszyklus lokaler Daten präzisiert.
- Freiwillige Unterstützungslinks für Liberapay und GitHub Sponsors ergänzt. Der verzögerte Adminhinweis ist zurückstellbar oder dauerhaft ausblendbar und lädt keine externen Ressourcen.

### 2.0.0

- Produktname und Administrationsoberfläche auf „Vemoro SocialFeed for WP“ umgestellt.
- Vemoro Login als Standard hinzugefügt; WordPress benötigt dabei kein Meta-App-Secret.
- Eigene Meta-App bleibt als Expertenmodus verfügbar und bestehende Konfigurationen werden automatisch beibehalten.
- Neuer Block `vemoro-socialfeed/feed`, Shortcode `[vemoro_socialfeed]`, Theme-Funktion `vemoro_socialfeed_render()` und CLI-Befehl `wp vemoro-socialfeed` ergänzt.

### 1.0.30

- Der Datenschutz-Tab zeigt Anzahl und maximalen Speicherbedarf nicht mehr zugeordneter plugin-eigener Attachments und bietet eine bestätigungspflichtige Bereinigung an.
- Aktuell zugeordnete Medien werden nie berücksichtigt. Anderweitig in WordPress verwendete Dateien bleiben erhalten und verlieren lediglich ihre Plugin-Eigentumsmarkierung.
- Der Ergebnisbericht nennt gelöschte, beibehaltene und fehlgeschlagene Dateien sowie den tatsächlich freigegebenen Speicherplatz.

### 1.0.29

- Der Feed-Block verwendet oben und unten denselben responsiven Sektionsabstand wie der Sunflower-Block „Aktuelles“: `var(--half-block-spacing)` mit einem Fallback von 90 Pixeln.
- Über die Gutenberg-Abstandseinstellungen individuell gesetzte Innenabstände überschreiben diesen Standard weiterhin.

### 1.0.28

- Für die optionale Block-Überschrift stehen Theme-Schriftarten und Theme-Schriftgrößen sowie eine freie Pixelgröße zur Auswahl.
- Zusätzlich lassen sich Textfarbe, links-/mittig-/rechtsbündige Ausrichtung, normaler oder fetter Schriftschnitt, normaler oder kursiver Stil und der Abstand zum Feed konfigurieren.

### 1.0.27

- Der Gutenberg-Block kann optional eine eigene Überschrift ausgeben; ihre Ebene ist zwischen H2 und H6 wählbar.
- Die Überschrift liegt im selben Wrapper wie der Feed und wird deshalb sowohl vom inhaltsbreiten als auch vom viewportbreiten Sektionshintergrund umfasst.

### 1.0.26

- Der Gutenberg-Block bietet eine eigene Hintergrundauswahl mit allen Farben der aktiven Theme-Palette und zusätzlich `Grüner Sand` (`#F3FAF6`) wie beim Sunflower-Block „Aktuelles“.
- Unabhängig von der Farbe lässt sich auswählen, ob der Hintergrund auf die Inhaltsbreite begrenzt bleibt oder bis an beide Viewportränder reicht. Der Feed-Inhalt selbst behält dabei seine normale Breite.

### 1.0.25

- Der Gutenberg-Block unterstützt native Hintergrundfarben und Verläufe aus der aktiven Theme-Palette sowie konfigurierbare Innenabstände und vertikale Außenabstände.
- Die Block-Support-Klassen werden über den offiziellen WordPress-Wrapper auch im Frontend ausgegeben.

### 1.0.24

- Die Gutenberg-Vorschau verwendet `useBlockProps` und ist damit wieder als normaler Block auswählbar – einschließlich Block-Werkzeugleiste und Einstellungsbereich.
- „Mehr anzeigen“-Verlauf und Play-Symbole werden im Editor frontendnah, aber bewusst ohne Funktion dargestellt.

### 1.0.23

- Videos werden erst nach dem vollständigen Abschluss der Schließanimation pausiert. Dadurch wird auch ein während des Zusammenklappens neu gestartetes Video zuverlässig erfasst.
- Ein Sicherheits-Timer führt den Abschluss auch dann aus, wenn ein Browser kein `transitionend`-Ereignis liefert.

### 1.0.22

- Der geöffnete Feed besitzt eine sticky Schaltfläche „Feed schließen“. Sie bleibt innerhalb des Feed-Bereichs am unteren Fensterrand erreichbar und liegt nach Erreichen des Endes unter den Beiträgen.
- Beim Schließen werden sämtliche Videos des Feeds pausiert – auch über native Browsersteuerungen gestartete Wiedergaben. Anschließend klappt der Feed wieder auf die Vorschauhöhe zusammen und der Feedanfang wird in den sichtbaren Bereich geholt.

### 1.0.21

- Medien verwenden ihre natürlichen lokalen Proportionen. Eine Grid-Zeile wird nur dann auf eine gemeinsame Höhe gebracht, wenn ihre Medien tatsächlich unterschiedlich hoch sind; bereits gleich hohe Medien bleiben unangetastet.

### 1.0.20

- Beitragsdaten verwenden wieder ausgeschriebene deutsche Monatsnamen im Format `17. Mai 2026`.

### 1.0.19

- Beitragstexte mit mehr als vier sichtbaren Zeilen sind wieder standardmäßig eingeklappt und über „Mehr anzeigen“ vollständig lesbar.
- Die Entscheidung basiert auf der tatsächlichen Darstellungshöhe und nicht mehr auf der konfigurierten Textlänge; die Gutenberg-Vorschau verwendet dieselbe Vier-Zeilen-Begrenzung.

### 1.0.18

- Beitragsdaten werden im deutschen Format `TT.MM.JJJJ` ausgegeben.
- Die dynamische Gutenberg-Vorschau lädt das Feed-Stylesheet und entspricht damit wieder dem responsiven Frontend-Grid.

### 1.0.17

- Der Gutenberg-Block übernimmt ohne eigene Overrides die globalen Darstellungseinstellungen; 24 konfigurierte Beiträge ergeben daher auch 24 Frontend-Beiträge.
- Die zuverlässige Zwei-Frame-Aufslide-Animation dauert wieder zwei Sekunden.

### 1.0.16

- Ausgangs- und Zielhöhe des Feeds werden in getrennten Renderzyklen gesetzt, sodass das Aufsliden zuverlässig animiert wird.
- Die gleichmäßigere Aufslide-Animation dauert jetzt 3,5 Sekunden.

### 1.0.15

- `content-visibility` wurde von den Feed-Beiträgen entfernt, damit sichtbare native Videosteuerungen und ihre Klickflächen deckungsgleich bleiben.

### 1.0.14

- Native Videosteuerungen werden über `preload="metadata"` zuverlässig initialisiert; Videos im inaktiven Template bleiben bis zum Aufklappen ungeladen.
- Solange der Verlauf sichtbar ist, blockiert er die darunterliegenden Inhalte wieder bewusst.

### 1.0.13

- Der Feed blendet responsiv nach etwa 25 Prozent der zweiten Beitragszeile aus und klappt per „Mehr anzeigen“ ruhig nach unten auf.
- Ein inaktives HTML-Template, Lazy-Loading, `content-visibility` und `preload="none"` verhindern das anfängliche Laden ausgeblendeter Medien.
- Die Aufslide-Animation dauert zwei Sekunden; der Verlauf blockiert keine Videosteuerung und besuchte Instagram-Symbole wechseln nicht mehr die Theme-Farbe.

### 1.0.10

- Überzählige lokale Beiträge können sofort oder nach einer wählbaren Aufbewahrungsfrist samt unreferenzierten Plugin-Medien gelöscht werden.
- Medien selbst sind nicht mehr verlinkt; der Instagram-Beitragslink erscheint als rechtsbündiges Symbol unter dem Medium.
- Der native Video-Downloadeintrag und das Kontextmenü auf lokalen Videos werden unterdrückt.

### 1.0.9

- Benutzername und Beitragsmedium können auf Profil beziehungsweise Beitrag bei Instagram verlinken.
- Vor jeder externen Instagram-Navigation erscheint ein lokaler Bestätigungsdialog; die Einstellung für neue Tabs wird berücksichtigt.

### 1.0.8

- Theme-seitige Abstände für `ul li` werden innerhalb der Interaktionsanzeige zuverlässig auf null gesetzt.

### 1.0.7

- Herz- und Kommentar-Symbol verwenden gleich hohe SVG-Geometrien; auch die Zahlen werden im selben Grau dargestellt.
- Stummschaltung und Lautstärke werden zwischen allen Feed-Videos auf derselben Seite synchronisiert.

### 1.0.6

- Ein per Hover gestartetes Video läuft weiter, bis ein anderes Video startet; die nativen Video-Steuerelemente sind wieder verfügbar.
- Like- und Kommentar-Icons sind einheitlich ausgerichtet und dezenter grau dargestellt.

### 1.0.5

- Lokale Videos starten beim Hover; es läuft immer höchstens ein Video gleichzeitig und das Play-Symbol verschwindet während der Wiedergabe.
- Einheitliche 9:16-Reel-Flächen zeigen Hoch- und Querformat ohne Zuschnitt.
- Vollständige Captions sind aufklappbar; Likes und Kommentaranzahl werden mit `instagram_business_basic` synchronisiert.
- Insights- und Repost-Statistiken werden nicht angefordert.

### 1.0.4

- Darstellungsänderungen lösen beim nächsten Sync eine vollständige Aktualisierung der lokalen Beiträge aus.
- Der Datenschutz-Tab kann alle synchronisierten Beiträge, Zuordnungen und nicht anderweitig verwendeten Plugin-Medien sicher löschen.

### 1.0.3

- Queryfreie OAuth-Callback-URI eingeführt, damit Autorisierungsanfrage und Token-Austausch dieselbe URI verwenden.

### 1.0.2

- Instagram-OAuth-Rücksprünge werden auch dann sicher verarbeitet, wenn Meta an `wp-admin/admin.php` nur `code` und `state` zurückgibt.

### 1.0.1

- Deutsche Übersetzungen für die sichtbare Feed-Ausgabe und die Gutenberg-Blockeinstellungen ergänzt.

### 1.0.0

- Erste produktionsfähige Version mit OAuth, verschlüsselter Tokenablage, lokalem Medienmirror, Karussells, optionalen lokalen Videos, Cron, WP-CLI, Block, Shortcode, Theme-API, Diagnose und Datenschutzprüfung.
