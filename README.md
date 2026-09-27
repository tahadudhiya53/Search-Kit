# Search Kit

Search management and search intelligence for Craft CMS 5.

Search Kit gives a Craft site a proper search layer: configurable indexes, a query pipeline that
understands what visitors actually type, deliberate control over what ranks where, a record of what
people search for, and a debugger that explains any search.

It works out of the box on Craft's own search index — no external service required — and can serve
any index from a Meilisearch server instead.

---

## What it does

- **Search** from Twig, PHP, HTTP or GraphQL, with filters, ranges, facets, sorting, pagination,
  snippets and highlighting.
- **Indexing** that follows every content change, on Craft's queue, with retries, failure reporting
  and rebuilds.
- **Query handling** — normalization, search operators, partial matching, stop words, synonyms,
  spelling correction, autocomplete and no-result suggestions.
- **Search rules** — boost, bury, hide, pin, promote and redirect, on a schedule and a priority.
- **Search activity** — what was searched for, what came back and what was opened, with nothing
  recorded about who searched.
- **A dashboard** showing how search is doing, and a page that reads the same activity further into
  a quality score, changes worth knowing about, content gaps, synonym candidates and
  recommendations.
- **A debugger** that runs a real search and explains it step by step.
- **Craft Commerce support**, where Commerce is installed — products and variants, indexed,
  filtered and merchandised by the same machinery as everything else.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- Craft Commerce 5 (optional)
- A Meilisearch 1.x server (optional)

## Installation

```bash
composer require tahadudhiya/craft-search-kit
php craft plugin/install search-kit
```

Then go to **Search Kit → Indexes**, create an index, choose its provider, the element types and
fields it should search, and the sites it covers. Rebuild it once and it is ready to search.

---

## Searching

`craft.searchKit` is the whole public API in a template:

```twig
{% set q = craft.app.request.getParam('q') %}
{% set results = craft.searchKit.search('siteSearch', q, {
    limit: 10,
    page: craft.app.request.getParam('page') ?: 1,
    highlight: true,
}) %}

{% for hit in results.hits %}
    <a href="{{ hit.element.url }}">{{ hit.element.title }}</a>
    <p>{{ hit.highlight }}</p>
{% endfor %}

{% if results.hasNextPage %}<a href="?q={{ q }}&page={{ results.nextPage }}">Next</a>{% endif %}
```

The same options build a query in PHP:

```php
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\models\SearchQuery;

$result = SearchKit::getInstance()->getSearch()->search(
    SearchQuery::create('siteSearch', 'winter boots', ['limit' => 10]),
);
```

### Options

| Option | What it does |
|---|---|
| `limit` | Hits per page. Defaults to 20, maximum 1000. |
| `offset` / `page` | Where in the results to start. |
| `site` / `sites` | Narrows the index's site scope — never widens it. |
| `status` | An element status. Anything other than published needs a signed-in administrator. |
| `filters` | Constraints on what may match. |
| `facets` | Fields to count the result set by. |
| `orderBy` | `'title asc'`, `'score desc, title asc'` or `{ title: 'asc' }`. Defaults to relevance. |
| `highlight` | Work out matched fields, snippets and highlights. Off by default. |
| `snippetLength` | Roughly how long a snippet may run. Defaults to 200 characters. |

Options are checked rather than coerced: a bad value raises an exception with an explanation instead
of quietly returning nothing.

### Filters and facets

A filter is a field, an operator and a value. Operators are `eq`, `neq`, `in`, `notIn`, `gt`, `gte`,
`lt`, `lte` and `between`.

```twig
{% set results = craft.searchKit.search('siteSearch', q, {
    filters: {
        section: ['news', 'blog'],
        postDate: { gte: '2024-01-01' },
        price: { between: [100, 500] },
    },
    facets: ['sectionId', 'elementType'],
}) %}

{% for value in results.getFacet('sectionId').values %}
    <a href="?q={{ q }}&section={{ value.value }}">{{ value.value }} ({{ value.count }})</a>
{% endfor %}
```

Filters may name element criteria Craft already exposes (`section`, `type`, `group`, `volume`,
`relatedTo`, `title`, `postDate` and so on) and any custom field the index searches. Facet counts
describe the whole result set, not the current page, so filtering by a counted value returns exactly
the number the facet reported.

