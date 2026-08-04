# SeoKit

SEO for **Craft CMS 5** that is already correct before you configure it.

```twig
{# in your layout, once #}
{{ craft.seokit.head() }}
```

That is the whole setup. Every page now has a title, a meta description, a
canonical, a robots directive, a full Open Graph set and a JSON-LD entity
graph, all derived from the content that is already there.

## Why another SEO plugin

Three decisions, and everything else follows from them.

**It works before it is configured.** An empty install produces correct
output. Settings are deviations from what SeoKit already derives, never
prerequisites. There is no per-section meta bundle to fill in before a page
gets a title.

**Every value carries its reason.** Ask any page why it says what it says:

```
<!-- SeoKit
  title            Werk - Viesrood        (derived: element title)
  description      Een multidisciplinair… (derived: field "intro")
  canonical        https://…/werk         (derived: element URL)
  robots           index, follow, max-image-preview:large  (derived)
-->
```

That comment is devMode-only, and the same trail is on the entry's SEO tab.
"Where does this og:image come from" is the question a configurable SEO plugin
makes expensive to answer.

**It renders where you put the tag.** No meta containers, no plugin deciding
where your head begins. What is in your template is what is in your head,
which means it behaves under a static page cache and can be read in the
markup.

There is one wrinkle worth knowing about. A Twig layout prints the head before
it renders the blocks inside it, so a `craft.seokit.set()` in a block, or in
an include three levels down, runs too late to be included. SeoKit marks the
head it wrote and re-renders that block at the end of the page if a late
override turns up, so the call still works. Position stays yours; timing is
not a trap.

## What you get

- Title, description, canonical, robots, hreflang, Open Graph, X cards
- A JSON-LD `@graph` with `@id` references: Organization, WebSite, WebPage,
  BreadcrumbList, plus a profile node per entry type
- Sitemap index and per-section sitemaps with `lastmod` and images
- robots.txt managed in the control panel, with the Disallow trap spelled out
- One compact field: title, description, sharing image, index switch,
  canonical. Five things, with live search and social previews
- Schema profiles guessed from entry type handles, in Dutch and English
- `VideoObject` nodes for embedded video, from the oEmbed data your template
  already fetched
- Per entry type, in the control panel: which field the title, description and
  sharing image should come from

## What you deliberately do not get

- **No keywords field.** Ignored by every search engine since 2009.
- **No FAQ screen.** FAQ rich results stopped appearing in Google on
  7 May 2026. `FAQPage` markup is still valid and still read by AI systems,
  but it is not a search feature to build a UI around.
- **No priority or changefreq in the sitemap.** Google ignores both.
- **No 1-100 score.** Green means nothing. Actions mean something.
- **No free-form JSON-LD editor.** Hand-written structured data drifts from
  the page it describes within a month, and wrong structured data is worse
  than none.

## Video

A template that embeds a video registers it:

```twig
{% do craft.seokit.video({
    name: media.title,
    description: block.desc,
    thumbnailUrl: media.image,
    uploadDate: media.publishedTime,
    embedCode: media.code,
    url: media.url,
}) %}
```

Why the template and not the plugin: the template just fetched all of that
from the video host, and asking again would be a second HTTP request per
video for data already in hand. `embedCode` is only used to read the `src` of
the provider's own iframe, which is the embed URL by definition and needs no
per-host knowledge.

Google wants a name, a description, a thumbnail and an upload date before it
does anything with a video. Missing any of them means the node is left out
rather than published incomplete: an error in Search Console costs attention,
a missing node costs nothing that was going to work anyway. The upload date
falls back to the page's publication date, because Vimeo's oEmbed response
does not include one at all.

## Redirects

SeoKit does not do redirects and will not grow them. Install
[BridgeKit](https://github.com/viesrood/bridgekit) for that. When it is
present, SeoKit asks it which paths are spoken for and leaves them out of the
sitemap: a redirecting URL in a sitemap is an error Search Console reports on
every crawl, and neither plugin can see it alone.

## Choosing the fields yourself

The guesser is a good default and a bad promise: it can only be right about
sites whose fields are named the way it expects. Under **SEO → Overview**,
every entry type has three dropdowns: title from, description from, image
from. Leave them on Automatic and nothing changes; point one at a field and
that field wins.

Three rules make this safe to use:

- **A chosen field that is empty on a page falls back to the guess.** Naming a
  field never leaves a page without a description.
- **The choice applies to the field, not to what is nested inside it.** Point
  at a Matrix field and SeoKit reads its blocks, but back on its own
  judgement in there: block settings like `section-b-padding` never end up in
  a meta description.
- **Entry types are listed once.** They are global in Craft 5 and often shared
  between sections, so one entry type is one row however many sections use it.

The origin trail says which of the two answered:

```
description   …  (derived: chosen field "summary")
image         …  (derived: guessed from field "heroImage")
```

## Setting up the override field

```bash
php craft seokit/setup/field --dry-run
php craft seokit/setup/field
```

Creates the field and adds it, in its own tab, to every entry type whose
section has public URLs. Idempotent, so run it again after adding a section.
Use `--entry-types=all` to include the ones without URLs, `--entry-types=none`
to only create the field, and `--tab=…` when another plugin already owns a tab
called SEO.

## Overriding

In a template, before `head()`:

```twig
{% do craft.seokit.set({
    title: 'Postduif - link verlopen',
    indexable: false,
}) %}
```

On an entry: add the SEO field to the field layout. Empty inputs show the
derived value as their placeholder, so an editor sees the real title and
description rather than an empty box implying something is missing.

## Migrating from SEOmatic

```bash
php craft seokit/import/seomatic --dry-run
php craft seokit/import/seomatic
```

Imports the site identity: organization, address, social profiles, default
image, X handle. Per-section title and description settings are not imported,
because those are Twig expressions and derivation replaces them.

```bash
php craft seokit/import/entries --dry-run
php craft seokit/import/entries
```

Moves the per-entry overrides somebody actually typed into the SeoKit field,
ignoring SEOmatic's own Twig-expression defaults. Worth running before
removing SEOmatic: those hand-written titles are invisible until they are
gone.

Then turn off SEOmatic's rendering, or every page gets two titles. Its
`renderEnabled`, `sitemapsEnabled` and `headersEnabled` settings are the
switches; the robots.txt route stays SEOmatic's until the plugin is removed.

## Requirements

Craft CMS 5.0+, PHP 8.2+.

## Licence

MIT.
