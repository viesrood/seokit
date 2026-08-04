# Changelog

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