### Results

A result reports `total`, `limit`, `offset`, `page`, `pageCount`, `hasNextPage`, `hasPreviousPage`,
`nextPage` and `previousPage`, plus the hits themselves. It also carries `correctedText` when the
query was corrected, `suggestions` when it found nothing, and `redirect` when a rule offers one.

Search covers published content. Anything else requires a signed-in administrator, and every result
is put to Craft's own permission check. Every hit names the site it was found in.

### Sites

An index covers one site or every site, and a query may narrow that scope but never widen it. There
is no current-site fallback.

```twig
{% set results = craft.searchKit.search('siteSearch', q, { sites: ['default', 'demoUk'] }) %}
```

Query text is read in the language of each site being searched, so a search covering sites in
different languages is read in each of them rather than having one stand in for the rest.

---

## Search behaviour

How a query is read belongs to the index, under **Search behaviour** on its edit page, so every
search of an index behaves the same way. None of it changes what is indexed, so changing it never
costs a rebuild.

**Normalization.** Query text and indexed content are reduced the same way Craft reduces keywords —
lowercased, stripped of markup, punctuation, diacritics and emoji — so `Café` finds `cafe`.

**Operators**, on by default:

| Written | Means |
|---|---|
| `winter boots` | Both words have to match. |
| `"winter boots"` | The words have to appear together, in that order. |
| `-leather` | Rules out anything matching it. |
| `boots OR shoes` | Either one is enough. |
| `boot*` / `*boot*` | Matches from the start of a word, or anywhere in it. |

**Partial matching** decides how much of a word a term has to cover when no operator says otherwise.
**Stop words** drops words too common to narrow anything down, and extra ones can be configured.

**Synonyms** are managed under **Search Kit → Synonyms**, two-way (`boots, footwear, shoes`) or
one-way (`tv → television`), scoped to an index and a site.

**Spelling correction** runs only when a search finds nothing: each word the index has never seen is
replaced by the closest one it holds, and the search is tried once more.

```twig
{% if results.wasCorrected %}
    <p>Showing results for <em>{{ results.correctedText }}</em></p>
{% endif %}
```

**Suggestions and autocomplete** are drawn from the words the index actually holds, so they never
lead to another empty result and never name content the person searching may not see:

```twig
{% set completions = craft.searchKit.autocomplete('siteSearch', q, { limit: 5 }) %}
```

An index can also complete what is being typed with whole queries people have searched for before.
That is off until it is turned on, and a past query is only offered once it has led somewhere real,
been searched for repeatedly across separate days, and every word of it still belongs to publicly
searchable content.

**Snippets and highlighting** come with `highlight: true`: each hit reports the fields it matched,
a plain-text `snippet` and a `highlight` with matched terms wrapped in `<mark>`. Text is escaped
before the marks go in, so no `|raw` is needed.

---

## Search rules

A rule is deliberate control over what one search returns. It belongs to an index, optionally to one
site, and is triggered by the query text — *is exactly*, *contains*, *starts with*, *ends with*, or
a `*` wildcard pattern.

| Action | What it does |
|---|---|
| Boost | Moves a result up. |
| Bury | Moves a result down. |
| Hide | Leaves a result out entirely. |
| Pin | Places a result at a fixed position, whether or not the search found it. |
| Promote | Puts a result near the top, whether or not the search found it. |
| Redirect | Offers somewhere the search should be sent instead. |

Rules apply highest priority first, oldest first within a priority. Hiding, pinning and promoting are
exclusive — the first rule to claim a result decides its fate — while boosts and buries accumulate on
results no rule claimed. The same rules over the same content always produce the same order.

Hidden, pinned and promoted results are settled before the search runs rather than filtered out of a
page afterwards, so hiding is exact at any depth and totals describe the results a visitor can
actually reach. A placed result is still loaded and authorized like any other, so a rule can arrange
results but never reveal something a viewer may not see.

Rules can be scheduled with a start date, an end date or both, and switched off without deleting.

A redirect is offered, not performed:

```twig
{% if results.redirect %}
    {% redirect results.redirect %}
{% endif %}
```

Every search carries what each rule did on `results.rules`, and what happened to a particular result
on `hit.ruleEffects`.

---

## Search activity

