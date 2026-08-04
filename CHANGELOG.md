# Changelog

## 1.0.0-alpha.4 - unreleased

### Added
- Pages are served as markdown to callers that ask for it: an
  `Accept: text/markdown` header on the page's own URL, a `.md` suffix on it,
  or the `<link rel="alternate">` that points at the second. A page of a real
  site is roughly 16,000 tokens as HTML and roughly 3,000 as markdown, and
  agents send that header today, which is more than can be said for any of the
  conventions invented for this. The negotiation is a real q-value comparison
  rather than a substring test: markdown has to be named explicitly and beat
  every claim HTML makes, wildcards included, because the alternative is
  handing raw markdown to a browser and never hearing about it
- Both variants carry `Vary: Accept`. Without it a CDN caches whichever it saw
  first and serves that to everyone, which is the failure mode that makes
  content negotiation look unreliable
- AI crawler rules in robots.txt, as two switches rather than one. Refusing to
  be training data (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended and
  company) costs nothing in search results; refusing the answer engines
  (OAI-SearchBot, Claude-SearchBot, PerplexityBot) removes the site from AI
  answers including the links back. Those are different decisions and they do
  not belong on one axis. A disabled group writes nothing at all, because an
  explicit `Allow: /` group for a bot silently cancels every rule the `*` group
  has for it
- A `Content-Signal` line to state intent alongside those rules, with a warning
  when it claims the opposite of what the switches do
- `/llms.txt`, listing the same pages as the sitemap, capped per section and
  overall, with the truncation written into the file rather than left to the
  reader to discover. Delivered with a caveat that is also in the README: in a
  90-day sample, 84 of 62,100 AI bot requests asked for this file. It is here
  because it costs one route, not because the crawlers read it
- An "AI agents" screen for all of the above, and a per entry type markdown
  switch on the overview that follows "In search engines" the way the sitemap
  toggle already does
- `craft.seokit.video()`: templates register the videos they embed and those
  become `VideoObject` nodes in the graph. The call takes the oEmbed response
  the template already holds, so it costs no extra request to the video host.
  A node missing a thumbnail or an upload date is left out rather than
  published incomplete, and the upload date falls back to the page's own
  publication date because Vimeo's oEmbed does not carry one
- Person pages get `jobTitle` and `email` when the entry has them, and an
  article's author is linked by `@id` to their own profile page when the site
  has one. A name is a string; a name that points at a page is a person
- `openingHours` for a local business
- Optional cooperation with BridgeKit: when it is installed, URLs that
  redirect are left out of the sitemap. Neither plugin can see that on its
  own, and Search Console reports every redirecting sitemap URL as an error
- A test suite: 43 unit tests over the text extraction, the resolution's
  fallback rule and the settings normalisation, plus PHPStan at level 6

### Fixed
- The sitemap cache never expired on an entry save. It depended on the tag
  `element::craft\elements\Entry`, which Craft only invalidates when a section
  or entry type changes; a saved entry invalidates `element::…::*`. The sitemap
  was therefore up to 24 hours stale, and nothing about it looked wrong
- The robots.txt screen listed Disallow rules from named user agent groups
  under the warning that Disallow does not mean "keep out of Google". It now
  only lists rules that reach every crawler, so a deliberate `Disallow: /` for
  GPTBot is not presented as a mistake
- Only closing tags counted as word separators when stripping markup, so text
  before a list ran into its first item (`twee<li>drie` became `tweedrie`)
- A sentence ending at exactly half the description budget was rejected, even
  though a whole sentence beats a longer fragment
- A branch in the content extraction that could never run
- The WebPage node referenced a BreadcrumbList that is not emitted for a
  single-crumb page, leaving a dangling `@id` on every homepage

## 1.0.0-alpha.3 - unreleased

### Added
- Per entry type: "In search engines". A whole section that must never be
  indexed is a setting, not something to repeat in a template. The sitemap
  toggle follows it, so the two can no longer contradict each other
- robots.txt refuses to serve a crawlable body outside a production
  environment, whatever the settings say. `productionEnvironments` decides
  which names count

### Fixed
- A `craft.seokit.set()` inside a `{% block %}`, or in an include below it,
  ran after the layout had already printed the head and was silently lost.
  head() now marks its output; if an override arrives late, the block is
  re-rendered once the page is finished. Position stays the template's
  decision, timing is no longer a trap
- A template that sets `robots` itself is no longer overwritten by the
  composed directive

## 1.0.0-alpha.2 - unreleased

### Added
- Per entry type, in the control panel: which field the title, description and
  sharing image are read from. Automatic by default; a chosen field that is
  empty on a page falls back to the guesser
- `seokit/setup/field` console command: creates the override field and adds it
  to the field layouts of entry types with public URLs
- `seokit/import/entries` console command: moves per-entry SEOmatic overrides
  into the SeoKit field, skipping SEOmatic's Twig-expression defaults

### Fixed
- Entry types shared between sections were listed once per section on the
  overview, and the lower row silently overwrote the choice made in the one
  above it when saving
- Entry type settings are read through one normaliser. A control panel
  lightswitch posts `""` when off, which `=== false` does not catch, so a
  section excluded from the sitemap reappeared in it after the first save
- Values without spaces (`section-b-padding`, `default`) are no longer treated
  as prose when guessing a description out of block fields

## 1.0.0-alpha.1 - unreleased

Phase 1: replaces SEOmatic for meta, sitemaps and robots.txt.

### Added
- Meta resolution with a visible origin trail: derive, entry type profile,
  field, template override
- `craft.seokit.head()`, plus `title()`, `meta()`, `jsonLd()` and `set()`
- SEO field with live search and social previews
- JSON-LD `@graph` with `@id` references
- Sitemap index and per-section sitemaps with `lastmod` and images
- robots.txt served from settings
- Control panel: overview with status checks, settings, robots and sitemap
- `seokit/import/seomatic` console command
