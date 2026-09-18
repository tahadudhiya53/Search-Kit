# SearchKit

Search management and intelligence for Craft CMS.

## Status

**Early development.** SearchKit can run searches from PHP and Twig, and keep indexes in step with
Craft content. It provides search providers behind a single interface, provider-independent query,
result and document objects, database-backed search indexes and searchable field configuration, a
search service that runs a query through an index's provider, filtering, sorting, pagination,
snippets and highlighting, and queue-backed indexing that follows every content change. What a
visitor types goes through a query pipeline of its own — normalization, operators, stop words,
synonyms and typo correction — and a search that finds nothing offers something else to try. Search
rules give deliberate control over what a particular query returns: boost, bury, hide, pin, promote
and redirect, on a schedule and in a fixed priority order. Indexes, rules, synonyms and search
behaviour are managed from the control panel or from PHP. A provider backed by Craft's own search
index ships with it.

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
  conditions and sort options. It also honours phrases, exclusions, alternation and partial
  matching, which it expresses through Craft's own search syntax rather than through SQL of
  SearchKit's making.
- It cannot weight fields or highlight, so a query asking for either is rejected rather than
  quietly run without it — highlighting is instead worked out by SearchKit itself, as described
  under [Snippets and highlighting](#snippets-and-highlighting).
- **It has no typo tolerance of its own**, so SearchKit corrects for it, as described under
  [Typo tolerance](#typo-tolerance). A provider that declares the capability keeps its own answer
  and SearchKit stays out of the way.
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
same way, so `Café` finds `cafe` and SearchKit never disagrees with what Craft indexed.

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
English; **Extra stop words** adds to it. A query made of nothing but stop words is left alone
rather than emptied, so searching for `the who` still searches for something.

### Synonyms

A synonym makes a search for one word find the others as well. They are managed under
**SearchKit → Synonyms**, and each group covers one index or all of them, and one site or all of
them.

| Type | Behaviour |
|---|---|
| Two-way | Every term stands in for the others. `boots, footwear, shoes` — any of them finds all of them. |
| One-way | The terms also search for the replacements, never the other way around. `tv → television`. |

A term may be a word or a phrase, and is normalized on save exactly as indexed content is, so a
synonym cannot fail to match over a capital letter or an accent. Synonyms are configuration rather
than something a query asked for, so a provider that cannot offer alternatives simply goes without
them instead of refusing the search. Groups are cached, and a save or delete is visible to the very
next search.

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

### The words an index holds

These come from the content SearchKit indexes. Each word is recorded against the document it was
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
offered, SearchKit checks that a document anybody may find still uses it — Craft's own definition of
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
- Where SearchKit applies Craft's `canView()` — on any search of something other than published
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
| Search behaviour, or synonyms | no |

Saving a configuration is a short database transaction; it never waits for a running rebuild.
SearchKit does not rebuild by itself: rebuilding can be expensive, so it is left as a deliberate
step. Changing an index's provider leaves whatever the previous provider held untouched — nothing
is migrated, and nothing pretends it was.

A disabled index is not written to, not rebuilt, and not searched. Outstanding work is kept rather
than discarded, but nothing is sent to the provider until the index is switched back on. Content
changes are not tracked while it is off, which is why switching it back on marks a rebuild as owed.

### Control panel

**SearchKit → Indexes** lists indexes with their pending and failed counts, when they were last
indexed and whether they are current, and lets you create, edit, enable, disable and delete
indexes, choose their searchable fields and weights, set how queries against them are read,
rebuild them, and retry failed operations. An index is only shown as current when it is enabled,
nothing is outstanding, nothing has failed, no rebuild is owed and the provider says it can serve.

**SearchKit → Rules** lists and edits search rules: the query that triggers them, what they do to
the results, their schedule and their priority.

**SearchKit → Synonyms** lists and edits synonym groups.

Four permissions govern all of it: viewing, managing indexes, rebuilding or retrying, and managing
rules. Synonyms and search behaviour are managed under the same permission as the indexes they
belong to; rules have their own, so merchandising can be delegated without handing over index
configuration.

### Commands

```bash
php craft search-kit/index/status            # every index, and what it owes
php craft search-kit/index/process <handle>  # work through what an index owes, now
php craft search-kit/index/rebuild <handle>  # queue a rebuild, or --now to run it here
php craft search-kit/index/retry <handle>    # put failed operations back in the queue
```

## Limitations

What SearchKit does not do yet, and the limits of what it does.

- Only entries, categories, assets and users are offered as indexable element types.
- Only the Craft provider ships, with the constraints described above — including that it shares
  Craft's single search index rather than giving each SearchKit index its own store.
- A rebuild is never started automatically after a configuration change; it is reported as owed.
- Suggestions describe published content only, for everybody. There is no way to complete or
  correct against unpublished content, even as an administrator.
- The built-in stop word list is English. Any other language needs its own words configured.
- `-` and `OR` cannot be combined in one term; the query is refused rather than reinterpreted.
- Suggestions are ranked by how little they change what was typed. Nothing knows what is popular,
  because nothing about what visitors search for is recorded.
- No faceting or field-scoped search.
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
- No analytics or debugger.

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