Search Kit records what is searched for, so the searches that fail and the content nobody can find
are visible rather than guessed at. **Nothing recorded identifies who searched** — no account, no
address, no session, no identifier of any kind:

```
query · normalized query · corrected query · index · site · language
result count · response time · results opened · when
```

Recording is a listener on the search event rather than a step inside the search, so a search that
cannot be recorded still returns its results.

Each index decides for itself whether searches are recorded, whether opened results are tied back to
the search that found them, how many days to keep them, and what counts as slow.

### Associating a click

A recorded search hands back a token. Post it with the result that was opened and the two are tied
together — the token identifies the search, never the person:

```twig
{% if results.isTracked() %}
    <form method="post">
        {{ csrfInput() }}
        {{ actionInput('search-kit/analytics/click') }}
        {{ hiddenInput('token', results.trackingToken) }}
        {{ hiddenInput('elementId', hit.elementId) }}
        {{ hiddenInput('siteId', hit.siteId) }}
    </form>
{% endif %}
```

Nothing posted is trusted: a click is only accepted when the search really did return that result in
that site, within 24 hours, and the same result reported twice is counted once.

### Reading it back

```php
$insights = SearchKit::getInstance()->getInsights();
$criteria = new InsightsCriteria(['indexId' => $index->id, 'dateFrom' => new DateTime('-30 days')]);

$insights->getSummary($criteria);            // totals, rates and response time
$insights->getPopularQueries($criteria);     // what people search for most
$insights->getZeroResultQueries($criteria);  // what comes back with nothing
$insights->getUnopenedQueries($criteria);    // searched repeatedly, nothing ever opened
$insights->getSlowQueries($criteria);
$insights->getTrend($criteria);
$insights->getClickedResults($criteria);
$insights->getRecentSearches($criteria);
```

### Dashboard

The **Search Kit** section opens a dashboard covering all of it: overview KPIs, activity over time,
search outcomes, most-searched queries, zero-result queries, most-opened results, queries nothing
came of, performance, and the health of every index. Everything reads the same index, site and date
filters, with presets for the last 7, 30 and 90 days.

Panels can be dragged, resized and put away, and the arrangement belongs to the person who made it.
Charts are plain SVG — no chart library, no JavaScript — and everything a chart says is also written
out beside it.

---

## What to do next

**Search Kit → What to do next** reads the same recorded activity a step further and says what is
worth doing about it, always with the measurements behind it. Nothing here changes a search, and
nothing is ever applied on its own.

**A quality score out of 100**, from three shares of the searches in the period:

```
success     = 1 − (searches that found nothing ÷ searches)
engagement  = searches where a result was opened ÷ searches whose index follows opened results
speed       = 1 − (searches past their own index's slow threshold ÷ searches)

score = 100 × (0.5 × success + 0.3 × engagement + 0.2 × speed)
```

The weights are a deliberate product judgement rather than a validated model, and the page says so.
A part nothing was recorded about is left out and the remaining weights shared out again.

**What has changed** — the last seven days against the seven before, on the share of searches
finding nothing, search volume, and average response time. A window with fewer than 20 searches
behind it is not compared at all, so a quiet index reports no anomalies rather than noise.

**Content gaps** — popular queries that come back with nothing at least half the time, or come back
with results nobody ever opens.

**Synonym candidates** — two queries people open the same results from. Offered with the numbers
behind them, never applied.

**Recommendations** — all of the above turned into things to do, each with its reason in words, the
measurements behind it, and a page to act on.

```php
$intelligence = SearchKit::getInstance()->getIntelligence();
$intelligence->getQualityScore($criteria);
$intelligence->detectAnomalies($criteria);
$intelligence->getContentGaps($criteria);
$intelligence->discoverSynonyms($criteria, $index);

SearchKit::getInstance()->getRecommendations()->forCriteria($criteria, $index);
```

Queries are also grouped by what their wording is after — product, informational, support or
transactional. That is a dictionary of English cue words plus one structural signal, it reports the
words that decided each reading, and it never affects a search.

---

## Debugger

The **Debugger** page runs a real search and shows what it did. Nothing is reconstructed afterwards:
the search records each step as it happens.

