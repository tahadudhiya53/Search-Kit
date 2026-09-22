# Search Kit

Search management and intelligence for Craft CMS.

## Status

**Early development.** Search Kit can run searches from PHP and Twig, and keep indexes in step with
Craft content. It provides search providers behind a single interface, provider-independent query,
result and document objects, database-backed search indexes and searchable field configuration, a
search service that runs a query through an index's provider, filtering, ranges, sorting,
pagination, faceted counts, snippets and highlighting, and queue-backed indexing that follows every
content change. A search covers one site, several of them, or every site an index holds, and query
text is read in the language of the site being searched. What a
visitor types goes through a query pipeline of its own — normalization, operators, stop words,
synonyms and typo correction — and a search that finds nothing offers something else to try. Search
rules give deliberate control over what a particular query returns: boost, bury, hide, pin, promote
and redirect, on a schedule and in a fixed priority order. Every search can be recorded — what was
searched for, what came back and what was opened, and nothing about who searched — and read back as
popular queries, zero-result queries, content gaps, trends and response times, on a control panel
dashboard that shows how search is doing at a glance. What has been recorded is read a step
further on a page of its own: a quality score out of 100 from real measurements, what has changed
against the week before, where demand is going unanswered, which two queries look like the same
thing, and what to do about each — every one of them shown with the numbers it was read from. A
search debugger runs a real search and
explains it: how the query was read, what the provider was asked and answered, which rules matched
and what they did, and what every result was ranked by. The same search is reachable over HTTP,
authenticated with a revocable API key that is scoped to indexes and held to a rate, and through
Craft's own GraphQL API, where a schema decides which indexes and sites it may search. Indexes,
rules, synonyms, API keys, search behaviour and what is recorded are managed from the control panel
or from PHP. Two providers ship with it: one backed by Craft's own search index, and one backed by a
Meilisearch server. Where Craft Commerce is installed, products and variants are indexed, filtered
and merchandised by that same machinery, through an integration the rest of Search Kit knows nothing
about.

