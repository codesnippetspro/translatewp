=== TranslateWP ===
Contributors: codesnippetspro, 0aksmith, ver3, johnpixle, carolinaop, tallblokeuk, lightbulbman, alberttarress, joanrodas
Donate link: https://translatewp.ai/
Tags: translation, multilingual, language switcher, localization, ai translation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Translate WordPress with AI, guide its wording with your Lexicon, and add a language switcher. Translations follow changes to your content.

== Description ==

**TranslateWP** helps you make your WordPress site available in more languages. Connect your site, choose your translation languages and add a language switcher. AI translates your page content using your site's context and writing preferences.

Prepare translations ahead of visits or translate content when it is requested. Finished translations are reused, and changes to your source content are picked up for translation again. Your original pages stay in place: no duplicate pages, no translation tables.

**Need to edit translations or use your own AI provider?** Orbital (Pro) adds the translation editor, advanced SEO tools, the Side tab switcher and your own AI providers through the separate TranslateWP Pro addon.

Built by the team behind [Code Snippets](https://wordpress.org/plugins/code-snippets/).

= Included in the free plugin =

* **AI translation with site context.** Read your site during setup and guide translations with your writing preferences.
* **Your own Lexicon.** Set preferred translations for product names, specialist terms and recurring phrases in each language.
* **Tone and style controls.** Adjust tone, formality and creativity to suit your content.
* **Six switcher placements.** Use a floating switcher, menu item, classic widget, the `[twp-languages]` shortcode, the Language Switcher block or a Button block configured as a switcher.
* **A translation dashboard.** See indexed pages, translation progress, language coverage and work that needs attention.
* **Updates that follow your content.** Source changes are picked up so translations can be refreshed.
* **Language addresses and hreflang.** Give translated versions their own language addresses and identify them for search engines.
* **Roles and multisite.** Choose who may edit translations, retranslate, manage settings or manage the Lexicon. Editing translated text requires the Pro translation editor. Network administrators can set defaults for sites in a network.

TranslateWP requires a connected TranslateWP account and its online translation service, including on the free plan. Setup guides you through connecting your site. You do not need to change DNS or move your site.

= TranslateWP Pro =

Orbital (Pro) extends the free plugin with the separate TranslateWP Pro addon:

* **Translation editor.** Review and correct translated text on your site.
* **Advanced SEO tools.** Manage translated metadata, image alt text and additional search-engine options.
* **Side tab switcher.** Offer a language tab along the edge of the page.
* **Additional writing controls.** Refine translations for your audience, length and cultural adaptation.
* **Your own AI providers.** Connect supported providers and choose a fallback order for each language. Your provider bills for that usage.

The addon requires an eligible TranslateWP account. Once eligibility is confirmed, the free plugin offers to install it. Installing the addon by itself does not unlock Pro features.

= Plans and fair use =

Every plan includes AI translation with a fair-use allowance per 30 days. When your account reaches its allowance, translated pages keep working for visitors and new translation pauses until the window moves on. On Orbital, add your own AI key to keep going.

* **Terrestrial (Free):** Fair-use allowance per 30 days.
* **Orbital (Pro):** A higher fair-use allowance. Add your own AI key for more volume, billed by your provider.
* **Universal (Agency):** Agreed with you, above the Orbital allowance.

Visit [TranslateWP](https://translatewp.ai/) for plan information.

= Support and contributions =

* [WordPress.org support forum](https://wordpress.org/support/plugin/translate/)
* [Source code and bug reports](https://github.com/codesnippetspro/translatewp)
* [Translate the plugin interface](https://translate.wordpress.org/projects/wp-plugins/translate/)

Find TranslateWP useful? [Leave a review on WordPress.org](https://wordpress.org/support/plugin/translate/reviews/) to help others decide whether it suits their site.

== Installation ==

= Automatic Installation =

1. Open **Plugins > Add New** and search for **TranslateWP**.
2. Click **Install Now**, then **Activate**.
3. Open **TranslateWP** in the admin menu and follow setup to connect your account, read your site, choose your writing preferences and select your languages.
4. Configure your language switcher and check a translated page.

= Manual Installation =

1. Download the plugin ZIP and upload it through **Plugins > Add New > Upload Plugin**, or unzip it and upload the `translate` directory to `wp-content/plugins/`.
2. Activate **TranslateWP** and complete setup from its admin menu.

= Multisite Installation =

Network activate TranslateWP from Network Admin to use the network settings and defaults. Check each site's connection, languages and settings before offering translations to visitors.

== Frequently Asked Questions ==

= Do I need a TranslateWP account? =

Yes. The plugin connects to the TranslateWP service to generate and serve translations. The setup wizard guides you through connecting your account and site. A paid plan is not required to use the free features.

= Does it translate every page into every language at once? =

TranslateWP can prepare translations in the background and request translations when content is needed. You can follow progress in the Dashboard. A page that is still being translated may take longer than one whose translation is already ready.

= What happens when I edit my original content? =

TranslateWP detects source changes and queues updated content for translation. Check the Dashboard for progress; a source edit does not mean every language is ready immediately.

= Can I control product names and specialist terms? =

Yes. Add them to your Lexicon and set the wording you want in each language. You can also adjust the writing settings. The Pro translation editor lets you correct translated text directly.

= Does it work with my theme or page builder? =

TranslateWP works with rendered page text rather than requiring a separate translated copy of each post. Test your theme, builder and interactive elements on a staging site. Compatibility can depend on how a theme or plugin renders and updates its content.

= Can search engines find translated pages? =

Translated versions have language addresses and hreflang links. Search visibility also depends on your site's indexing settings and translation readiness. Pro adds advanced SEO controls, including translated metadata. No plugin can guarantee indexing or rankings.

= Will my page cache need configuration? =

Your cache must keep different language addresses separate. If your site still uses `?lang=` addresses, do not strip the language parameter or serve the default-language page for it. Check translated pages with your cache enabled, especially after changing cache rules.

= Are translations stored as duplicate WordPress pages? =

No. TranslateWP adds no translation tables and no duplicate pages. Your original posts and pages remain the content you edit.

= Can I use my own AI provider? =

Yes, with Orbital (Pro) and the Pro addon. Connect a supported provider in the AI models settings and choose the providers for each language. Your provider's own charges and terms apply.

= Does installing the Pro addon activate a paid plan? =

No. Pro features require an eligible TranslateWP account as well as the addon. The free plugin offers installation after checking eligibility.

= Where can I get help? =

Use the plugin's Support screen or the [WordPress.org support forum](https://wordpress.org/support/plugin/translate/). Report reproducible bugs on [GitHub](https://github.com/codesnippetspro/translatewp/issues).

== External services and privacy ==

TranslateWP connects to `api.translatewp.ai` to register your connected site, generate and retrieve translations, report translation progress and check which features your account may use.

The plugin also uses `app.translatewp.ai`, the TranslateWP account portal, to sign in and connect your site, view account information and access plans and upgrades.

After account eligibility is confirmed, the free plugin can download, install and update the Pro addon package from the TranslateWP service.

Translation and site-reading features send the page content selected for processing, along with information such as the site host, home URL, selected languages and translation instructions. The service processes that content to provide translations. This includes content supplied in the site's context and Lexicon when it is needed for a translation.

The plugin also sends sampled translation timing, completion and count measurements to help diagnose delivery performance. These performance reports do not contain page text or full page URLs.

When you connect your site or use these features, the TranslateWP service is involved. Review what you make available for translation and exclude private or sensitive content that should not be processed before scanning or translating your site.

Using your own AI provider on Pro also makes the provider's terms and privacy policy relevant to the content it processes.

* [TranslateWP service terms](https://translatewp.ai/terms)
* [TranslateWP privacy policy](https://translatewp.ai/privacy)

== Upgrade Notice ==

= 1.0.3 =

Lexicon entries are no longer reachable from public pages or WordPress's public routes. Clear any external CDN or edge page cache after updating; TranslateWP clears recognized WordPress page caches once.

= 1.0.2 =

Keeps saved language switchers attached to the correct page element when the WordPress admin bar is visible.

= 1.0.1 =

Fixes sign-in from WordPress Playground and other framed WordPress admin screens.

= 1.0.0 =

AI translation, Lexicon, language switchers and role controls for WordPress. Back up your site and test translated pages, caching and any Pro features before updating a live site.

== Changelog ==

= 1.0.3 =

* Keep Lexicon terms and categories out of public pages and WordPress's public routes; preserve access for authorized editors.
* Fill the page translation store when a visitor and the background indexer request the same text at the same time.
* Preserve crawler settings when saving languages.
* Add full right-to-left layout support and improve admin accessibility.

= 1.0.2 =

* Keep saved switcher anchors stable when the WordPress admin bar or page layout wrappers change.
* Preserve the correct heading when moving a switcher beside repeated headings.

= 1.0.1 =

* Open framed WordPress sign-in in a new tab and resume setup when the connection completes.
* Offer a sign-in link when the browser blocks or refuses the new tab.
* Size language dropdowns to the available screen space and show scroll hints when more languages remain.
* Matte switchers now use the selected border color (light gray by default) and preserve background transparency.
* Show matching TranslateWP and Code Snippets attribution in the Plugins screen.

= 1.0.0 =

* AI translation with site context, writing controls and a Lexicon for preferred terminology.
* Six switcher placements in the free plugin, with language addresses and hreflang.
* Background translation, on-demand requests, progress reporting and refreshes after source changes.
* Role permissions and multisite settings.
* The optional Pro addon adds the translation editor, advanced SEO tools, the Side tab switcher, additional writing controls and your own AI providers.
