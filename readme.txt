=== TranslateWP ===
Contributors: codesnippetspro, 0aksmith, ver3, johnpixle, carolinaop, tallblokeuk, lightbulbman, alberttarress, joanrodas
Donate link: https://translatewp.ai/
Tags: translation, multilingual, language switcher, localization, ai translation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0-beta.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI translation for WordPress. Unlimited websites, unlimited languages, zero word-count billing.

== Description ==

TranslateWP translates your site with AI that understands context, tone and idiom rather than swapping words one at a time. A page is translated on demand the moment a visitor asks for it, and TranslateWP can also prepare pages ahead so the first visitor does not wait. No word-count invoices.

Translations follow your content. Edit your source page and the translated version is brought up to date. No stale copies, nothing to re-run by hand.

Built by the team behind Code Snippets, trusted on over a million WordPress sites.

= Your site translates itself, exactly when it needs to =

You do not have to convert your whole site into every language before a single visitor has read a word. A page is translated the first time it is requested in a language, and TranslateWP can prepare pages ahead of time as well. Each translation is stored, so every visitor after that is served it instantly, with no further AI generation.

= Fair use =

Every plan includes AI translation with a fair-use allowance per 30 days. When your account reaches its allowance, translated pages keep working for visitors and new translation pauses until the window moves on. On Orbital, add your own AI key to keep going.

= Teach the AI your vocabulary with Lexicon =

Every brand has words that should never be guessed: product names, industry terms, phrases you always say one way. Lexicon lets you pin exactly how each term is translated, in each of your languages, so the AI stays on brand everywhere. Your rules overrule the AI.

= Stay in control =

* A dashboard with every language and its translation coverage at a glance.
* Tune how the AI writes with simple controls for tone, formality and creativity.
* Flexible language switchers, all in Free: a floating switcher, a menu item, a classic widget, the [twp-languages] shortcode, the Language Switcher block and a Button block that becomes the switcher.
* Always current: update your source page and the translations follow.
* Install and go: no DNS changes, no migration, no per-post workflow. Activate and pick your languages.

= Privacy friendly =

Page content is translated and cached by the TranslateWP service. Visitor personal data never leaves your site: no customer details, no form submissions and no order data are sent to us.

This plugin connects to the TranslateWP translation service to generate and serve translations. Site content used for translation is sent to that service when you translate or scan pages. See the Privacy section below.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/translatewp` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open **TranslateWP** in the admin menu and complete the setup wizard.
4. Add target languages and configure the language switcher as needed.

== Frequently Asked Questions ==

= Do you charge per word or per translation? =

No. There is no word-count billing at all.

= How is this different from pre-translation plugins? =

They translate every page into every language up front and leave you to manage all the copies. TranslateWP translates a page on demand when a visitor requests it and can also prepare pages ahead, so there is nothing you have to build or keep in sync by hand, and translations follow your edits to the original.

= What happens when I edit a page after it is translated? =

The translation updates with it. TranslateWP notices that the source changed and translates the page again, so your other languages stay in step with your source content automatically.

= Will it slow my site down? =

The first request in a new language is translated and cached. Every visitor after that is served the stored version, so ongoing performance is normal.

= Does it work with my theme and page builder? =

TranslateWP translates the rendered text of your pages, so standard Gutenberg and classic theme content is detected and translated. Builders output rendered text too, so standard builder content should be picked up; formal testing across builders is still in progress.

= Does this plugin require an external service? =

Yes. AI-assisted translation is provided by the TranslateWP API. The free plugin uses that service to translate content.

= Page cache exclusions =


The `lang` query parameter selects the rendered language. Page caches must not strip or normalize it, and must not serve a cached default-language page for a `?lang=` URL. The plugin sends no-cache headers for non-default `?lang=` requests.

== Privacy ==

When you use translation or scanning features, the plugin may send page content samples, your site host, and home URL to `api.translatewp.ai` so the service can translate content and attribute usage to your site. No data is sent until you use those features or complete setup that registers the site with the service.

Service terms: https://translatewp.ai/terms. Privacy policy: https://translatewp.ai/privacy

== Changelog ==

= 1.0.0 =
* First public release. AI translation for WordPress with context, tone and your Lexicon: pages translate on demand and can be prepared ahead, translations follow your edits, and every visitor after the first is served the stored version.
* Six switcher placements, all in Free: floating, a menu item, a classic widget, the shortcode, the Language Switcher block and a Button block, as a dropdown or native dropdown, with an edit mode for managers on the site itself.
* Each language has its own address, with hreflang, so search and answer engines can index it.
* Who can manage translations: give each role the right to edit translations, retranslate, manage the settings or manage the Lexicon; on a network, set defaults for every site.
* With Orbital, the Pro addon adds the translation editor, the Side tab switcher, SEO tools and your own AI providers.