See [Limitations](#limitations) for what is not there yet.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## How it fits together

A search index is a database record: a name, a handle, the provider serving it, and the searchable
fields it is configured with, each carrying a weight. A search runs like this:

```
SearchQuery → query pipeline → search provider → SearchResult
```

The pipeline is where everything a query *means* is settled, in one place and in this order:

```
raw text → normalization → operators and tokens → stop words → synonyms → terms
```

A provider is only ever handed terms. It never has to read search syntax of its own, and nothing a
visitor types is used to build a query by hand.

Queries and results are Search Kit's own types, so no search engine's request or response format
reaches the rest of the plugin. Providers declare what they can do, and Search Kit rejects a query
asking for something the provider cannot honour rather than quietly ignoring it.

```php
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\models\SearchQuery;

$result = SearchKit::getInstance()->getSearch()->search(
    SearchQuery::create('siteSearch', 'winter boots', ['limit' => 10]),
);
```

### The Craft provider

The bundled provider searches Craft's own search index, so Search Kit works without any external
service. Craft scores results itself, which sets real limits on what this provider can honour:

- It searches, indexes, filters, counts and sorts, using Craft's own element query criteria, field
  conditions and sort options. It also honours phrases, exclusions, alternation and partial
  matching, which it expresses through Craft's own search syntax rather than through SQL of
  Search Kit's making.
- It cannot weight fields or highlight, so a query asking for either is rejected rather than
  quietly run without it — highlighting is instead worked out by Search Kit itself, as described
  under [Snippets and highlighting](#snippets-and-highlighting).
- **It has no typo tolerance of its own**, so Search Kit corrects for it, as described under
  [Typo tolerance](#typo-tolerance). A provider that declares the capability keeps its own answer
  and Search Kit stays out of the way.
- **A score is only reported when results are ranked by relevance.** Craft works one out while
  ordering by `score`; order by anything else and every hit's score is `0`.
- **Searchable fields do not narrow what it searches.** It uses their element types to decide which
  element types to query, and their handles to tell Craft which custom fields to index — but a
  search still matches anything Craft has indexed for those elements. Craft's search API offers no
  way to restrict a query to a set of fields, and Search Kit does not rewrite your query to fake one.
- **Weights are configuration, not ranking.** They are stored and exposed for providers that can use
  them; this provider leaves ranking entirely to Craft.
- **It does not delete.** Craft clears an element's own keywords when the element is deleted, so the
  provider declares no deletion capability and Search Kit never records deletions for it.
- **It cannot discard and recreate an index**, so it declares no rebuild capability either. A
  rebuild reindexes every element in scope over the top of what Craft already holds.
- **Indexes are not physically separate.** Craft has exactly one search index, and every Search Kit
  index using this provider writes to and reads from it. Two indexes are separate *configurations*
  — different element types, sites and fields — not separate stores. Indexing an element through
  one index updates the same Craft keywords the other would read, and removing an element type from
  an index stops that index searching it without removing anything Craft has stored.

### The Meilisearch provider

Serves an index from a [Meilisearch](https://www.meilisearch.com) server (1.x). Meilisearch scores,
highlights, counts and tolerates typos itself, so this provider honours far more of the search API
than the Craft one does — and every Search Kit index gets a store of its own.

A rebuild also tells Meilisearch which languages the index's content is written in, taken from the
sites it covers, so words are tokenized and stemmed the way those languages work. A site in a
language Meilisearch does not know is simply not declared rather than failing the rebuild.

Choose **Meilisearch** as an index's provider and fill in its settings:

| Setting | What it is |
|---|---|
| Server address | Where Meilisearch is reachable, e.g. `http://localhost:7700` or `$MEILISEARCH_URL`. |
| API key | **Must name an environment variable**, written as `$MEILISEARCH_API_KEY`. Leave it empty for a server that needs no key. |
| Index prefix | Put in front of the Meilisearch index name, so two environments can share one server. |

A key typed in rather than named is refused at save, so the key itself is never stored in the
database, never shown back in the control panel, and never reported by the debugger:

```
# .env
MEILISEARCH_URL=http://localhost:7700
MEILISEARCH_API_KEY=your-search-key
```

One Search Kit index maps to one Meilisearch index, named `prefix` + the index handle. Each element,
in each site, is one document: its identity (`elementId`, `siteId`, `elementType`) alongside the
configured searchable values under a `fields` object, so a field handle can never collide with the
identity Search Kit needs back.

**Rebuild the index after configuring it.** A rebuild waits for anything Meilisearch still has
queued against the index, discards it and recreates it, and that is the moment its searchable,
filterable and sortable attributes are settled from the index's searchable fields. Field weights become the *order* of Meilisearch's searchable attributes —
the heaviest field first — which is how Meilisearch expresses that one field matters more than
another. Sorting is moved ahead of relevance in the ranking rules, so `orderBy` is an instruction
rather than a tiebreaker.

Filters and sorts may name `id`, `siteId`, `elementType`, or any field the index searches. Anything
else is rejected rather than quietly dropped.

What it cannot do:

- **No `OR` between terms**, so a query using the operator is refused — and because Search Kit
  expresses synonyms as alternatives, **configured synonyms are not applied** to an index this
  provider serves. Meilisearch has synonyms of its own, which Search Kit does not manage.
- **No per-term partial matching**, so `boot*` is refused and the index's partial matching setting
  is not applied. Meilisearch matches word prefixes in its own way instead.
- **Typo tolerance is Meilisearch's.** It declares the capability, so Search Kit never corrects a
  search of one of its indexes, and the index's own typo settings do not reach it.
- **Text cannot be compared.** Searchable values are stored as the keywords a Craft field produced,
  so `>`, `>=`, `<` and `<=` are refused on them and accepted only on `id` and `siteId`.
- **Two round trips per document.** Meilisearch accepts a write and applies it afterwards, and it
  can still refuse it at that point, so every write is followed until Meilisearch confirms it —
  otherwise Search Kit would settle an indexing operation that never landed. A rebuild of a large
  site is therefore a long run of small HTTP calls.
- **Totals stop being exact past Meilisearch's `maxTotalHits`**, 1000 by default, which also caps
  how deep results can be paged. Search Kit does not change that setting.
- Scores are Meilisearch's ranking scores, between 0 and 1, so a rule's boost or bury amount has to
  be chosen on that scale rather than on the one Craft scores with.

### Sites

An index declares the sites it covers, and a query may narrow that scope but never widen it:

| `SearchIndex::$siteId` | `SearchQuery::$siteId` | Searches |
|---|---|---|
| `null` | `null` | every site |
| `null` | a site ID | that site |
| a site ID | `null` | the index's site |
| a site ID | the same site ID | that site |
| a site ID | a different site ID | rejected as an invalid query |

A search can also name several sites at once with `sites`, given as site handles, IDs or `Site`
models — or as a comma-separated list, which is how a request carries one. Every site named must be
one the index covers, and naming one site this way is exactly the same search as `site`:

```twig
{% set results = craft.searchKit.search('siteSearch', q, { sites: ['default', 'demoUk'] }) %}
```

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

How the query *text* is read — operators, partial matching, stop words, synonyms and typo tolerance
— is not a query parameter. It belongs to the index, so every search of one behaves the same way.
See [Search experience](#search-experience).

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

Search Kit adds no permissions of its own for searching: it asks Craft. The control panel permissions
govern only index management.

### Filters

A filter is a field, an operator and a value. Templates can leave the operator out:

```twig
{% set results = craft.searchKit.search('siteSearch', q, {
    filters: {
        elementType: 'entry',            {# any of: eq #}
        section: ['news', 'blog'],       {# a list means any of them: in #}
        postDate: { gte: '2024-01-01' }, {# eq, neq, in, notIn, gt, gte, lt, lte, between #}
        price: { between: [100, 500] },  {# a range, both bounds included #}
    },
}) %}
```

Operators are `eq`, `neq`, `in`, `notIn`, `gt`, `gte`, `lt`, `lte` and `between`. A value must be a
string, number, boolean or date; `in` and `notIn` need a non-empty list; `between` needs exactly a
lowest and a highest value and includes both; the comparisons need something with an order to it. A
range written backwards — `[500, 100]` — is refused rather than quietly matching nothing, for numbers
and for dates, whether the dates are `DateTime`s or written out as `2024-01-01`. Text with no
ordering every provider agrees on is left to the provider to judge. Null is rejected, because a provider would quietly ignore it. There is no
“contains” operator — matching text is what the search itself does.

What may be filtered on is decided by the provider. The Craft provider accepts:

- `elementType`, taking Craft's reference handles (`entry`, `category`, `asset`, `user`) or class
  names, and narrowing an index only to element types it already covers.
- The element criteria Craft itself exposes: `id`, `uid`, `title`, `slug`, `uri`, `level`,
  `section`, `sectionId`, `type`, `typeId`, `authorId`, `group`, `groupId`, `volume`, `volumeId`,
  `folderId` and `kind`.
- `relatedTo`, naming elements a result has to be related to, by ID — which is how a search is
  narrowed to a category, a tag, or anything else a relation field points at. It takes `eq` or `in`
  and element IDs only, and it cannot be negated: Craft has no “related to none of these”.
- Any custom field the index is configured to search, as long as that field is on one of the
  element type's own field layouts. The filter becomes Craft's own condition for that field type,
  so it behaves exactly as the same parameter would on an element query.
- Anything an integration registers for a particular element type — see
  [Craft Commerce](#craft-commerce).

Nothing else reaches the query builder. A field no element type in the index can be asked about is
rejected, a filter that rules an element type out removes it from the search, and a field type Craft
stores no queryable value for — a Matrix field, for instance — is rejected rather than quietly
matching nothing. One field may only be filtered on once.

### Facets

A facet counts how the whole result set divides up by a field, which is what a filter can then be
offered from. Ask for one by naming the fields to count, and read the counts back off the result:

```twig
{% set results = craft.searchKit.search('siteSearch', q, {
    facets: ['sectionId', 'elementType'],
}) %}

{% for value in results.getFacet('sectionId').values %}
    <a href="?q={{ q }}&section={{ value.value }}">{{ value.value }} ({{ value.count }})</a>
{% endfor %}
```

Counts describe the search, not the page: they are the same whatever `limit` and `offset` are, and
they are narrowed by exactly the filters the search was narrowed by — so counting and then filtering
by a value returns the number the facet reported. A value is returned as text, in the form a filter
would name it, and values come back commonest first.

Faceting is a provider capability. A provider that cannot count is refused rather than answering
with nothing, and the fields that can be counted are the provider's to decide:

- The **Craft provider** counts by `elementType`, by `siteId`, and by any column the element type's
  own table holds — `sectionId` and `typeId` for entries, `groupId` for categories, `volumeId`,
  `folderId` and `kind` for assets, `typeId` for Commerce products. The columns are read from the
  search Craft itself prepared, so an element type a plugin defines is counted by its own columns.
  A name matching no column on any element type in the index is rejected. Custom fields cannot be
  counted: Craft stores their values as JSON, which cannot be grouped.
- The **Meilisearch provider** counts by `elementId`, `siteId`, `elementType` and any field the
  index searches.

Counting costs one extra request to the provider, made only when facets were asked for. A result a
rule hides is left out of the counts, as it is left out of the results; one a rule pins or promotes
is still counted, where the provider found it.

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

A result also carries `correctedText` and `wasCorrected` when the query was corrected before it
ran, `suggestions` and `hasSuggestions` when it found nothing, and `parsedQuery` — the terms it was
produced from, which is what a ranking explanation will eventually be built from.

`provider`, `metadata`, `parsedQuery` and a hit's `providerData` are diagnostics: they report what
happened to run the search, and are not part of the stable result contract.

## Search experience

How a query is read is configured per index, under **Search behaviour** on the index's page. None
of it changes what is indexed, so changing any of it never costs a rebuild.

Settings are checked rather than coerced, exactly as search parameters are: `"maybe"` is not a
boolean, `"12.7"` is not a whole number, and a matching mode that does not exist is refused. An
unusable value is reported as an error rather than saved as something else.

### Normalization

Query text and indexed content go through Craft's own keyword normalization: lowercased, stripped
of markup, punctuation, diacritics and emoji, with whitespace collapsed. Both sides are reduced the
same way, so `Café` finds `cafe` and Search Kit never disagrees with what Craft indexed.

Normalization is language-aware, because Craft's is: a character folds differently depending on the
language, so content is reduced in the language of the site it belongs to and a query is read in the
language of the site it is searching.

**A search covering sites written in different languages is read in each of them.** No single
language stands in for the others: every language the searched sites use reads the text for itself,
and where two of them fold a word differently the term accepts both readings — `grüße` becomes
`grusse OR gruesse` for a search covering an English and a German site, so neither site is searched
for the other's spelling. Where the languages agree, which is most words, the term stays one term.

Accepting two readings needs a provider that can be given alternatives. The Craft provider can;
Meilisearch cannot, so a query whose readings differ is refused there rather than run as one of
them. `result.parsedQuery.languages` says which languages a search was read in.

**A query the languages do not read as the same words at all is refused**, whatever the provider.
Craft reads the Cyrillic hard sign `ъ` as a word in English and as nothing in Russian, so
`boots ъ` is two words to an English site and one to a Russian one — there is no pair of terms to
accept, and running either reading would search the other site for something nobody typed. That is
an invalid query, reported like any other: a 400 `invalid_query` over REST, a validation error over
GraphQL, and an `InvalidQueryException` in PHP. Searching the sites of one language at a time
answers each of them correctly.

Words somebody configures — synonyms and extra stop words — are held in the language of the site
they were written for.

### Operators

With **Search operators** on — the default — these are read in what a visitor types:

| Written | Means |
|---|---|
| `winter boots` | Both words have to match. |
| `"winter boots"` | The words have to appear together, in that order. |
| `"boot"` | That word and nothing longer, whatever partial matching is set to. |
| `-leather` | Rules out anything matching it. |
| `boots OR shoes` | Either one is enough. Each side keeps its own matching. |
| `boot*` | Matches from the start of a word. |
| `*boot*` | Matches anywhere in a word. |

That is the whole set. Each one can be expressed consistently, so a provider either honours it or
says it cannot — a query is never quietly run as something else.

- A leading wildcard on its own (`*boot`) is read as `*boot*`, since matching only the end of a word
  is something few engines can answer.
- Phrases use double quotes; an apostrophe is just a character. An unclosed quote is read as text.
- `OR` is only `OR` in capitals, between two terms. With nothing on one side it is dropped.
- **`-` and `OR` cannot be combined.** `boots OR -leather` is rejected rather than guessed at: one
  reading searches for something to exclude, the other excludes one of two alternatives, and
  quietly picking either would run a different search from the one written. Write the exclusion as
  its own term — `boots OR shoes -leather` — which does exactly what it looks like.
- A query that only rules things out is rejected, because it has nothing to look for.
- Anything else that is not valid syntax is read as text rather than failing.

Turn operators off and every word is searched for exactly as written, punctuation and all.

### Partial matching

**Partial matching** decides how much of a word a term has to cover when no operator says
otherwise: whole words only, the start of a word (the default), or anywhere in a word. Terms
shorter than **Shortest partial match** are matched whole, so a two-letter word does not match
everything. Exclusions are always matched whole — ruling results out on part of a word is rarely
what anyone means.

### Stop words

Words too common to narrow anything down are dropped from a query. The built-in list is short and
English, so it is only applied where **every** language the search covers is English — dropping
`was` from a German query, or from a search covering an English and a German site, would be removing
a word that narrows it. **Extra stop words** adds to it, in any language: a word somebody configured
is dropped whatever the search is read in, and is recognised in each language's reading of it. A query made of nothing but stop words is left alone
rather than emptied, so searching for `the who` still searches for something.

### Synonyms

A synonym makes a search for one word find the others as well. They are managed under
**Search Kit → Synonyms**, and each group covers one index or all of them, and one site or all of
them.

| Type | Behaviour |
|---|---|
| Two-way | Every term stands in for the others. `boots, footwear, shoes` — any of them finds all of them. |
| One-way | The terms also search for the replacements, never the other way around. `tv → television`. |

A term may be a word or a phrase, and is normalized on save exactly as indexed content is — in the
language of the site the group was written for — so a synonym cannot fail to match over a capital
letter or an accent. Synonyms are configuration rather than something a query asked for, so a
provider that cannot offer alternatives simply goes without them instead of refusing the search.
Groups are cached, and a save or delete is visible to the very next search.

**A group only widens a search that stays inside the site it was written for.** A search covering
several sites is one search, so an expansion is applied only where it holds in *every* site being
searched:

| Group's site | Search | Applied |
|---|---|---|
| Every site | any | yes |
| Site A | site A | yes |
| Site A | sites A and B | no — it would return results in B that a search of B alone never had |
| Site A *and* an equivalent group on site B | sites A and B | yes — both sites say the same thing |

Nothing is lost silently: what was held back is on `result.parsedQuery.withheldSynonyms` and on the
debugger's page. Each site is asked with its own language's reading of the term, so a group written
in either language is still found.

Completions, corrections and no-result suggestions read the same words, and they read only the
sites the search covers — a word only another site holds is never offered. Where the searched sites
are written in different languages, each language reads what was typed for itself and is answered
from its own sites, so a completion is always a word its own site actually holds.

### Typo tolerance

This is spelling correction, not a fuzzy ranking algorithm: a search that finds **nothing** is tried
again against the words the index actually holds. Each word the index has never seen is replaced by
the closest one it has, within **Edits allowed** edits, and the search is run once more. An edit is
an insertion, a deletion, a substitution, or a swap of two neighbouring characters — `form` and
`from` are one edit apart. Any character can be corrected, including the first.

If the retry finds something, the result says what was searched for instead:

```twig
{% if results.wasCorrected %}
    <p>{{ "Showing results for"|t }} <em>{{ results.correctedText }}</em></p>
{% endif %}
```

If it finds nothing either, the search stands as it was asked. Correction never runs on a search
that found something, so a working search pays nothing for it. Words shorter than **Shortest word
corrected** are left alone, since a short word is rarely a typo, and edits are counted in characters
rather than bytes.

Phrases, alternations and exclusions are never corrected. The first two already say what they
accept, and quietly widening what a search rules out would change what it means.

Which word wins is decided the same way every time: the closest one, then the shortest, then the
first alphabetically. Every word the index holds that is close enough is weighed — nothing is cut
off at an arbitrary number of candidates.

### Suggestions and autocomplete

Both are drawn from the words an index holds, so a suggestion can never lead to another empty result
and never names content the person searching may not see. A search that found nothing carries them:

```twig
{% for suggestion in results.suggestions %}
    <a href="?q={{ suggestion|url_encode }}">{{ suggestion }}</a>
{% endfor %}
```

Autocomplete completes what has been typed so far, keeping everything before the last word. It runs
no search at all, so it is cheap enough to call while someone types, and its answers are cached:

```twig
{% set completions = craft.searchKit.autocomplete('siteSearch', q, { limit: 5 }) %}
```

`craft.searchKit.suggest('siteSearch', q)` asks for the same alternatives without running a search.

**Offering back what others searched for.** An index can also complete what is being typed with
whole queries people have searched for before, which come ahead of the dictionary's own
completions. This shows one visitor's wording to the next, so it is off until it is turned on under
**Record searches → Suggest what others searched for**, and a past query is only ever offered when
all of this holds:

- the search found results, and somebody opened one of them — so the query is known to lead
  somewhere real;
- it was searched for at least five times, across at least two separate days, which raises the bar
  on a one-off or a single burst of searching (both numbers are configurable per index). Search Kit
  records nothing about who searched, so this is a threshold on **repeated searches over separate
  days** — it is not evidence that different people made them;
- and **every word of it is a word publicly searchable content still uses**, checked the same way
  every other suggestion is.

That last check is what makes the whole thing safe: a query naming something nobody may find — or
that the content behind it no longer uses — is dropped rather than shown. The visibility check runs
on every lookup, so content going unpublished stops being suggestible at once; which past queries
are eligible is reused for up to five minutes, so a query that has just become popular can take
that long to start being offered. Nothing about who searched is stored or read to do any of this.

**Site and language scope.** Past queries are looked for among the searches of the sites being
suggested for, in the language those sites read them in, and only ever narrowed by that scope. A
search recorded for one site is offered in that site; a search that covered the index's whole scope
is offered only when the whole scope is what is being suggested for, because it is not attributable
to any one of those sites. This is the same rule a synonym group written for one site is held to: a
query from one site is never offered in another just because its words happen to be visible there.

### The words an index holds

These come from the content Search Kit indexes. Each word is recorded against the document it was
read from, so the list follows the content:

| What happens to a document | What happens to its words |
|---|---|
| It is indexed | Its words are recorded for that index and site. |
| It is changed | Words it no longer uses go; words it now uses appear. |
| It is deleted | Its words go, unless another document also uses them. |
| It is restored | Its words come back. |
| Indexing it fails | Nothing is settled: the work stays outstanding and is retried, so the words are never left describing a document the provider does not hold. |
| The index is rebuilt | The whole list is rebuilt from the content itself. |

A word several documents use survives until the last of them stops using it.

**Suggestions only ever name published content.** Before a word is completed, corrected to, or
offered, Search Kit checks that a document anybody may find still uses it — Craft's own definition of
published, asked of the element itself. A word that only a draft, a disabled entry, one that is not
posted yet, one that has expired, or one that has been deleted uses is never offered, to anybody.
That applies to administrators too: unpublished content is searched for deliberately, by status, not
stumbled upon through a completion.

Every document using a word is checked until one of them proves the word may be shown, so a word
buried under any number of hidden documents is still offered. The same goes for a correction and for
completions: candidates are read a batch at a time until there are enough that may be shown, or the
index runs out. Nothing is cut short by a fixed sample.

Content that becomes published or expires with the clock, rather than through a save, is picked up
when the cached answer expires — within five minutes. A word appearing or disappearing through
indexing is visible to the very next lookup.

There is no notion of a popular word: ordering is by how little a suggestion changes what was typed,
shortest first. Nothing about what visitors search for is recorded.

### Snippets and highlighting

With `highlight: true`, each hit reports the fields it matched on, a plain-text `snippet` cut around
the matches, and a `highlight` — the same excerpt with matched terms wrapped in `<mark>`. Both are
also available per field: `hit.getSnippet('body')`.

A value long enough to hold several matches shows up to three excerpts, joined by an ellipsis, so a
snippet reflects why the whole value matched rather than only where it first did. Excerpts are cut
at word boundaries, phrases are marked as one rather than word by word, and terms are marked
whatever the pipeline made of them — a corrected word is marked as corrected, and a synonym is
marked where it matched. Excluded terms are never marked.

Excerpts come from the values the index is configured to search, so they work whatever is serving
the index; a provider that highlights for itself keeps its own answer. Text is escaped before the
marks go in, so `{{ hit.highlight }}` needs no `|raw` and indexed content cannot carry markup into a
page. It is off by default because it reads each hit's content.

## Search rules

A rule is deliberate control over what one search returns. It belongs to one index, optionally to
one site, and is triggered by the query text: *is exactly*, *contains*, *starts with*, *ends with*,
or a pattern where `*` stands for any run of characters. Query text and rule text are normalized the
same way, so a rule written “iPhone” governs a search for “iphone”. Patterns are wildcards, never
regular expressions.

A matching rule can do any of these:

| Action | What it does |
|---|---|
| Boost | Moves a result up the ones the search already found. |
| Bury | Moves a result down. |
| Hide | Leaves a result out entirely. |
| Pin | Places a result at a fixed position, whether or not the search found it. |
| Promote | Puts a result near the top, whether or not the search found it. |
| Redirect | Offers somewhere the search should be sent instead. |

Rules are applied in a fixed order: **highest priority first, and oldest first within a priority.**
From that, two precedence rules settle everything:

1. **The first rule to claim a result decides its fate.** Hiding, pinning and promoting are
   exclusive — whichever of them reaches a result first wins, and every later rule that names the
   same result is refused and says so. A hide does not override a pin from a higher-priority rule,
   and a pin does not override a higher-priority hide; priority alone decides.
2. **Boosts and buries accumulate**, but only on results no rule claimed. They add up across every
   matching rule, and addition settles the same way in any order, so two rules moving one result
   never disagree. A result that was hidden, pinned or promoted is not ranked by its score any more,
   so an adjustment on it is recorded as superseded rather than silently applied.

Two pins cannot share a position — the first claim keeps it. Only the highest-priority applicable
redirect is offered. The same rules over the same content always produce the same order.

Precedence is settled **per site**. A rule naming a site claims only that site's copy of a result; a
rule naming none claims every site it has not already lost. So a site rule that outranks a global one
keeps its own site while the global rule still governs the rest, and a global rule that outranks a
site rule decides every site. A rule for one site never blocks a rule for another.

A rule may carry a start date, an end date, or both, and can be switched off without deleting it.
Dates are entered in Craft's system timezone — the one `timezone` is set to, shown next to the field
— and held in UTC, so a schedule means the moment it was given wherever it is read. Both ends are
inclusive. Craft has no per-site timezone, so neither does a schedule.

### How rules reach the results

Hidden, pinned and promoted results are left out of the search itself — the provider is asked not to
return them — and the placed ones are then put back at their own positions. That is what makes the
behaviour exact rather than best-effort:

- A hidden result cannot appear on any page, however far down the ranking it sat. It is not counted
  either, so the total describes the results a visitor can actually reach.
- A placed result appears exactly once and is counted exactly once, whether or not the search would
  have found it on its own.
- Pagination stays consistent: every page together holds each result once.

This needs the provider to support result exclusion. A provider that cannot is refused rather than
hiding only what it happened to read.

Boosting and burying work the same way: the results a rule moves are held back from the ranked list
and asked for by name, so a boost lifts a result onto the first page however far down it ranked, and
a bury pushes one off it. They are moved at the score the search itself gave them, and a result the
search never matched is not added by a boost — moving a result never invents one.

Reordering does still need every result above the page in hand, so past the first 1000 results the
page is read straight and the adjustment is recorded as skipped rather than applied to the wrong
results. Score adjustments are also skipped when the search supplies its own `orderBy` — relevance is
not deciding the order then, so a score nudge would mean nothing. Pins, promotions, hides and
redirects apply either way.

### Sites

A rule belongs to one index and may name one site. A rule that names a site only ever affects that
site's results, including on a search covering every site — the same entry in another site is left
alone. A rule that names no site applies in every site the search covers.

Pinning and promoting put a result somewhere in particular, so they need one site to put it in: the
rule's site, or the index's when the index covers only one. On an index covering every site, a rule
must name a site before it can place results, since which site's version to place would otherwise be
a guess. Nothing falls back to the primary site.

A rule may only name a site its index covers, and every target is checked when the rule is saved: it
must exist, be a real element type, be a type the rule's index actually searches, be reachable in the
rule's site, and not be in the trash. Nothing a control panel form posts is trusted. If the index
later stops searching a kind of result, rules naming one stop acting on it.

### What a placed result may show

A rule may name content that is published today and a draft tomorrow, so **what a viewer may see is
settled every time the search runs**, never when the rule was saved. A pinned or promoted result is
loaded and authorized exactly like one the search found itself:

- It is loaded under the same status the search ran with, so a result that is disabled, not yet
  posted, expired, disabled for the site being searched, or in the trash is not returned — to
  anybody, administrators included.
- Where Search Kit applies Craft's `canView()` — on any search of something other than published
  content — a placed result is put to it with all the others.
- A placed result that is withheld is taken off the total on the page it would have appeared on. On
  a page far enough in that the placed results all sit above it, the total still counts it — see
  [Limitations](#limitations).
- The explanation on `results.rules` is settled against what was shown: a placement that was withheld
  is reported as `notViewable` rather than as applied, and **the withheld result's ID is not named**,
  so nothing identifies content the viewer was not allowed to see.

A rule is a way to arrange results, never a way around the visibility policy.

A redirect sends the whole search somewhere, so it may only come from a rule whose site covers the
whole search: a rule naming one site redirects a search of that site alone, and never a search
covering every site. Only the highest-priority applicable redirect is offered.

A redirect is offered, not performed: the search still runs, and the template decides.

```twig
{% set results = craft.searchKit.search('siteSearch', query) %}

{% if results.redirect %}
    {% redirect results.redirect %}
{% endif %}

{% for hit in results.hits %}
    {% if hit.pinned or hit.promoted %}<span class="featured">Featured</span>{% endif %}
    ...
{% endfor %}
```

Every search carries what each rule did, matched or not, on `results.rules` — its priority, match
type and value, site scope, whether it matched and why not, and for each action whether it applied
and which rule superseded it. What happened to a particular result is on `hit.ruleEffects`.
`hit.score` stays the provider's own score; `hit.scoreAdjustment` is what the rules moved it by, and
`hit.finalScore` is what it was ranked by.

### Rules and corrected queries

A search that finds nothing is retried against a correction of what was typed. **Rules are matched
against the query that actually ran** — so on a corrected search, that is the corrected text, and the
rules are read again against it before anything is arranged. Write rules against the spelling you
want them to govern. A rule written for a misspelling still governs for as long as that misspelling
finds results, because nothing is corrected then. A provider that tolerates typos itself never
reports a correction, so rules there are matched against what the visitor typed.

A search whose rules place results is never corrected: placed results are held back from the provider
while it searches, so an empty answer from it does not mean the search found nothing.

## Search activity

Search Kit records what is searched for, so the searches that fail and the content nobody can find
are visible rather than guessed at.

Nothing recorded identifies who searched. There is no account, no address, no session and no
identifier of any kind on a recorded search — only the search itself:

```
query · normalized query · corrected query · index · site · language
result count · response time · results opened · when
```

The query is kept twice: once exactly as it was typed, and once reduced the way the index reduces
it. Queries group on the reduced form, so `Winter Boots` and `winter  boots` are one query, while
what was actually typed is still there to read.

Recording is a listener on the search event, not a step inside the search. A search costs one
insert to record, and a search that cannot be recorded still returns its results.

### What each index records

Each index decides for itself, on its edit page:

| Setting | What it does |
|---|---|
| Record searches | Whether searches of this index are recorded at all |
| Record opened results | Whether a result someone opened can be tied back to the search that found it |
| Kept for | Days a recorded search is kept, after which it is deleted |
| Slow search | Milliseconds beyond which a search counts as slow |

There is no “keep everything”: retention is always a number of days, enforced by Craft’s own
garbage collection, which `php craft gc` runs on demand.

### Associating a click

A recorded search hands back a token. Post it with the result that was opened and the two are tied
together — the token is the only link, and it identifies the search, never the person:

```twig
{% set results = craft.searchKit.search('site', query) %}

{% for hit in results.hits %}
    <a href="{{ hit.element.url }}">{{ hit.element.title }}</a>

    {% if results.isTracked() %}
        <form method="post" class="searchkit-click">
            {{ csrfInput() }}
            {{ actionInput('search-kit/analytics/click') }}
            {{ hiddenInput('token', results.trackingToken) }}
            {{ hiddenInput('elementId', hit.elementId) }}
            {{ hiddenInput('siteId', hit.siteId) }}
        </form>
    {% endif %}
{% endfor %}
```

Nothing posted is trusted. A recorded search remembers which results it returned — the element and
the site each of them named — and a click is only accepted when it names one of them. So the token
has to belong to a search from the last 24 hours, that search has to have returned *this* result in
*this* site, and the element has to still exist. A result from another site, a result the search
never returned, one past the window it returned, an element since deleted, and an invented or
expired token are all refused.

Where the result sat is read from the search rather than posted, so which result it was is the only
thing a page reports. The same result reported twice for one search is counted once, so a reload
cannot inflate a rate.

A search remembers at most its first 100 results for this purpose, and remembers none at all when
the index does not follow clicks, so a wide result window cannot turn one search into unbounded
storage.

### Reading it back

```php
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\models\InsightsCriteria;

$insights = SearchKit::getInstance()->getInsights();
$criteria = new InsightsCriteria(['indexId' => $index->id, 'dateFrom' => new DateTime('-30 days')]);
// dateFrom is inclusive and dateTo is exclusive, so a whole day can be asked for by its two ends.

$insights->getSummary($criteria);            // totals, rates and response time
$insights->getPopularQueries($criteria);     // what people search for most
$insights->getZeroResultQueries($criteria);  // what comes back with nothing
$insights->getUnopenedQueries($criteria);    // searched for repeatedly, nothing ever opened
$insights->getSlowQueries($criteria);        // the ones past what the index calls slow
$insights->getTrend($criteria);              // day by day, optionally for one query
$insights->getClickedResults($criteria);     // the results people opened most
$insights->getRecentSearches($criteria);     // the searches themselves
```

Every reading is one grouped query over an indexed date range, and is reused for up to five minutes
— but only while nothing new has been recorded. A search or an opened result makes every page read
it again, so opening a page never costs a scan of the whole table and no two pages can disagree
about what has happened. Rates are counted on searches rather than on
clicks: a click-through rate is the share of searches that led to something being opened, however
many results were opened.

### Dashboard

The **Search Kit** section itself opens the dashboard, which puts all of this on one page for
whoever may view search activity. Without that permission it opens the indexes instead.

| Section | What it shows |
|---|---|
| Overview | Searches, different things searched for, the share that found nothing, the share that led to a click, average response time, and how many searches were slow |
| Search activity over time | Searches and zero-result searches day by day, with the same figures as a table |
| Search outcomes | How much of the period found results, found nothing, or led to a click |
| Most searched for | The queries people search for most, with their zero-result and click-through rates |
| Queries returning no results | What came back with nothing, most often first |
| Most opened results | The results people clicked through to, and where in the results they were |
| Queries nothing came of | Searched at least twice in the period with no result ever opened |
| Performance | Average response time over the period, and the queries averaging slower than the index calls slow |
| Search health | Every index: whether it is serving, its provider, its site scope and what it records |

Everything on the page reads the same index, site and date filters, with presets for the last 7, 30
and 90 days. The dates mean the same thing they do everywhere else in Search Kit: the day chosen at
the far end is counted in full. A site filter counts searches of that site alone.

**Arranging it.** *Arrange panels* turns on a mode where each panel can be dragged by its bar into
any place on the grid, set to take one, two, three or four of the dashboard's columns, or put away
altogether — and brought back from the bar of panels you have put away, which sits with the
filters. An arrangement belongs to the
person who made it: nobody else's dashboard moves, and it decides nothing about what anybody is
allowed to see. *Reset to the default arrangement* puts it back. Dragging needs JavaScript;
everything else works without it.

Each section says so plainly when there is nothing to show, and a reading that cannot be taken
leaves the rest of the page standing. The charts are drawn as plain SVG — no chart library, no
JavaScript — and everything a chart says is also written out as a figure or a table beside it.

## What to do next

Everything above describes what happened. This reads a step further and says what is worth doing
about it, always with the measurements behind it. It is reached from **Search Kit → What to do next**,
needs the same permission as search activity, and reads the same index, site and date filters.
Nothing on the page changes a search, and nothing here is ever applied on its own.

### Search quality score

One number out of 100, from three shares of the searches in the period:

```
success     = 1 − (searches that found nothing ÷ searches)
engagement  = searches where a result was opened ÷ searches whose index follows opened results
speed       = 1 − (searches past their own index's slow threshold ÷ searches)

score = 100 × (0.5 × success + 0.3 × engagement + 0.2 × speed)
```

The weights are **a deliberate product judgement, not a validated model**: finding something at all
is what a search is for, engagement is the only evidence that what was found was right, and speed
matters but never as much as answering. No data was fitted and no study stands behind the numbers —
they are stated here, and on the page, precisely so a score can be taken apart into the measurements
it came from and argued with.

A part nothing was recorded about is **left out**, and the remaining weights are shared out again —
so an index that does not follow clicks scores on success and speed alone rather than being marked
down for engagement nobody measured. With nothing searched for there is no score, not a score of
zero.

```php
$intelligence = SearchKit::getInstance()->getIntelligence();
$intelligence->getQualityScore($criteria);
```

**Across several indexes**, the reading settles two things itself rather than taking a caller's word
for them:

- *Engagement* is measured over only the searches whose index follows opened results. If every index
  in the reading follows them it is the whole period; if none do it is unavailable; if they disagree
  it is measured over the tracked subset, **with that subset as the denominator**, and the page says
  how many searches it covers. An index that records nothing about clicks is never counted as an
  index nobody opened anything from.
- *Speed* judges each search by **its own index's** slow threshold, so two indexes that disagree
  about what slow means are not both measured against one of the two numbers.

### What has changed

The last seven days against the seven before them, on the three things a change in would matter.
Both windows end where the period asked for ends, so filtering to a date range compares the run-up
to that date.

| Reported when | Guard against noise |
|---|---|
| The share of searches finding nothing rose by 15 points **and** reached 25% | Both windows need at least 20 searches |
| Search volume moved by half **and** by at least 20 searches | The baseline window needs at least 20 searches |
| Average response time reached 1.5× the baseline **and** the strictest slow threshold among the indexes being read | Both windows need at least 20 searches |

A window with fewer than 20 searches behind it is not compared at all: a rate over a handful of
searches is not a measurement. The one exception is volume, which is still reported when the recent
window is empty — search stopping altogether is exactly the thing worth knowing.

```php
$intelligence->detectAnomalies($criteria);        // the last 7 days against the 7 before
$intelligence->detectAnomalies($criteria, 30);    // or any other window length
```

### Content gaps

Demand the content does not answer: a query people search for repeatedly that comes back with
nothing at least half the time, or comes back with results nobody ever opens. They are looked for
among the most-searched queries, because that is where closing a gap is worth the work.

```php
$intelligence->getContentGaps($criteria);
```

### Synonym discovery

Two queries people open the same results from mean the same thing to whoever searched. A pair is
offered when both were searched for often enough, they share at least two opened results, and those
make up at least half of the narrower query's opened results. A pair an existing synonym group
already covers is not offered. **Nothing is ever applied**: a candidate is evidence for somebody to
decide on, and it comes with the numbers it was read from.

```php
$intelligence->discoverSynonyms($criteria, $index);
```

The same element in two sites counts as two results, so two sites' versions of one page are not
mistaken for two queries agreeing.

**Pairs are built within one site scope.** An index covering several sites is searched in several
languages, and two queries are only evidence about each other if the same search could have produced
either. So the site each search was recorded for is carried through, and two queries are only weighed
against each other when they were searched under the same scope — a query from an English site is
never paired with one from a German site. Each candidate reports the scope it was observed in, which
is the scope a group written from it belongs to, and whether an existing group already covers it is
asked about that same scope.

### Recommendations

The readings above, turned into things to do, most-searched first. Each one carries its reason in
words, the measurements behind it, and a page to act on where there is one:

| Recommendation | Read from |
|---|---|
| Improve the content | A popular query that finds nothing at least half the time |
| Create a rule | A popular query whose results are there and nobody opens them |
| Create a synonym | Two queries people open the same results from |
| Promote a result | A result opened at least three times, sitting at position five or deeper on average |

```php
SearchKit::getInstance()->getRecommendations()->forCriteria($criteria, $index);
```

### How the wording reads

Queries are also grouped by what their wording is after — product, informational, support or
transactional. This is a dictionary of cue words plus one structural signal, and nothing more:
`how` and `guide` read as informational, `buy` and `delivery` as transactional, `warranty` and
`returns` as support, and a token carrying both letters and digits reads as a part number. Every
reading reports the words that decided it.

```php
$intent = SearchKit::getInstance()->getIntent()->classify('how do I clean a kettle', 'en-GB');
$intent->intent;               // SearchIntent::Informational
$intent->getMatchedCues();     // ['how']
$intent->getExplanation();     // Read as Informational from “how”.
```

A query pointing equally at two readings is reported as pointing at neither, rather than being
resolved by some order of precedence. A query saying nothing recognisable is left unread. The cue
words are English, so a query in another language is read from its structure alone. This describes
the wording people use and nothing else: **it never affects a search**, and it is not a claim about
anybody's purpose.

```php
$intelligence->getIntentBreakdown($criteria);   // intent => searches whose wording read that way
```

The breakdown covers the most-searched queries of the period, not every query recorded in it, and
reads them in the language of the site being counted — the application's language when no site is
being filtered to.

## Debugger

The **Debugger** page runs a real search and shows what it did. Nothing on it is reconstructed
afterwards: the search itself records each step as it happens, so the page explains the search that
ran rather than a description of one. A debugged search is not counted as search activity.

Choose an index, type a query, optionally narrow it to a site or a status, and run it. The page
then shows:

| Section | What it explains |
|---|---|
| Query | What was typed, what it normalized to, and — separately — what the provider was actually searched with once stop words, operators, synonyms and any correction had been settled. A correction never overwrites what was typed. Also the parsed terms the provider was given: phrase, exclusion, whole-word, partial matching and the synonyms each term also accepts |
| Provider | Which provider served the index, what it declared it can do, the sites and element types searched, the configured field weights, every call made to the provider with its window and timing, and the diagnostics that provider declares safe to show |
| Time | Milliseconds spent resolving the index, reading the query, planning and applying rules, searching, loading and authorizing results, working out matched fields, and finding suggestions |
| Rules | Every rule considered, whether it matched and why not, and what each of its actions did — including an action a higher-priority rule had already settled |
| Results | Each result in rank order, with what put it there: a score — the provider's own plus what the rules moved it by — or a pin at a stated position, or a promotion. A placed result shows no score, because the search never gave it one. Also what it matched on and the weight the index gives those fields |
| Search Kit exclusions | What Search Kit itself kept out: the targets a rule removed before the search ran, named with their status, and the results that were found but could not be shown in the site and status the search ran in |

**What it will not claim.** The scoring model shown is the real one: a result is ranked by the
provider's own score plus whatever the rules moved it by. The Craft provider scores results itself
and applies no field weighting, and the page says so rather than presenting configured weights as
though they decided the ranking. A result a rule pinned or promoted is shown as placed, never as
though a score put it there. A rule's hidden target is listed as something Search Kit kept out, not
as a result the query matched: it was never searched for, so whether it would have matched is not
known, and the debugger does not re-run the search without the rule to find out.

**Provider diagnostics are opt-in.** A provider is asked which parts of what it reported may be
shown, and nothing else reaches the page. The Craft provider names the query it ran, the element
types and the ordering. A provider that reports a request carrying credentials shows nothing at all
until it declares otherwise.

Running the debugger needs the *Run searches in the debugger* permission, and a search of anything
other than published content still needs an administrator, exactly as it does anywhere else. The
page shows no provider settings: credentials belong in environment variables and never reach it.

## HTTP and GraphQL

Search is reachable over HTTP and through Craft's GraphQL API. Both run the same search service
PHP and Twig run: the query pipeline, the rules, the site scope and the visibility rules are the
same whichever way the search arrives.

**Both search published content only.** There is no signed-in user behind an API key or a GraphQL
token, and only published content is searchable anonymously. Asking for anything else is refused.

### API keys

The HTTP API is authenticated with a key, managed under **Search Kit → API keys** with the *Create
and revoke search API keys* permission.

- A key is **shown once**, as soon as it is created. It is stored only as an irreversible SHA-256
  digest, so it can never be shown, recovered or logged again — a lost key is replaced, not found.
- A key is **scoped to indexes**. It may search the indexes it names, or every index if it names
  none. An index it may not search is reported as though it were not there, so a key cannot be used
  to find out which indexes exist.
- A key carries a **rate limit** in requests a minute, 60 by default and optional.
- A key is **revoked** by deleting it, or suspended by disabling it. A disabled key is refused
  exactly as an unknown one is.

### REST

```
GET  /search-kit/api/search?index=siteSearch&q=winter+boots&limit=10
POST /search-kit/api/search
```

The key travels in the `Authorization` header and nowhere else — a key in a query string would be
kept by every log and proxy it passed through.

```bash
curl -H "Authorization: Bearer sk_…" \
  "https://example.com/search-kit/api/search?index=siteSearch&q=winter%20boots&limit=10"

curl -X POST -H "Authorization: Bearer sk_…" -H "Content-Type: application/json" \
  -d '{"index":"siteSearch","q":"winter boots","limit":10,"highlight":true}' \
  "https://example.com/search-kit/api/search"
```

`index` and `q` are the endpoint's own parameters. Everything else is a search parameter and means
exactly what it means in PHP and Twig — `limit`, `offset`, `page`, `site`, `sites`, `facets`,
`filters`, `orderBy`, `highlight` and `snippetLength` — so anything the search API rejects is rejected here, in the same
words. `status` is refused outright, since the API searches published content only. Booleans may be
given as `true`/`false` or `1`/`0`, since that is how a query string carries one.

Filters take the same shapes a template uses, as query parameters or as JSON, and `sites` and
`facets` may be given as a comma-separated list:

```
?filters[section][]=news&filters[postDate][gte]=2024-01-01&facets=sectionId,elementType
```

A successful search answers with the result as plain data:

```json
{
  "index": "siteSearch",
  "query": "winter boots",
  "correctedQuery": null,
  "suggestions": [],
  "redirect": null,
  "total": 42,
  "limit": 10, "offset": 0, "page": 1, "pageCount": 5,
  "hasNextPage": true, "hasPreviousPage": false,
  "executionTime": 12.41,
  "trackingToken": "3f2a…",
  "facets": [
    { "field": "sectionId", "values": [{ "value": "3", "count": 28 }, { "value": "7", "count": 14 }] }
  ],
  "hits": [
    {
      "elementId": 431, "siteId": 1, "elementType": "entry",
      "title": "Winter Boots", "url": "https://example.com/winter-boots",
      "score": 253, "scoreAdjustment": 0, "finalScore": 253,
      "pinned": false, "promoted": false,
      "matchedFields": ["title"], "snippets": {}, "highlights": {}
    }
  ]
}
```

A hit reports what matched and how it ranked, plus the element's title and URL. Field values are
not part of it: a search API is not a content API.

Every failure has the same shape, with the status that goes with it:

```json
{ "error": { "code": "invalid_query", "message": "…", "details": { "limit": ["…"] } } }
```

| Status | Code | When |
|---|---|---|
| 400 | `invalid_query` | A parameter is missing, unknown, the wrong type, or out of range |
| 400 | `unsupported_query` | The index's provider cannot do what the query asked for |
| 401 | `unauthorized` | No key, or one that is unknown or disabled |
| 404 | `index_not_found` | No such index — or none this key may search |
| 404 | `index_disabled` | The index exists but is disabled |
| 403 | `forbidden` | The search asked for content nobody anonymous may see |
| 405 | — | Anything other than `GET` or `POST` |
| 429 | `rate_limit_exceeded` | The key has spent its rate, with `Retry-After` in seconds |
| 502 | `provider_error` | The index's provider failed |
| 500 | `search_failed` | Anything else, logged rather than described |

A rate-limited key gets `X-Rate-Limit-Limit` and `X-Rate-Limit-Remaining` on every answer. The
allowance leaks back at the limit's own rate rather than resetting on a clock, so a key cannot
spend two windows' worth of requests either side of a boundary.

### GraphQL

Search Kit adds one query to Craft's GraphQL API, `searchKitSearch`. It is only offered to a schema
that names at least one Search Kit index, and each index is a schema component of its own — grant
them under **GraphQL → Schemas**, the same way sections and asset volumes are granted.

```graphql
{
  searchKitSearch(index: "siteSearch", q: "winter boots", site: "default", limit: 10) {
    total
    page
    hasNextPage
    hits {
      elementId
      siteId
      elementType
      finalScore
      snippet
    }
  }
}
```

The arguments are the search parameters: `index`, `q`, `site`, `sites`, `facets`, `limit`, `offset`,
`page`, `orderBy`, `filters`, `highlight` and `snippetLength`. Counts come back under `facets`, as
`{ field, values { value count } }`. A filter is `{ field, operator, value }`, where `value`
is always a list — `in` and `notIn` take several, and every other operator takes exactly one.

**What a schema decides.** An index the schema does not name is reported as though it were not
there. A site the schema does not allow cannot be searched — every site a search names has to
be allowed — and a search covering every site is refused unless the schema allows every site — naming one is what narrows it, rather than Search Kit
quietly answering for part of the scope.

**A hit names an element; it does not hand out its content.** `elementId` and `siteId` are what a
result is, and the content behind it is fetched through Craft's own `entries`, `categories` or
`assets` queries, where the schema decides which fields may be read. Snippets and highlights are
excerpts of the index's own searchable values, so granting an index is granting excerpts of what
that index searches.

## Indexing

Search Kit keeps an index in step with Craft content by listening to Craft's own element events.
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
assets and users can be indexed, along with Commerce products and variants where Craft Commerce is
installed.

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
administrator to look at and retry from the control panel or the command line. Search Kit's own
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
only accepted if the element type is one Search Kit indexes and the handle is genuinely searchable
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
| Search behaviour, or synonyms | no |

Saving a configuration is a short database transaction; it never waits for a running rebuild.
Search Kit does not rebuild by itself: rebuilding can be expensive, so it is left as a deliberate
step. Changing an index's provider leaves whatever the previous provider held untouched — nothing
is migrated, and nothing pretends it was.

A disabled index is not written to, not rebuilt, and not searched. Outstanding work is kept rather
than discarded, but nothing is sent to the provider until the index is switched back on. Content
changes are not tracked while it is off, which is why switching it back on marks a rebuild as owed.

### Control panel

**Search Kit → Indexes** lists indexes with their pending and failed counts, when they were last
indexed and whether they are current, and lets you create, edit, enable, disable and delete
indexes, choose their searchable fields and weights, set how queries against them are read,
rebuild them, and retry failed operations. An index is only shown as current when it is enabled,
nothing is outstanding, nothing has failed, no rebuild is owed and the provider says it can serve.

**Search Kit → Rules** lists and edits search rules: the query that triggers them, what they do to
the results, their schedule and their priority.

**Search Kit → Synonyms** lists and edits synonym groups.

**Search Kit → API keys** lists, creates and revokes the keys the HTTP API is reached with. A key is
shown once, when it is created.

**Search Kit → Search activity** lists recorded searches, filtered by index, site and date range,
with the totals for whatever is being shown. A date range covers the whole of both days it names.
Deleting from that page forgets every search recorded for the selected index, or for every index —
it is not limited by the dates or the site being shown, and says so.

**Search Kit → What to do next** reads the same recorded activity a step further: the quality score,
what has changed against the window before, and what to do about each gap, pair and buried result.

Permissions govern all of it: viewing, managing indexes, rebuilding or retrying, managing rules,
running the debugger, managing API keys, viewing search activity and deleting it. Synonyms and
search behaviour are managed under the same permission as the indexes they belong to; rules, API
keys and search activity have their own, so merchandising, integration and measurement can each be
delegated without handing over index configuration.

### Commands

```bash
php craft search-kit/index/status            # every index, and what it owes
php craft search-kit/index/process <handle>  # work through what an index owes, now
php craft search-kit/index/rebuild <handle>  # queue a rebuild, or --now to run it here
php craft search-kit/index/retry <handle>    # put failed operations back in the queue
```

## Craft Commerce

Commerce is optional. Search Kit does not require it and does not depend on it: the only Commerce
class names in the codebase are two strings inside one service, so nothing can autoload Commerce
that is not installed, and without the Commerce plugin that service registers nothing at all — no
element types, no filters, no listeners.

Where Commerce is installed, products and variants join entries, categories, assets and users as
indexable element types, and are then configured, indexed, searched, merchandised, recorded and
explained by the machinery everything else uses: the same `SearchQuery`, query pipeline, provider
abstraction, `SearchResult`, rules, activity, debugger, REST and GraphQL. There is no Commerce
search path, no Commerce rules engine and no Commerce endpoints.

Verified against **Craft Commerce 5.7.4** on Craft 5.10.13.2 — Commerce 5 is the only line that runs
on Craft 5. What Search Kit offers is settled against the query the installed Commerce actually
defines, so a version that drops or renames something simply stops offering it.

### What gets indexed

Products and variants are configured on the index page like any other element type, and what they
can be indexed on comes from Commerce itself:

- Products: `defaultSku` and `sku` — Commerce's own searchable attributes, both filled from the
  default variant — plus `title`, `slug` and any custom field on the product field layout.
- Variants: `sku`, `price`, `description`, `productTitle`, `width`, `height`, `length`, `weight`,
  `minQty`, `maxQty`, plus `title`, `slug` and any custom field on the variant field layout.

Values are extracted by Craft and Commerce themselves, so nothing here knows how a price or a SKU is
stored. Commerce-native values that Commerce does not declare searchable — a variant's stock, for
instance — are filter criteria rather than indexed content.

### Variant changes

A product's searchable attributes — `defaultSku` and `sku` — are the values Commerce fills from its
**default** variant, so that variant being saved, deleted or restored can leave the product's
document stale. Search Kit follows exactly that, and nothing wider:

- A variant that is not the default is not followed. Nothing of it reaches the product's document,
  so the product cannot have gone stale, and the product is never even loaded.
- A product whose index searches it only by title and its own custom fields is not followed either:
  a custom field on the product's own layout cannot carry variant data.

So a variant change loads a product only when that variant is the default *and* some index searches
one of the attributes it fills. A variant ceasing to be the default is not followed on its own
account — the variant replacing it is saved too, and carries the flag.

A failure here can never fail a Commerce save or delete. What it hands to indexing is an ordinary
element change, so indexing keeps its own queue, retry and failure behaviour.

### Filtering

Commerce contributes its own query criteria to the Craft provider, on top of the criteria every
element type already accepts. A criterion the installed Commerce does not define is never offered,
and is then refused with the usual explanation rather than silently ignored.

- Products: `defaultSku`, `defaultPrice`, `defaultWidth`, `defaultHeight`, `defaultLength` and
  `defaultWeight`. A product's type is `type` or `typeId`, and a category is `relatedTo`.
- Variants: `sku`, `price`, `stock`, `hasStock`, `hasUnlimitedStock`, `inventoryTracked`,
  `availableForPurchase`, `isDefault`, `productId`, `minQty`, `maxQty`, `width`, `height`, `length`
  and `weight`. A variant is filtered by its product's type with `typeId`.

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

Prices are Commerce's own stored prices: `defaultPrice` is the product's default variant's price and
`price` is a variant's, both resolved by Commerce from its base price. Filtering is done by
Commerce's element query, never by loading products and comparing in PHP.

Stock, availability and inventory tracking are four different questions and are kept apart.
`hasStock` asks whether anything is available — a variant Commerce does not track has unlimited
stock and always answers yes; `stock` asks how much; `hasUnlimitedStock` and `inventoryTracked` ask
whether Commerce counts it at all; and `availableForPurchase` is a separate flag again. An element's
`status` is none of these and stays what it is everywhere else in Search Kit.

**Deliberately not exposed:** promotional and sale pricing (`promotionalPrice`, `salePrice`,
`onPromotion`, `hasSales`), because what a shopper pays depends on catalog pricing rules and on who
is asking, and an anonymous search has no deterministic answer; `forCustomer`, for the same reason;
`hasVariant`, because it takes a query rather than a value and Search Kit's filters carry strings,
numbers, booleans and dates only; and shipping and tax categories, which are internal configuration
rather than something a shopper searches by. Each of these is refused rather than ignored. Customer,
order, payment and address data is never indexed, never filterable and never returned.

### Sites, rules, activity and the APIs

Commerce changes none of it. A product or variant is resolved in the site Search Kit asked for, an
index's site scope is never widened, and there is no Commerce-specific site fallback. Products and
variants are merchandised with the rules that already exist — boost, bury, hide, pin, promote and
redirect, on the same priorities, schedules and site scoping — and a rule's element type is checked
against what its index searches. Searches of Commerce content raise the same search event, are
recorded by the same activity, explained by the same debugger, and reachable over the same REST and
GraphQL paths under the same keys, schemas, index scopes and site restrictions.

## Limitations

What Search Kit does not do yet, and the limits of what it does.

- Only entries, categories, assets and users are offered as indexable element types, plus Commerce
  products and variants where Craft Commerce is installed.
- Commerce promotional and sale pricing cannot be filtered on, and products cannot be filtered by
  variant criteria in one query (`hasVariant`). Filter variants directly instead.
- Whether a variant change reaches its product is decided across every enabled index at once. If any
  index searches a product's variant-derived attributes, a variant change reindexes its product in
  every index that covers it, not only in the index that needed it.
- Commerce support is verified against Commerce 5.7.4 only. Other 5.x releases are expected to work,
  since what is offered is resolved against the installed query, but they have not been run.
- Commerce filtering is the Craft provider's. On a Meilisearch-backed index a field's value is
  indexed as text, so a price range — or any other comparison on a field — is refused there.
- `relatedTo` is the Craft provider's too. Meilisearch holds no relationships, so a search of a
  Meilisearch-backed index cannot be narrowed by one.
- Two providers ship — Craft and Meilisearch — each with the constraints described above. Algolia,
  Typesense and OpenSearch are not implemented.
- Provider settings are per index. Two indexes on one Meilisearch server each carry their own copy
  of its address and key.
- A rebuild is never started automatically after a configuration change; it is reported as owed.
- Suggestions describe published content only, for everybody. There is no way to complete or
  correct against unpublished content, even as an administrator.
- The built-in stop word list is English, and is only applied to a search read in English. Any other
  language needs its own words configured under **Extra stop words**.
- Language-aware analysis is Craft's character folding, plus whatever the provider does with the
  languages it is told about. There is no stemming, lemmatization or per-language analyzer of
  Search Kit's own.
- `-` and `OR` cannot be combined in one term; the query is refused rather than reinterpreted.
- The dictionary's own suggestions are ranked by how little they change what was typed; recorded
  activity does not reorder them. Past searches can be offered alongside them, but only as whole
  queries and only when the index is told to.
- A past search is offered back to anybody who can reach the search box. The checks behind it are
  what make that safe; there is no per-person filtering, because nothing about who searched is
  recorded.
- Past searches are offered from what the index has recorded, whatever site each search covered. It
  is the visibility check on every word that decides what may be shown in a given site, not the
  site the search was recorded against.
- No field-scoped search.
- A facet counts by a column, not by a custom field: Craft stores custom field values as JSON, which
  cannot be grouped, so only element attributes can be counted on a Craft-backed index. Meilisearch
  counts by its own attributes, so a searchable field can be counted there.
- A facet returns at most 100 values per element type, commonest first, and counting costs one extra
  request to the provider.
- Results cannot be ordered by how often they are opened — there is no popularity sort. Recorded
  search activity is read back as insights; it does not feed ranking.
- A search naming several sites reads every rule its index has and settles each action against the
  sites it named. Analytics records a site only for a search of one site, and a language only when
  every site being searched is written in the same one — a search spanning two languages records
  neither, rather than claiming one of them.
- Language-aware reading is per site, not per index: an index cannot be told to read everything in
  one language. What a site is written in is Craft's answer.
- A search covering sites in different languages needs a provider that accepts alternatives for any
  word those languages fold differently. The Craft provider does; Meilisearch does not, so such a
  query is refused there rather than run as one language's reading.
- A synonym group written for one site is not applied to a search covering other sites, since it
  would return results there that a search of those sites alone never had. What was held back is
  reported on the result and in the debugger.
- A rule's boost or bury amount is shared by every result it moves; different amounts for different
  results need one rule each. A rule's results are chosen only after it has been saved with an index
  and, on an index covering every site, a site — both decide what there is to choose from.
- Boosting and burying are skipped past the first 1000 results, where reordering would need more of
  the ranking than is affordable to read; it is recorded rather than applied to the wrong page.
  Which results are hidden, pinned, promoted or redirected is decided the same way at any depth.
- A placed result that has stopped being viewable since its rule was saved is withheld from every
  page, but the total only stops counting it on the page it would have appeared on. On other pages
  the total is one too high. Counting it correctly everywhere would mean loading and authorizing
  placed results on every page, including the ones they never appear on.
- A rule that boosts or buries costs one extra search, to fetch the results it moves. Rules that only
  hide, pin, promote or redirect cost nothing extra.
- Search activity is counted by UTC day, whatever timezone the site runs in.
- A click has to be reported by the page showing the results; nothing is tracked automatically, and
  a result opened more than 24 hours after the search that found it is not associated with it.
- A click on a result past the first 100 a search returned is not associated with that search.
- Recorded searches are deleted for a whole index at a time. There is no way to delete only part of
  what was recorded, other than waiting for retention to reach it.
- The dashboard filters by index, site and date range. There is no filtering by query, and no
  comparison against a previous period.
- Dashboard tables show the top few rows of each metric. Longer listings are readable from PHP.
- The dashboard has a fixed set of panels: they can be arranged, resized and put away, but not
  configured, duplicated or added to.
- The quality score, anomalies, gaps, synonym candidates and recommendations are read from
  recorded activity alone. Nothing is learned, nothing is predicted, and nothing is applied
  automatically.
- Anomaly detection compares two windows of equal length and nothing more. There is no seasonality,
  no trend fitting, and no allowance for a period that is quiet every year.
- A window with fewer than 20 searches is not compared at all, so a quiet index reports no
  anomalies rather than reporting noise.
- Synonym candidates need clicks. An index that does not follow opened results discovers nothing,
  and neither does a period nobody clicked in.
- A synonym candidate is only ever built from searches made under one site scope. Two queries that
  mean the same thing but were only ever searched in different sites are not paired, and a pair
  observed across an index's whole scope does not name a site to write the group for.
- Site scope is what carries language through both of these. A search covering an index's whole
  scope may span several languages, so a candidate or an offered query from one is not attributable
  to any single language either — which is why neither is narrowed to a site inside that scope.
- Past searches are only offered within the site scope they were searched under. A search covering
  an index's whole scope is not offered when suggesting for one site inside it, so a narrowed search
  box on an all-sites index offers only what was searched in that site.
- The minimum searches and minimum days behind an offered query are thresholds on repetition. They
  are not a count of people: Search Kit records nothing that could distinguish one searcher from
  another, and deliberately keeps it that way.
- Content gaps are looked for among the most-searched queries the period returns, not across every
  query recorded in it.
- The search-activity page and the dashboard count slow searches against one threshold when they
  cover several indexes, which is the index filter's own setting or the default. Only the
  intelligence readings judge each search by its own index's threshold, so the two pages can report
  different slow counts for an unfiltered period.
- The intent breakdown covers the most-searched queries of the period, not every query in it.
- The 0.5 / 0.3 / 0.2 quality weights are a product judgement, not a validated model. Nothing was
  fitted to data and they cannot be configured.
- Intent is a dictionary of English cue words plus a part-number signal. It is not measured against
  labelled data, no accuracy is claimed for it, it cannot be configured or extended, and it never
  affects a search. A query in another language is read from its structure alone.
- A recommendation links to the page for writing a rule or a synonym; it does not prefill one.
- The debugger explains one search at a time. It cannot compare two searches, and it cannot answer
  why a particular piece of content was not matched: it explains the results a search returned and
  the ones Search Kit itself took out of it, not everything it did not find.
- The HTTP and GraphQL APIs search published content only. There is no way to search unpublished
  content through either of them, whatever the key or the schema.
- Only search is exposed. Autocomplete, suggestions for a failed search and click reporting are
  available from PHP and Twig, but not over HTTP or GraphQL.
- An API key is scoped to indexes and nothing finer. It cannot be limited to sites, element types
  or filters, and it cannot be regenerated — it is revoked and replaced.
- Rate limiting is per key, per minute, and is held in Craft's cache. Clearing the cache gives every
  key its full allowance back, and two web servers with separate caches each count separately.
- A GraphQL hit names its element and how it ranked. Content is fetched through Craft's own element
  queries, so a result cannot be read and its fields returned in one request.
- A GraphQL search covering every site is refused unless the schema allows every site.
- What a result matched on is worked out from the index's own values, the same way an excerpt is.
  The Craft provider does not report which fields it matched, so a field whose value no longer
  contains the term — because it changed since it was indexed — is not listed.

## Local development

Search Kit is developed as a Composer path repository inside a Craft project. Add the plugin
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

The integration suite boots the Craft project Search Kit is installed in, so run it from that
project's environment.

## License

Search Kit is licensed under [The Craft License](LICENSE.md).