| Section | What it explains |
|---|---|
| Query | What was typed, what it normalized to, and what the provider was actually searched with |
| Provider | Which provider served the index, what it can do, every call made to it and its timing |
| Time | Milliseconds spent on each stage of the search |
| Rules | Every rule considered, whether it matched, and what each action did |
| Results | Each result in rank order, with what put it there — a score, a pin, or a promotion |
| Exclusions | What Search Kit itself kept out, and why |

The scoring model shown is the real one: the provider's own score plus whatever the rules moved it
by. Provider diagnostics are opt-in per provider, and no provider settings are ever shown —
credentials belong in environment variables.

A debugged search is not counted as search activity.

---

## HTTP and GraphQL

Both run the same search service PHP and Twig run, so the query pipeline, the rules, the site scope
and the visibility rules are identical whichever way a search arrives. **Both search published
content only.**

### API keys

Managed under **Search Kit → API keys**. A key is shown once and stored only as an irreversible
digest, is scoped to the indexes it names, carries an optional rate limit in requests a minute, and
is revoked by deleting it. An index a key may not search is reported as though it were not there.

### REST

```bash
curl -H "Authorization: Bearer sk_…" \
  "https://example.com/search-kit/api/search?index=siteSearch&q=winter%20boots&limit=10"

curl -X POST -H "Authorization: Bearer sk_…" -H "Content-Type: application/json" \
  -d '{"index":"siteSearch","q":"winter boots","limit":10,"highlight":true}' \
  "https://example.com/search-kit/api/search"
```

`index` and `q` are the endpoint's own parameters; everything else is a search option and means
exactly what it means in PHP and Twig. The key travels in the `Authorization` header and nowhere
else.

A search answers with the result as plain data — totals, paging, facets, and hits carrying their
identity, title, URL, scores and excerpts. Field values are not part of it: a search API is not a
content API. Failures all have the same shape:

```json
{ "error": { "code": "invalid_query", "message": "…", "details": { "limit": ["…"] } } }
```

| Status | Code |
|---|---|
| 400 | `invalid_query`, `unsupported_query` |
| 401 | `unauthorized` |
| 403 | `forbidden` |
| 404 | `index_not_found`, `index_disabled` |
| 429 | `rate_limit_exceeded`, with `Retry-After` |
| 502 | `provider_error` |
| 500 | `search_failed` |

### GraphQL

Search Kit adds one query, `searchKitSearch`, offered only to a schema that names at least one
Search Kit index. Grant indexes under **GraphQL → Schemas**, the same way sections are granted.

```graphql
{
  searchKitSearch(index: "siteSearch", q: "winter boots", site: "default", limit: 10) {
    total
    page
    hasNextPage
    hits { elementId siteId elementType finalScore snippet }
  }
}
```

A hit names an element; it does not hand out its content. Fetch that through Craft's own `entries`,
`categories` or `assets` queries, where the schema decides which fields may be read.

---

## Indexing

Search Kit keeps an index in step with Craft content by listening to Craft's own element events.
Nothing is indexed inside the request that changed the content:

```
element saved, deleted or restored → outstanding work recorded → queue job → provider
```

Entries, categories, assets and users can be indexed, along with Commerce products and variants.

One piece of outstanding work per element, per site, per index — so an element saved ten times in a
request is indexed once. A failing operation is retried three times and then parked as failed for an
administrator to look at. A value that is empty is content; a value that cannot be read is a failure,
so nothing is ever indexed as though it had no content.

**Rebuilding** walks every element the index covers and runs on the queue with progress reporting.
Only one run may write to an index at a time, content changing mid-rebuild is never swallowed, and a
rebuild that leaves anything behind is reported as incomplete rather than claiming a completion that
did not happen.

**Configuration changes** mark an index as owing a rebuild — searchable fields, weights, element
types, site scope, provider and provider settings all count; renaming, disabling, search behaviour
and synonyms do not. A rebuild is never started automatically.

### Control panel

| Page | What it is for |
|---|---|
| Indexes | Create, edit, enable, rebuild and retry; searchable fields, weights and search behaviour |
| Rules | Query triggers, actions, schedule and priority |
| Synonyms | Synonym groups |
| API keys | Create and revoke keys |
| Search activity | Recorded searches, filtered by index, site and date range |
| What to do next | Quality score, changes, gaps, synonym candidates and recommendations |
| Debugger | Run a search and see exactly what it did |

