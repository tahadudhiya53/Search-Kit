# SearchKit

Search management and intelligence for Craft CMS.

## Status

**Early development.** SearchKit can run searches from PHP and Twig, and keep indexes in step with
Craft content. It provides search providers behind a single interface, provider-independent query,
result and document objects, database-backed search indexes and searchable field configuration, a
search service that runs a query through an index's provider, filtering, sorting, pagination,
snippets and highlighting, and queue-backed indexing that follows every content change. Indexes are
managed from the control panel or from PHP. A provider backed by Craft's own search index ships
with it.

See [Limitations](#limitations) for what is not there yet.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## How it fits together

A search index is a database record: a name, a handle, the provider serving it, and the searchable
fields it is configured with, each carrying a weight. A search runs like this:

```
SearchQuery → Search service → search provider → SearchResult
```

Queries and results are SearchKit's own types, so no search engine's request or response format
reaches the rest of the plugin. Providers declare what they can do, and SearchKit rejects a query
asking for something the provider cannot honour rather than quietly ignoring it.

```php
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\models\SearchQuery;

$result = SearchKit::getInstance()->getSearch()->search(
    SearchQuery::create('siteSearch', 'winter boots', ['limit' => 10]),
);
```

### The Craft provider

The bundled provider searches Craft's own search index, so SearchKit works without any external
service. Craft scores results itself, which sets real limits on what this provider can honour:

- It searches, indexes, filters and sorts, using Craft's own element query criteria, field
  conditions and sort options. It cannot weight fields or highlight, so a query asking for either
  is rejected rather than quietly run without it — highlighting is instead worked out by SearchKit
  itself, as described under [Snippets and highlighting](#snippets-and-highlighting).
- **A score is only reported when results are ranked by relevance.** Craft works one out while
  ordering by `score`; order by anything else and every hit's score is `0`.
- **Searchable fields do not narrow what it searches.** It uses their element types to decide which
  element types to query, and their handles to tell Craft which custom fields to index — but a
  search still matches anything Craft has indexed for those elements. Craft's search API offers no
  way to restrict a query to a set of fields, and SearchKit does not rewrite your query to fake one.
- **Weights are configuration, not ranking.** They are stored and exposed for providers that can use
  them; this provider leaves ranking entirely to Craft.
- **It does not delete.** Craft clears an element's own keywords when the element is deleted, so the
  provider declares no deletion capability and SearchKit never records deletions for it.
- **It cannot discard and recreate an index**, so it declares no rebuild capability either. A
  rebuild reindexes every element in scope over the top of what Craft already holds.
- **Indexes are not physically separate.** Craft has exactly one search index, and every SearchKit
  index using this provider writes to and reads from it. Two indexes are separate *configurations*
  — different element types, sites and fields — not separate stores. Indexing an element through
  one index updates the same Craft keywords the other would read, and removing an element type from
  an index stops that index searching it without removing anything Craft has stored.

### Sites

An index declares the sites it covers, and a query may narrow that scope but never widen it:

| `SearchIndex::$siteId` | `SearchQuery::$siteId` | Searches |
|---|---|---|
| `null` | `null` | every site |
| `null` | a site ID | that site |
| a site ID | `null` | the index's site |
| a site ID | the same site ID | that site |
| a site ID | a different site ID | rejected as an invalid query |

There is no current-site fallback: an index is explicit about its own scope. The same scope applies
when indexing — an index refuses an element belonging to a site it does not cover.

## Searching

From a template, `craft.searchKit` is the whole public API:

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

The same parameters build a query in PHP, which the search service then runs:

```php
$query = SearchQuery::create('siteSearch', 'winter boots', ['limit' => 10]);
$result = SearchKit::getInstance()->getSearch()->search($query);
```

| Parameter | Type | What it does |
|---|---|---|
| `limit` | whole number | Hits per page. Defaults to 20, and may not exceed 1000. |
| `offset` / `page` | whole number | Where in the results to start. `page` is worked out from `limit`. |
| `site` | handle, ID or `Site` | May narrow the index's scope, never widen it. |
| `status` | string | An element status, such as `live` or `disabled`. Craft's own default applies otherwise. |
| `filters` | array | Constraints on what may match — see below. |
| `orderBy` | string or array | `'title asc'`, `'score desc, title asc'` or `{ title: 'asc' }`. Defaults to relevance. |
| `highlight` | boolean | Whether to work out matched fields, snippets and highlights. Off by default. |
| `snippetLength` | whole number | Roughly how long a snippet may run. Defaults to 200 characters. |

Types are checked rather than coerced. A whole number may be given as a number or as the string form
of one, since that is how request parameters arrive — `'2'` is a page number, while `'2.5'`, `2.5`,
`true` and `[]` are mistakes and say so. `highlight` takes only `true` or `false`: `'false'` and `1`
each have two plausible readings, and PHP's own answer is not the one a template would expect.
`status` takes a non-empty string, and a status no element type in the index has is refused rather
than quietly matching nothing.

Anything else is rejected, as is a blank query, a filter value that is empty or cannot be compared,
an unknown operator and a limit beyond the maximum. Invalid input raises an exception rather than
returning something that looks like an empty result.

### What a search may return

Search covers published content. What counts as published is Craft's own answer: the status an
unrestricted query of each element type returns.

| Element type | Published status |
|---|---|
| Entries | `live` — enabled, posted, and not expired |
| Categories, assets, users | `enabled` |

- With no `status`, that is exactly what you get. Drafts and revisions are never searched.
- **`enabled` is not a public status for entries.** Craft's `enabled` means only that the entry is
  switched on, so it also covers entries scheduled for the future and entries that have expired.
  Asking for it is asking for unpublished content.
- Any status other than the published one — `enabled`, `disabled`, `pending`, `expired`, `archived`
  — requires a signed-in administrator. Everyone else is refused outright, in a request or out of
  one. Code running in the console or a queue job can search unpublished content by signing an
  administrator in first, as Craft's own tooling does.
- For an unpublished search, every result is still put to Craft's `Elements::canView()`. If the site
  refuses even one of them, the search is refused rather than answered: dropping a result would
  leave the total describing a different set of results from the one returned.
- Every hit names the site it was found in, and its element is always loaded from that site. A
  result a provider cannot place in a site the search covered is refused rather than guessed at, so
  a search can never widen its own scope.
- An element that cannot be loaded in the site and status that were searched is dropped, so a hit
  never comes back as an identifier with nothing behind it. That only happens when a provider's
  index has fallen behind Craft, and the total drops with it.

SearchKit adds no permissions of its own for searching: it asks Craft. The control panel permissions
govern only index management.

### Filters

A filter is a field, an operator and a value. Templates can leave the operator out:

```twig
{% set results = craft.searchKit.search('siteSearch', q, {
    filters: {
        elementType: 'entry',            {# any of: eq #}
        section: ['news', 'blog'],       {# a list means any of them: in #}
        postDate: { gte: '2024-01-01' }, {# eq, neq, in, notIn, gt, gte, lt, lte #}
    },
}) %}
```

Operators are `eq`, `neq`, `in`, `notIn`, `gt`, `gte`, `lt` and `lte`. A value must be a string,
number, boolean or date; `in` and `notIn` need a non-empty list; the four comparisons need something
with an order to it. Null is rejected, because a provider would quietly ignore it. There is no
“contains” operator — matching text is what the search itself does.

What may be filtered on is decided by the provider. The Craft provider accepts:

- `elementType`, taking Craft's reference handles (`entry`, `category`, `asset`, `user`) or class
  names, and narrowing an index only to element types it already covers.
- The element criteria Craft itself exposes: `id`, `uid`, `title`, `slug`, `uri`, `level`,
  `section`, `sectionId`, `type`, `typeId`, `authorId`, `group`, `groupId`, `volume`, `volumeId`,
  `folderId` and `kind`.
- Any custom field the index is configured to search, as long as that field is on one of the
  element type's own field layouts. The filter becomes Craft's own condition for that field type,
  so it behaves exactly as the same parameter would on an element query.

Nothing else reaches the query builder. A field no element type in the index can be asked about is
rejected, a filter that rules an element type out removes it from the search, and a field type Craft
stores no queryable value for — a Matrix field, for instance — is rejected rather than quietly
matching nothing. One field may only be filtered on once.

### Sorting

`orderBy` is provider-independent: `score` means whatever relevance the provider reports, and any
other field is checked against what the provider can sort by. For the Craft provider that is Craft's
own sort options for the element type, so nothing a caller passes is ever used as SQL, and orderings
Craft builds itself — an entry's post date, for instance — are used as Craft builds them.

Craft can only order one element type's query at a time, so a search covering several of them is put
in order afterwards, by comparing the attribute each hit carries. Only a sort that is a plain
attribute on every element type involved can be honoured that way: anything else is rejected with an
explanation rather than applied to part of the results. Equal values are broken by element ID, so a
page is never ordered arbitrarily.

### Pagination

A result reports `total`, `limit`, `offset`, `page`, `pageCount`, `hasNextPage`, `hasPreviousPage`,
`nextPage` and `previousPage`, plus `count` for the hits on this page.

`hasNextPage` and `hasPreviousPage` describe the window itself — whether there are results after or
before it — while `page` and `pageCount` count whole pages, so they line up exactly when `offset` is
a multiple of `limit`. Every page of one search reports the same `total`, because what a search may
return is decided before it runs rather than by dropping results from a page. On an all-site index each site's copy of an element is its own result, since
each one is a separate thing to link to.

`provider`, `metadata` and a hit's `providerData` are diagnostics: they report what happened to run
the search, and are not part of the stable result contract.

### Snippets and highlighting

With `highlight: true`, each hit reports the fields it matched on, a plain-text `snippet` cut around
the first match, and a `highlight` — the same excerpt with matched terms wrapped in `<mark>`. Both
are also available per field: `hit.getSnippet('body')`.

Excerpts come from the values the index is configured to search, so they work whatever is serving
the index; a provider that highlights for itself keeps its own answer. Text is escaped before the
marks go in, so `{{ hit.highlight }}` needs no `|raw` and indexed content cannot carry markup into a
page. It is off by default because it reads each hit's content.

## Indexing

SearchKit keeps an index in step with Craft content by listening to Craft's own element events.
Nothing is indexed inside the request that changed the content:

```
Craft element saved, deleted or restored
        ↓
outstanding operation recorded for each index that covers it
        ↓
queue job
        ↓
document builder → search provider
```

An index only receives element types it is configured for, in sites it covers. Entries, categories,
assets and users can be indexed.

### Documents

A document is what a provider is handed: one element, in one site, reduced to the configured
searchable values and the weight of each. Values come from Craft itself — a custom field's own
`searchKeywords()`, or the element's keywords for a searchable attribute — so no provider needs to
know how Craft stores anything, and weights stay plain `handle => integer` pairs rather than any
engine's syntax.

A document also carries the element it was built from, as source context for providers whose engine
is Craft itself. That is not document data: only the extracted fields are searchable content, and
the bundled Craft provider is the only thing that reads it, because Craft's search service indexes
an element rather than a document.

### Outstanding work, failures and retries

Every content change becomes a row of outstanding work for each index that cares about it. One row
per element per site per index: the newest intent wins, so an element saved ten times in a request
is indexed once. A row is deleted as soon as the provider accepts it, so the table only ever holds
what is pending or has given up.

Work is claimed with a token. If content changes while a worker is busy with an older version of
the same element, the newer intent replaces it and the worker's result is discarded rather than
deleting work it never did.

A failing operation is retried up to three times. After that it is parked as failed for an
administrator to look at and retry from the control panel or the command line. SearchKit's own
error messages are shown as written; anything else is reported generically and its detail goes to
the log, so a provider can never leak internals into the control panel.

A value that is empty is content. A value that *cannot be read* is a failure: the element is left
outstanding and retried rather than indexed as though it had no content.

### Rebuilding

A rebuild asks the provider to discard its own copy of the index if it can, and walks every element
the index covers — exactly the element types and sites that would have produced work on save, so a
rebuild can never reach outside the index's scope. Rebuilds run on the queue and report progress.

Only one run may write to an index at a time. A rebuild and ordinary processing take the same lock,
so they can never overlap; whichever is turned away comes back rather than dropping its work.

An element that fails during a rebuild becomes outstanding work rather than stopping the rebuild,
and a processing job is queued once the rebuild finishes so those elements are retried. A rebuild
that leaves anything behind is reported as incomplete: the index keeps owing a rebuild, and the
last-indexed stamp is left alone rather than claiming a completion that did not happen. The rebuild
is settled later, when those leftovers finally succeed and nothing is outstanding.

Content that changes while a rebuild is running is never swallowed by it. A rebuild only clears
work that was already outstanding when it started; anything recorded after that is left alone and
processed once the rebuild releases the index.

A *configuration* change during a rebuild is handled the same way round. Each index carries a
configuration generation, bumped whenever an effective change is saved. A rebuild remembers the
generation it started against and is only allowed to settle that one, so a rebuild that finishes
after the configuration moved on leaves the newer configuration owing a rebuild rather than
reporting it as indexed. That is not a failure — nothing went wrong, the rebuild simply covered an
older configuration — and rebuilding again settles the current one.

### Configuration changes

An index and the fields it searches are one configuration, saved as one thing: if any part of it is
invalid the whole save is rolled back and the previous configuration stays in place. A field is
only accepted if the element type is one SearchKit indexes and the handle is genuinely searchable
on it, so the control panel offers exactly what a save accepts, and the same rules apply from the
console or from PHP.

What a provider holds was built from the configuration in force at the time. When that changes, the
index is marked as needing a rebuild and stops reporting itself as current, in the control panel,
the status API and on the command line. These count as changes:

| Change | Rebuild owed |
|---|---|
| Searchable fields, weights, or whether a field is enabled | yes |
| Element types covered | yes |
| Site scope | yes |
| Provider, or provider settings | yes |
| Re-enabling a disabled index | yes |
| Disabling an index | no |
| Renaming an index | no |

Saving a configuration is a short database transaction; it never waits for a running rebuild.
SearchKit does not rebuild by itself: rebuilding can be expensive, so it is left as a deliberate
step. Changing an index's provider leaves whatever the previous provider held untouched — nothing
is migrated, and nothing pretends it was.

A disabled index is not written to, not rebuilt, and not searched. Outstanding work is kept rather
than discarded, but nothing is sent to the provider until the index is switched back on. Content
changes are not tracked while it is off, which is why switching it back on marks a rebuild as owed.

### Control panel

**SearchKit** in the control panel lists indexes with their pending and failed counts, when they
were last indexed and whether they are current, and lets you create, edit, enable, disable and
delete indexes, choose their searchable fields and weights, rebuild them, and retry failed
operations. An index is only shown as current when it is enabled, nothing is outstanding, nothing
has failed, no rebuild is owed and the provider says it can serve. Three permissions govern it: viewing indexes,
managing them, and rebuilding or retrying.

### Commands

```bash
php craft search-kit/index/status            # every index, and what it owes
php craft search-kit/index/process <handle>  # work through what an index owes, now
php craft search-kit/index/rebuild <handle>  # queue a rebuild, or --now to run it here
php craft search-kit/index/retry <handle>    # put failed operations back in the queue
```

## Limitations

These do not exist yet. They are the intended direction of the plugin, not a description of what it
currently does.

- Only entries, categories, assets and users are offered as indexable element types.
- Only the Craft provider ships, with the constraints described above — including that it shares
  Craft's single search index rather than giving each SearchKit index its own store.
- A rebuild is never started automatically after a configuration change; it is reported as owed.
- No faceting, fuzzy matching, typo tolerance, synonyms or autocomplete.
- No search rules, merchandising, analytics, or debugger.

## Local development

SearchKit is developed as a Composer path repository inside a Craft project. Add the plugin
directory as a repository and require it:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/Search-Kit"
        }
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

The integration suite boots the Craft project SearchKit is installed in, so run it from that
project's environment.

## License

SearchKit is licensed under [The Craft License](LICENSE.md).
