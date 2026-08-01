=== Vemoro SocialFeed ===
Contributors: vemoro
Tags: instagram, privacy, local media, feed, gutenberg
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.1.2
Donate link: https://vemoro.de/unterstuetzen/
License: GPLv2 or later

Synchronizes a professional Instagram account server-side and renders a feed using only local WordPress media.

== Description ==

Vemoro SocialFeed uses Meta's official Instagram API with Instagram Login. It stores required content in WordPress so ordinary frontend views do not need Meta scripts, embeds, API calls, or CDN media. The recommended Vemoro Login does not require a Meta App Secret in WordPress; an own Meta app remains available as expert mode.

The recommended login uses the external Vemoro connection service at connect.vemoro.de. When the administrator deliberately starts a connection, WordPress transmits the WordPress callback URL, a random security state, plugin version and site URL to that service. The service communicates with Instagram, keeps an OAuth flow for at most ten minutes and an encrypted one-time grant for at most two minutes. It does not retain the permanent Instagram token. Service privacy information: https://vemoro.de/socialfeed/datenschutz/ . Terms: https://vemoro.de/nutzungsbedingungen/ . Data deletion instructions: https://vemoro.de/datenloeschung/ .

Visitors do not connect to Meta on ordinary page views. Only the website operator's server communicates with the Instagram API during OAuth, synchronization, and token maintenance. Temporary API or token failures never trigger immediate deletion. A confirmed permanent disconnect removes the token and all API-derived Platform Data; plugin-owned media are deleted only when they are not referenced elsewhere in WordPress.

Missing posts are removed from the public feed only after three complete authoritative synchronizations and an optional grace period of up to 48 hours. The plugin synchronizes only media returned by Meta as media owned by the connected account. Native reposts and Collab posts owned by another account are not reliably returned and are therefore not supported or heuristically classified.

The plugin does not claim that a particular use is legally GDPR compliant. Operators remain responsible for published content, image rights, personal data, and their privacy notice.

See README.md for complete setup, security, WP-CLI, cron, troubleshooting, and browser acceptance instructions.

== Installation ==

1. Activate the plugin.
2. Keep the recommended Vemoro Login selected, or configure an own Meta app in expert mode.
3. Review and accept the linked Vemoro connection service terms and acknowledge its privacy notice.
4. Connect a Business or Creator account.
5. Run the first synchronization and add the block or shortcode.

== Upgrade Notice ==

= 2.1.0 =
The folder changes to `vemoro-socialfeed`. Deactivate 2.0.3 without uninstalling it, replace the old folder with the new one and reactivate. Existing `lif_*` data and integrations remain unchanged. Keep uninstall data deletion disabled.

== Voluntary support ==

Vemoro SocialFeed for WP remains free of charge, without advertising or tracking. Voluntary contributions through Liberapay or GitHub Sponsors help fund maintenance, security updates, hosting and operation of the Vemoro connection service.

All signed-in users see the support notice immediately in the WordPress backend. It can be postponed for 120 days or permanently hidden per user. The plugin does not load external resources for these notices; a connection to a funding service is made only after its link is clicked. Supporting is entirely voluntary and does not change the available features.

For technical questions and problems, contact support@vemoro.de. The address is displayed in the backend notice and the plugin administration area.

== Changelog ==

= 2.1.2 =
* Stores versioned acceptance of the Vemoro terms and privacy notice in the WordPress settings.
* Moves the acceptance control next to the Vemoro Login selection.
* Collapses expert-mode Meta App credentials until that connection method is selected.
* Sends one-time OAuth grants as JSON and remains compatible with the broker's form-encoded transition endpoint.

= 2.1.1 =
* Repairs missing synchronization schedules automatically after WordPress or Action Scheduler initialization.
* Detects an existing WP-Cron fallback even when Action Scheduler is installed.
* Uses WP-Cron safely when Action Scheduler is loaded but not ready or cannot create an action.
* Makes hosted OAuth callbacks independent of legacy WordPress admin-page slugs.