Permissions govern all of it separately, so merchandising, integration and measurement can each be
delegated without handing over index configuration.

### Commands

```bash
php craft search-kit/index/status            # every index, and what it owes
php craft search-kit/index/process <handle>  # work through what an index owes, now
php craft search-kit/index/rebuild <handle>  # queue a rebuild, or --now to run it here
php craft search-kit/index/retry <handle>    # put failed operations back in the queue
```

---

## Providers

A provider is what actually serves an index. Search Kit's own query, result and document types are
provider-independent, and a provider declares what it can do — a query asking for something it
cannot honour is refused rather than quietly run as something else.

| | Craft | Meilisearch |
|---|---|---|
| External service needed | no | yes (1.x) |
| Relevance scoring | Craft's, when ordering by score | Meilisearch's, 0–1 |
| Field weighting | no | yes, as attribute order |
| Typo tolerance | Search Kit corrects | Meilisearch's own |
| Highlighting | Search Kit works it out | Meilisearch's own |
| Synonyms and `OR` | yes | no |
| Partial matching (`boot*`) | yes | no |
| Comparing field values | yes | `id` and `siteId` only |
| `relatedTo` | yes | no |
| Facets | element table columns | any searchable field |
| Separate store per index | no — one shared Craft index | yes |

### The Craft provider

The default. It works with no external service, and searches, indexes, filters, counts and sorts
through Craft's own element queries and search syntax — so nothing a caller passes is ever used as
SQL.

Craft has exactly one search index, so two Search Kit indexes on this provider are separate
*configurations*, not separate stores. Searchable fields tell Craft which custom fields to index and
which element types to search, but Craft's search API offers no way to restrict a query to a set of
fields, and Search Kit does not rewrite a query to fake one.

### The Meilisearch provider

