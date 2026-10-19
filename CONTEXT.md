# TranslateWP

WordPress plugin that translates site content and exposes a public language switcher. Hosted translation uses per-site usage metering.

## Language

**Site**:
A WordPress installation identified by an API-issued UUID for metering and translation-cache attribution; host and home URL are display metadata only.
_Avoid_: domain (when meaning the metered entity), customer, account, siteId (when meaning the old URL-hash cache fingerprint)

**Usage**:
Token consumption from hosted translation work attributed to a Site, observed for ops rather than enforced as a limit.
_Avoid_: rate limit (when meaning observed metering), quota, budget (future enforced caps)

**Usage event**:
One recorded unit of observed Usage for a Site — either a successful translation batch item or a successful context scan.
_Avoid_: usage record, meter row, billable unit (when meaning the persisted event)

**Switcher offset**:
The distance in pixels from the browser window edge(s) used by the active switcher corner.
_Avoid_: change size, custom position offset, screen offset (when meaning Pro Custom)

**Switcher icon size**:
The pixel size of the graphic shown on the closed language switcher trigger.
_Avoid_: change size, trigger size (the full closed control includes padding)

**Switcher padding**:
The padding in pixels around the closed-switcher icon inside the trigger control.
_Avoid_: change size, trigger size

## Terms

- **Default language**: the language the site's content is written in; translations are made from it. UI help line: "The language your content is written in." Avoid: main language, source language, original language, base language. Code identifiers such as `sourceLang` and `languages.list[].default` keep their names.
- **Translation languages**: the languages the content is translated into. Avoid: target language, website languages.