= 2.1.0 =
* Migrates the WordPress.org distribution slug, main file, text domain and language files to `vemoro-socialfeed`.
* Preserves all `lif_*` data, the legacy block, shortcode, theme function and WP-CLI command.
* Adds WordPress Privacy Policy Guide content and fully discloses the hosted OAuth service, data transfer, privacy information and terms.
* Requires explicit, server-validated acceptance of the linked terms before a hosted Vemoro Login starts.
* Prepares the official WordPress.org contributor, donation and upgrade metadata.

= 2.0.3 =
* Adds support@vemoro.de to the backend notice, plugin administration area and documentation.

= 2.0.2 =
* Shows the support notice immediately to all signed-in WordPress backend users while keeping postponement and permanent dismissal user-specific.

= 2.0.1 =
* Removes unreliable Collab and repost filters and documents that only the account-owned media returned by Meta is synchronized.
* Adds an optional missing-post grace period of up to 48 hours and ensures confirmed removed posts no longer remain public.
* Makes permanent disconnect delete API-derived Platform Data after explicit confirmation while temporary API and token failures retain data for reconnection.
* Clarifies frontend privacy, server-side Meta communication, data deletion, and uninstall behavior.
* Adds privacy-friendly voluntary support links for Liberapay and GitHub Sponsors with a delayed, postponable and permanently dismissible administrator notice.

= 2.0.0 =
* Rebranded as Vemoro SocialFeed for WP.
* Added the hosted Vemoro OAuth connection as the default and retained own-app expert mode.
* Added new Vemoro block, shortcode, template function and WP-CLI aliases without removing legacy integrations.

= 1.0.30 =
* Fuegt eine bestaetigungspflichtige Bereinigung fuer nicht mehr zugeordnete plugin-eigene Medien hinzu.
* Schuetzt aktuell zugeordnete und anderweitig in WordPress verwendete Dateien und meldet den freigegebenen Speicherplatz.

= 1.0.29 =
* Uebernimmt fuer den Feed-Block den responsiven vertikalen Sektionsabstand des Sunflower-Aktuelles-Blocks.

= 1.0.28 =
* Erweitert die optionale Ueberschrift um Theme-Schriftarten und -groessen, freie Schriftgroesse, Farbe, Ausrichtung, Schriftschnitt, Schriftstil und Abstand.

= 1.0.27 =
* Ergaenzt eine optionale Block-Ueberschrift mit waehlbarer Ebene H2 bis H6 innerhalb des Feed-Hintergrunds.

= 1.0.26 =
* Fuegt dem Gutenberg-Block eine eigene Hintergrundauswahl aus Theme-Farben plus Gruener Sand hinzu.
* Der Hintergrund kann wahlweise auf die Inhaltsbreite begrenzt oder ueber die gesamte Viewportbreite ausgedehnt werden.

= 1.0.25 =
* Aktiviert native Gutenberg-Hintergrundfarben, Farbverlaeufe sowie Innen- und vertikale Aussenabstaende fuer den Feed-Block.

= 1.0.24 =
* Bindet die Gutenberg-Vorschau ueber useBlockProps korrekt als auswaehlbaren Block mit Werkzeugleiste und Inspector ein.
* Zeigt Verlauf, Mehr-anzeigen-Schaltflaeche und Play-Symbole im Editor als frontendnahe, nicht interaktive Vorschau.

= 1.0.23 =
* Pausiert alle Feed-Videos erst nach dem vollstaendigen Abschluss der Schliessanimation; ein Sicherheits-Timer deckt fehlende transitionend-Ereignisse ab.

= 1.0.22 =
* Zeigt im geoeffneten Feed eine sticky Schaltflaeche zum erneuten Schliessen und legt sie am Feed-Ende unter den Beitraegen ab.
* Pausiert beim Schliessen saemtliche Videos des Feeds, auch bei nativer Wiedergabe.