Serves an index from a [Meilisearch](https://www.meilisearch.com) server. Each Search Kit index gets
a store of its own, named `prefix` + the index handle, and each element in each site is one document.

| Setting | What it is |
|---|---|
| Server address | e.g. `http://localhost:7700` or `$MEILISEARCH_URL` |
| API key | **Must name an environment variable**, written as `$MEILISEARCH_API_KEY`. Leave empty for a server that needs no key. |
| Index prefix | Put in front of the index name, so two environments can share one server. |

```
# .env
MEILISEARCH_URL=http://localhost:7700
MEILISEARCH_API_KEY=your-search-key
```

A key typed in rather than named is refused at save, so it is never stored in the database, shown in
the control panel, or reported by the debugger.

**Rebuild the index after configuring it.** That is the moment its searchable, filterable and
sortable attributes are settled, field weights become the order of its searchable attributes, and
the languages its sites are written in are declared.

---

## Craft Commerce

Commerce is optional and Search Kit does not depend on it — without the Commerce plugin installed,
nothing Commerce-related is registered at all.

Where Commerce is installed, products and variants join entries, categories, assets and users as
indexable element types, and are then configured, indexed, searched, merchandised, recorded and
explained by exactly the machinery everything else uses. There is no Commerce search path, no
Commerce rules engine and no Commerce endpoints.

**Indexed on** — products: `defaultSku` and `sku` (Commerce's own searchable attributes, both filled
from the default variant), plus `title`, `slug` and any custom field on the product layout. Variants:
`sku`, `price`, `description`, `productTitle`, dimensions, weight, quantity limits, plus `title`,
`slug` and custom fields.

**Filterable on** — products: `defaultSku`, `defaultPrice` and the default dimensions, plus `type`,
`typeId` and `relatedTo`. Variants: `sku`, `price`, `stock`, `hasStock`, `hasUnlimitedStock`,
`inventoryTracked`, `availableForPurchase`, `isDefault`, `productId`, dimensions and quantity limits.

```twig
{% set results = craft.searchKit.search('products', q, {
    filters: {
        elementType: 'product',
        type: 'clothing',
        defaultPrice: { lte: 50 },
        relatedTo: category.id,
    },
}) %}
```

Promotional and sale pricing, `forCustomer` and `hasVariant` are deliberately not exposed: what a
shopper pays depends on catalog pricing and on who is asking, so an anonymous search has no
deterministic answer. Customer, order, payment and address data is never indexed, never filterable
and never returned.

Verified against Craft Commerce 5.7.4.

---

## Limitations

Stated plainly, so nothing here comes as a surprise.

**Content and providers**

- Indexable element types are entries, categories, assets and users, plus Commerce products and
  variants.
- Two providers ship — Craft and Meilisearch. Algolia, Typesense and OpenSearch are not implemented.
- Provider settings are per index, so two indexes on one Meilisearch server each carry their own
  copy of its address and key.
- Meilisearch cannot be filtered by field value comparisons or by `relatedTo`, and refuses `OR`,
  configured synonyms and `boot*` partial matching. Its totals stop being exact past 1000 results.
- A rebuild is never started automatically after a configuration change; it is reported as owed.
- No field-scoped search.

**Query handling**

- The built-in stop word list is English. Other languages need their own words configured.
- Analysis is Craft's character folding plus whatever the provider does with the languages it is
  told about. There is no stemming, lemmatization or per-language analyzer of Search Kit's own.
- `-` and `OR` cannot be combined in one term; the query is refused rather than reinterpreted.
- A search covering sites in different languages needs a provider that accepts alternatives for a
  word those languages fold differently — the Craft provider does, Meilisearch does not.
- A synonym group written for one site is not applied to a search covering other sites. What was
  held back is reported on the result and in the debugger.
- Suggestions describe published content only, for everybody — administrators included.
- Suggestions are ranked by how little they change what was typed. Recorded activity does not
  reorder them.

**Rules**

- A rule's boost or bury amount is shared by every result it moves; different amounts need one rule
  each.
- Boosting and burying are skipped past the first 1000 results and recorded as skipped rather than
  applied to the wrong page. Hiding, pinning, promoting and redirecting are exact at any depth.
- A placed result that has stopped being viewable is withheld from every page, but the total only
  stops counting it on the page it would have appeared on.
- A rule that boosts or buries costs one extra search. Rules that only hide, pin, promote or
  redirect cost nothing extra.

**Activity and intelligence**

- Search activity is counted by UTC day, whatever timezone the site runs in.
- A click has to be reported by the page showing the results; nothing is tracked automatically, and
  a click more than 24 hours later, or on a result past the first 100, is not associated.
- Recorded searches are deleted a whole index at a time, or left to retention.
- The dashboard filters by index, site and date range — not by query, and with no comparison against
  a previous period. Its panels can be arranged and resized, but not configured or added to.
- Everything under *What to do next* is read from recorded activity alone. Nothing is learned,
  predicted or applied automatically.
- Anomaly detection compares two windows of equal length — no seasonality, no trend fitting — and
  skips any window with fewer than 20 searches.
- Synonym candidates need clicks, and are only built from searches made under the same site scope.
- The quality weights are a product judgement and cannot be configured. Intent is a dictionary of
  English cue words with no measured accuracy, and never affects a search.
- The dashboard and search-activity pages count slow searches against one threshold when covering
  several indexes; only the intelligence readings judge each search by its own index's threshold, so
  the two can report different slow counts.
- Results cannot be ordered by how often they are opened. There is no popularity sort.

**APIs and debugger**

- HTTP and GraphQL search published content only, whatever the key or the schema.
- Only search is exposed over them. Autocomplete, suggestions and click reporting are PHP and Twig
  only.
- An API key is scoped to indexes and nothing finer, and cannot be regenerated — it is revoked and
  replaced.
- Rate limiting is per key, per minute, held in Craft's cache — clearing it restores every
  allowance, and two web servers with separate caches count separately.
- A GraphQL search covering every site is refused unless the schema allows every site.
- The debugger explains one search at a time. It cannot compare two searches, and it cannot say why
  a particular piece of content was *not* matched.

---

## Local development

Search Kit is developed as a Composer path repository inside a Craft project:

```json
{
    "repositories": [
        { "type": "path", "url": "plugins/Search-Kit" }
    ]
}
```

```bash
composer require tahadudhiya/craft-search-kit:@dev
php craft plugin/install search-kit
```

## Tests

```bash
composer test              # unit tests, no Craft app required
composer test-integration  # runs against the surrounding Craft project's database
```

The integration suite boots the Craft project Search Kit is installed in, so run it from that
project's environment.

## License

Search Kit is licensed under [The Craft License](LICENSE.md).
