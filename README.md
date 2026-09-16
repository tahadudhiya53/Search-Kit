# SearchKit

Search management and intelligence for Craft CMS.

## Status

**Early development.** SearchKit can run searches and keep indexes in step with Craft content. It
provides search providers behind a single interface, provider-independent query, result and
document objects, database-backed search indexes and searchable field configuration, a search
service that runs a query through an index's provider, and queue-backed indexing that follows every
content change. Indexes are managed from the control panel or from PHP. A provider backed by
Craft's own search index ships with it.

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
    SearchQuery::make('winter boots', 'siteSearch'),
);
```

### The Craft provider

The bundled provider searches Craft's own search index, so SearchKit works without any external
service. Craft scores results itself, which sets real limits on what this provider can honour:

- It declares only the `Search` and `Indexing` capabilities. A query using filters or a custom sort
  is rejected rather than quietly run without them.
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

- No Twig integration; searches run from PHP.
- Only entries, categories, assets and users are offered as indexable element types.
- Only the Craft provider ships, with the constraints described above — including that it shares
  Craft's single search index rather than giving each SearchKit index its own store.
- A rebuild is never started automatically after a configuration change; it is reported as owed.
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