= 1.0.21 =
* Verwendet die natuerlichen lokalen Medienproportionen und gleicht eine Grid-Zeile nur bei unterschiedlichen Medienhoehen an.

= 1.0.20 =
* Zeigt Beitragsdaten mit ausgeschriebenem lokalisiertem Monat im Format 17. Mai 2026 an.

= 1.0.19 =
* Klappt Beitragstexte anhand ihrer tatsaechlichen Hoehe nach vier Zeilen ein, unabhaengig von der gespeicherten Textlaenge.
* Begrenzt lange Beitragstexte auch in der Gutenberg-Vorschau auf vier Zeilen.

= 1.0.18 =
* Zeigt Beitragsdaten im deutschen Format TT.MM.JJJJ an.
* Laedt das Feed-Stylesheet auch fuer die dynamische Gutenberg-Vorschau.

= 1.0.17 =
* Der Gutenberg-Block uebernimmt ohne eigene Overrides alle globalen Darstellungseinstellungen einschliesslich der Beitragszahl.
* Setzt die Aufslide-Dauer bei beibehaltener Zwei-Frame-Animation auf zwei Sekunden.

= 1.0.16 =
* Erzwingt getrennte Renderzyklen fuer Start- und Zielhoehe und verlaengert das sichtbare Aufsliden auf 3,5 Sekunden.

= 1.0.15 =
* Entfernt content-visibility von Feed-Beitraegen, damit Darstellung und Klickflaechen nativer Videosteuerungen deckungsgleich bleiben.

= 1.0.14 =
* Initialisiert die nativen Videosteuerungen wieder ueber Metadaten, waehrend nachgelagerte Videos bis zum Aufklappen inaktiv bleiben.
* Der sichtbare Verlauf blockiert die darunterliegenden Inhalte wieder bewusst.

= 1.0.13 =
* Verlaengert die Aufslide-Animation auf zwei Sekunden.
* Der Feed-Verlauf blockiert keine Videosteuerung mehr; besuchte Instagram-Symbole behalten ihre Farbe.

= 1.0.12 =
* Blendet den Feed responsiv nach etwa 25 Prozent der zweiten Beitragszeile aus und oeffnet ihn per Schaltflaeche mit einer ruhigen Aufslide-Animation.
* Reduziert initiale Browserarbeit durch Lazy-Loading, content-visibility und Video-Preload none.

= 1.0.10 =
* Adds configurable retention for locally stored posts exceeding the synchronization limit.
* Removes links from media and replaces the text link with a right-aligned Instagram icon.
* Hides the native video download action and suppresses the video context menu.

= 1.0.9 =
* Links usernames to Instagram profiles and post media to their Instagram posts when external links are enabled.
* Adds a local privacy confirmation dialog before every Instagram navigation and respects the new-tab setting.

= 1.0.8 =
* Resets theme-provided list-item margins inside the interaction row.

= 1.0.7 =
* Uses geometrically aligned interaction icons and applies the same gray to counts.
* Shares mute and volume changes between all feed videos on the current page.

= 1.0.6 =
* Keeps a hovered video playing until another video starts and restores native video controls.
* Aligns interaction icons and uses a softer gray icon color.

= 1.0.5 =
* Adds local hover playback with only one active video at a time.
* Uses a consistent 9:16 Reel stage with non-cropping letterboxing.
* Synchronizes and displays like and comment counts without loading comments.
* Adds expandable full captions and suppresses detected reposts without extra permissions.

= 1.0.4 =
* Added a full post refresh after display changes and a privacy-tool action for deleting all synchronized data.

= 1.0.3 =
* Use a query-free OAuth callback URI so the authorization request and token exchange remain identical.

= 1.0.2 =
* Accept Instagram OAuth callbacks when Meta returns only code and state on wp-admin/admin.php.

= 1.0.1 =
* Added German translations for the frontend feed and Gutenberg controls.

= 1.0.0 =
* Initial release.
