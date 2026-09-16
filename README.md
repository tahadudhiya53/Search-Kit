# SearchKit

Search management and intelligence for Craft CMS.

## Status

**Early development.** SearchKit can run searches today. It provides search providers behind a
single interface, provider-independent query and result objects, database-backed search indexes
and searchable field configuration, and a search service that runs a query through an index's
provider. A provider backed by Craft's own search index ships with it.

Everything is reachable from PHP only — see [Limitations](#limitations).

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

## Limitations

These do not exist yet. They are the intended direction of the plugin, not a description of what it
currently does.

- No control panel: indexes and searchable fields are created through the PHP services.
- No Twig integration; searches run from PHP.
- No content synchronisation — nothing keeps an index in step with content changes automatically.
- Only the Craft provider ships, with the constraints described above.
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
