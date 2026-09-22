# Release Notes for Search Kit

## 1.0.0

### Added

- Initial Craft CMS 5 plugin foundation.
- Core search architecture: a search provider abstraction with explicit capability declarations,
  provider-independent search query and search result models, and a search service that resolves an
  index and its provider, validates the request, and returns normalized results.
- Database-backed search indexes and searchable field configuration, including field weighting.
- A search provider backed by Craft's own search index, declaring only the capabilities Craft can
  actually serve.
- A search provider backed by a Meilisearch server, giving each index a store of its own along with
  Meilisearch's highlighting, typo tolerance and relevance scoring. Meilisearch accepts a change and
  applies it afterwards, so every write is followed until it is confirmed: an indexing operation is
  only settled once the document has actually landed, and a rebuild waits for anything still queued
  before discarding the index it replaces. Field weights become the order
  of its searchable attributes, and sorting is ranked ahead of relevance so an explicit ordering is
  an instruction rather than a tiebreaker.
- Provider settings, declared by the provider itself and edited on the index alongside everything
  else. They are validated before they are stored, so an address a provider cannot be built from is
  a save error rather than something discovered the next time somebody searches. A Meilisearch API
  key must name an environment variable, so the key is never written to the database, shown in the
  control panel, or reported by the debugger.
- Explicit site scope on search indexes: an index covers one site or every site, and a query can
  narrow that scope but not widen it.
- Indexing and content synchronisation: search indexes follow every element save, delete and
  restore, with the work recorded in the database and carried out on Craft's queue. One piece of
  outstanding work is kept per element per site per index, and the newest content change always
  wins, including when it lands while a worker is busy with an older one.
- Search documents: a provider-independent representation of one element in one site, carrying only
  the configured searchable values and their weights.
- Full index rebuilds, with progress reporting, run from the control panel or the command line.
  A rebuild and ordinary indexing never write to one index at the same time, and a rebuild that
  leaves anything behind is reported as incomplete rather than marking the index as current.
- Retry behaviour for failed indexing operations, and a record of the ones that gave up. A value
  that cannot be read is retried rather than indexed as though it were empty, and unexpected
  provider errors are reported without their internals.
- Configuration changes that can invalidate what a provider holds — fields, weights, element types,
  site scope, provider, or re-enabling an index — mark the index as needing a rebuild, so it stops
  reporting itself as current until it is rebuilt.
- An index and its searchable fields are saved as one configuration: an invalid field rolls the
  whole save back and leaves the previous configuration in place.
- Searchable fields are validated against what the element type can actually be indexed on,
  wherever the configuration comes from.
- Each index carries a configuration generation, so a rebuild can only report the configuration it
  actually rebuilt as current. A configuration saved while a rebuild is running keeps owing a
  rebuild instead of being marked indexed by the older one.
- A control panel section for listing, creating, editing, enabling, disabling and deleting search
  indexes, configuring their searchable fields and weights, rebuilding them and retrying failures,
  governed by three user permissions.
- `search-kit/index` console commands for index status, processing, rebuilding and retrying.
- A public search API for PHP and Twig: `craft.searchKit.search()` in templates, and
  `SearchQuery::create()` for PHP, both taking plain parameters for filtering, sorting, paging,
  site, status and excerpts, and validating every one of them before a provider sees it.
- Normalized results: every hit carries its element, whether or not the provider loaded it, and a
  result reports its total, limit, offset, current page, page count and whether there are results
  either side of the window it returned.
- Filtering and sorting through the Craft provider, over element types, sections, entry types, the
  element criteria Craft exposes, and the fields an index is configured to search. A filter or sort
  the index cannot answer is rejected rather than quietly dropped.
- Snippets and highlighting, worked out from the values an index is configured to search, for any
  provider that cannot highlight for itself. Snippets are plain text and highlights are escaped
  before matched terms are marked, so indexed content cannot carry markup into a page. Terms are
  found at the start of a word first, and anywhere in the text for languages that do not separate
  their words.
- An explicit visibility policy for searching: content is published when Craft's own default status
  for its element type says so — `live` for entries, so `enabled` does not expose scheduled or
  expired entries — and any other status requires a signed-in administrator. Authorization is
  settled before a search runs, so every page of it reports the same total.
- Strict types for every search parameter: whole numbers may be given as numbers or as the string
  form of one, booleans must be booleans, and a status no element type has is refused.
- Every result names the site it was found in, and its element is loaded from that site alone. A
  result a provider cannot place inside the scope that was searched is refused rather than guessed
  at, and one whose element cannot be loaded in that site and status is dropped.
- Filtering on custom fields goes through Craft's own field conditions, and a field type Craft
  stores no queryable value for is rejected instead of quietly matching nothing.
- Sorting a search that covers several element types is restricted to attributes every one of them
  carries, with ties broken by element ID; anything Craft can only order in SQL is rejected with an
  explanation instead of being applied to part of the results.
- A query pipeline that settles everything query text means before a provider sees it: normalization,
  operators, tokenization, stop words and synonyms, each in one place. Providers are handed terms
  rather than search syntax, and a query is carried through as a structured set of terms that a
  ranking explanation can later be built from.
- Query normalization shared with indexing, using Craft's own keyword normalization, so what is
  typed and what was indexed are reduced the same way.
- Search operators: phrases, exclusions, alternation and wildcards, each expressed through Craft's
  own search syntax rather than through SQL. Each side of an alternation keeps its own matching. A
  provider that cannot honour an operator refuses the query instead of running a different search, a
  query that only rules things out is rejected, and an exclusion combined with `OR` is refused
  rather than quietly read as one of its two possible meanings.
- Configurable partial matching per index, with a minimum term length, so a short word does not
  match everything. Exclusions are always matched whole.
- Stop words, with a built-in English list and per-index additions. A query made of nothing but
  stop words is searched as written rather than emptied.
- Database-backed synonyms: two-way groups whose terms stand in for each other, and one-way groups
  that expand in one direction only. Each group covers one index or all of them, one site or all of
  them, and its words are normalized on save the way indexed content is. Groups are cached, and a
  save or delete is visible to the next search.
- Typo tolerance: a search that found nothing is retried against the words the index holds, and the
  result says what was searched for instead. An insertion, a deletion, a substitution or a swap of
  two neighbouring characters each count as one edit, any character can be corrected including the
  first, and every word close enough is weighed rather than an arbitrary sample of them. Correction
  never runs on a search that found something, and a provider that tolerates typos itself is left
  to do it.
- Search suggestions and autocomplete, drawn from the words an index holds so nothing is ever
  suggested that the index cannot find. A search that found nothing carries alternatives, and
  autocomplete completes what has been typed without running a search.
- A record of the words each index holds, one row per word per document, so a word disappears once
  the last document using it stops using it and survives while any other still does. Updates,
  deletions, restores and rebuilds all keep it in step, and indexing that failed changes nothing.
- Suggestions describe published content only. Before a word is completed, corrected to or offered,
  Search Kit checks that a document anybody may find still uses it, so nothing from a draft, a
  disabled entry, one not yet posted, one expired or one deleted can be suggested to anyone. Every
  document using a word is checked until one proves it may be shown, and completions and corrections
  are read a batch at a time until enough of them may be shown, so no suggestion is lost to a fixed
  sample of the content behind it.
- Better snippets: a long value shows several excerpts rather than only its first match, phrases are
  marked as one rather than word by word, and terms are marked as the pipeline made them, so a
  correction or a synonym is marked where it matched.
- Control panel management of synonyms, and of how queries against an index are read. Neither
  changes what is indexed, so neither costs a rebuild. Search behaviour is validated rather than
  coerced, so a value that cannot be read is reported instead of being saved as something else.
- `craft.searchKit.autocomplete()` and `craft.searchKit.suggest()` in templates.
- Search rules: deliberate control over what a particular query returns. A rule belongs to one index
  and optionally to one site, is triggered by exact, contains, starts-with, ends-with or wildcard
  query text, and can boost, bury, hide, pin, promote or redirect. Rule text is normalized the same
  way query text is, and a pattern is a wildcard rather than a regular expression.
- Deterministic rule priority: rules are applied highest priority first and oldest first within a
  priority. Hiding, pinning and promoting are exclusive and the first rule to claim a result keeps
  it, whatever any later rule asks for; boosts and buries accumulate, but only on results no rule
  claimed. Two pins cannot share a position, and only the highest-priority redirect is offered.
- Hidden, pinned and promoted results are left out of the search itself and the placed ones put back
  at their own positions, so a hidden result can never appear on any page however far down it ranked,
  a placed result appears and is counted exactly once, and totals describe the results a visitor can
  actually reach. Providers declare whether they can leave results out, and one that cannot is
  refused rather than hiding only what it happened to read.
- The results a boost or a bury moves are held back from the ranked list and asked for by name, so a
  boost lifts a result onto the first page however far down it ranked, at the score the search gave
  it, and without adding a result the search never matched. Past the first 1000 results the
  adjustment is recorded as skipped rather than applied to the wrong page, as it is when the search
  supplies its own ordering.
- Search rules are scoped by site: a rule naming a site only ever affects that site's results, even
  on a search covering every site, and the same element in another site is left alone. Precedence is
  settled per site, so a site rule that outranks a global one keeps its own site while the global
  rule still governs the rest, and a rule for one site never blocks a rule for another. Pinning and
  promoting need one site to place the result in and never fall back to the primary site.
- Every rule target is checked when the rule is saved — it must exist, be a real element type, be a
  type the rule's index searches, be reachable in the rule's site, and not be in the trash — so
  nothing a control panel form posts is trusted.
- What a placed result may show is settled every time a search runs, not when the rule was saved: a
  pinned or promoted result is loaded under the search's own status and put to Craft's authorization
  with every other result, so one that has since become disabled, unposted, expired, disabled for the
  site, trashed or otherwise unviewable is withheld from everybody. It is taken off the total on the
  page it would have appeared on, and the rule explanation reports it as not viewable without naming
  it, so nothing identifies content the viewer was not allowed to see.
- Rules are matched against the query that actually ran: a search corrected for a typo has its rules
  read again against the corrected text, so merchandising is not bypassed by a correction. A search
  whose rules place results is never corrected, since those results are held back from the provider
  and its empty answer does not mean the search found nothing.
- Scheduled and switchable rules: a start date, an end date, or both, entered in Craft's system
  timezone and held in UTC, so a schedule means the moment it was given wherever it is read. Both
  ends are inclusive.
- Redirects are offered on the result rather than performed, and may only be a site-relative path or
  an http(s) address. A redirect acts on the whole search, so a rule naming one site redirects a
  search of that site alone and never one covering every site.
- Every search carries what each rule did, matched or not, and what happened to each result. A hit's
  provider score is kept apart from what the rules moved it by, so both can be read.
- A control panel section for listing, creating, editing, enabling, disabling, scheduling, ordering
  and deleting search rules, governed by a permission of its own so merchandising can be delegated
  without handing over index configuration.

- Search activity recording: every search can be recorded with what was searched for exactly as it
  was typed, the form it normalized to, what it was corrected to, its index, site and language, how
  many results came back and how long it took. Queries group on the normalized form, so what was
  typed stays readable without splitting one query into several. Nothing recorded identifies who searched — no account, no address, no session
  and no identifier of any kind.
- Recording hangs off the search event rather than sitting inside the search, so search behaviour is
  unchanged whether anything is listening or not, a recorded search costs one insert, and a search
  that cannot be recorded still returns its results.
- Click tracking: a recorded search hands back a token a template posts with the result that was
  opened, which is the only thing tying the two together. A recorded search remembers which results
  it returned and in which site, and a click is only accepted when it names one of them — so a
  result from another site, one the search never returned, one past the window it returned, an
  element since deleted, and an invented or expired token are all refused. Where the result sat is
  read from the search rather than posted, and the same result reported twice for one search is
  counted once. A search remembers at most its first 100 results for this, and none at all when the
  index does not follow clicks.
- Date filtering that covers whole days: the start of a range is inclusive and its end is
  exclusive, so asking for activity up to a day counts everything that happened on it, in the
  timezone the day was chosen in.
- Search metrics, each read as one grouped query over an indexed date range and reused only while
  nothing new has been recorded, so no two pages can disagree about what has happened:
  total searches, unique queries, popular queries, zero-result searches and their rate, search and
  per-query trends by day, result clicks, click-through rate, average response time, slow searches
  and content gaps — queries searched for repeatedly that nothing ever came of.
- Per-index analytics settings: whether searches are recorded, whether opened results are, how many
  days recorded searches are kept, and what counts as slow. There is no unlimited retention; anything
  past an index's retention is deleted by Craft's own garbage collection.
- A control panel section listing recorded searches, filtered by index, site and date range, with the
  totals for what is shown and a way to forget every search recorded for an index or for all of
  them, governed by permissions of their own so measurement can be delegated without handing over
  index configuration.
- A control panel dashboard, which the Search Kit section itself opens: the totals for a period as
  cards, search activity and response time over it as charts, and compact tables of what was
  searched for most, what returned nothing, what was opened most, what nothing ever came of, and
  what ran slowest — alongside the state of every
  index serving them. It reads the same index, site and date filters throughout, says what is
  missing rather than drawing an empty chart, and keeps the rest of the page standing when a reading
  cannot be taken.
- The results people opened most, as a metric of its own, named in one query per element type and
  site so a list of them costs no more lookups than a single one. A result that can no longer be
  read keeps its count and loses its name.
- Response time is read day by day alongside searches, and a period too long to read a day at a time
  is grouped into fewer points without losing any of what it counted.
- The dashboard can be arranged: every panel can be dragged into place, made one to four columns
  wide, put away and brought back. An arrangement is the one person's own,
  saved for them alone, and decides nothing about what anybody may see. Dragging uses Craft's own
  drag sorting; everything else works without JavaScript.

- Search intelligence: a control panel page reading recorded activity a step further, and the same
  readings from PHP. A quality score out of 100 from three measured shares of the period — searches
  that found something, searches a result was opened from, and searches inside the slow threshold of
  their own index — weighted 0.5, 0.3 and 0.2 as an explicit product judgement rather than a fitted
  model, with any part nothing was recorded about left out and the remaining weights shared out again
  rather than scored as zero. Over several indexes, engagement is measured only over the searches
  whose index follows opened results, with that subset as its denominator, and speed judges each
  search by its own index's threshold, so two indexes that disagree are not both measured against one
  of the two numbers. Anomaly detection comparing the
  last window with the one before it on zero-result rate, volume and response time, where a window
  with too few searches behind it is not compared at all and the only thing reported from an empty
  one is search having stopped. Content gaps: popular queries that come back with nothing, or with
  nothing anybody opens. Synonym discovery from queries people open the same results from, offered
  with its evidence for an administrator to decide on and never applied; pairs are built within one
  site scope, so what people did in one site is never weighed against what they did in another, and
  each candidate names the scope it was observed in. Recommendations — improve
  the content, create a rule, create a synonym, promote a result — each carrying the numbers it was
  read from. And a reading of what queries are after, from cue words and part numbers, which
  reports the words that decided it, says nothing at all for a query pointing equally two ways, and
  never affects a search.
- Completions drawn from what has been searched for before, ahead of the ones read from the index's
  own words. Off until an index asks for it, since it shows one person's wording to the next: a past
  query is only offered once it found results, something was opened from it, it was searched for
  repeatedly across separate days, and every word of it is still a word publicly searchable content
  uses. They are scoped to the sites being suggested for and read in those sites' language, so a
  query from one site is never offered in another.
- A search debugger: a control panel page that runs a real search and explains it. The search
  records what it does as it does it — how the query normalized, the terms the provider was given,
  every call made to the provider with the window it asked for and what came back, the time each
  stage took, every rule considered and what each of its actions did, and for each result what it
  matched on, the provider's own score, what the rules moved it by and what it was ranked by.
  What Search Kit kept out is listed with the reason: the rule that removed it before the search ran,
  or that a result found could not be shown in the site and status asked for. What was typed is
  always kept beside what a correction searched for instead, a result a rule placed is explained as
  placed rather than scored, and a provider is asked which of its diagnostics may be shown rather
  than having its metadata reported as it stands. It is governed by a permission of its own, shows
  no provider settings, and a search run to diagnose one is not counted as search activity.

- An HTTP search endpoint, at `/search-kit/api/search`, taking an index, a query, filters, sorting
  and paging, and answering with the result as plain data. It runs the same search service PHP and
  Twig run, so anything the search API rejects is rejected there in the same words. A hit reports
  what matched, how it ranked, and the element's title and URL — field values are not part of it.
  Every failure has one shape and the status that goes with it, and an unexpected one is logged
  rather than described.
- Database-backed API keys, created and revoked from the control panel under a permission of their
  own. A key is shown once and stored only as an irreversible digest, so it can never be shown,
  recovered or logged again. It is scoped to the indexes it may search — one it may not search is
  reported as though it were not there — carries a rate limit in requests a minute, and is refused
  exactly as an unknown key is once it is disabled. It travels in the `Authorization` header alone,
  and its use is recorded at most once a minute so searching stays a read.
- A GraphQL query, `searchKitSearch`, added to Craft's own GraphQL API and resolved through the same
  search service. Each search index is a schema component, so a schema decides which indexes may be
  searched; an index it does not name is reported as though it were not there. A site the schema
  does not allow cannot be searched, and a search covering every site is refused unless the schema
  allows every site. A hit names its element and how it ranked, leaving the content behind it to
  Craft's own element queries, where the schema decides which fields may be read.
- Optional Craft Commerce support, as an integration rather than a dependency. Search Kit's core
  names no Commerce class — the two class names in the codebase are strings inside one service — so
  nothing can autoload Commerce that is not installed, and without the Commerce plugin that service
  registers nothing at all. Where Commerce is installed, products and variants become indexable
  element types and are then configured, indexed, searched, merchandised, recorded, explained and
  served over both APIs by the machinery every other element type uses: no second search path, no
  second rules engine, no Commerce endpoints. Verified against Commerce 5.7.4.
- Commerce filter criteria, resolved against the query the installed Commerce actually defines
  rather than a fixed list. Products can be filtered by `defaultSku`, `defaultPrice` and their
  default dimensions; variants by `sku`, `price`, `stock`, `hasStock`, `hasUnlimitedStock`,
  `inventoryTracked`, `availableForPurchase`, `isDefault`, `productId`, quantity limits and
  dimensions. Product type and category go through the `type`, `typeId` and `relatedTo` criteria
  every element type already has. Promotional and sale pricing, `forCustomer` and `hasVariant` are
  refused rather than offered: what a shopper pays depends on catalog pricing rules and on who is
  asking, so an anonymous search has no deterministic answer, and `hasVariant` takes a query rather
  than a value.
- Changes to a product's **default** variant reach the product, because its searchable attributes
  are the values Commerce fills from that variant — but only where an index searches one of them,
  since a custom field on the product's own layout cannot carry variant data. A variant that is not
  the default contributes nothing to a product's document and is not followed, so the product is
  not even loaded. A failure there can never fail a Commerce save.
- Filtering by relationship on the Craft provider, through `relatedTo`. It names elements by ID only
  and cannot be negated, so a search can be narrowed to a category or anything else a relation field
  points at without a relation criteria structure ever reaching Craft from a caller.
- A seam for registering further element query criteria per element type, which is how an
  integration extends what the Craft provider will filter on without the provider knowing what
  defines them.
- Faceted counts: a search can be counted by one or more fields, and the counts describe the whole
  result set rather than the page it returned. Counting is a provider capability — a provider that
  cannot count is refused rather than answered with nothing. The Craft provider counts by the kind
  of element, by site, and by any column the element type's own table holds, read from the search
  Craft itself prepared rather than from a list kept in Search Kit; Meilisearch counts by its own
  attributes. Counts are exposed to Twig, PHP, REST and GraphQL.
- Range filters, through a `between` operator taking a lowest and a highest value and including
  both. Both providers express it in their own dialect, so a price range works on either.
- Searching several named sites at once, given as handles, IDs or `Site` models. A list may narrow
  an index's scope but never widen it, every result still carries its own site, and a rule acts only
  in a site the search actually named — including a redirect, which still needs a rule covering the
  whole search.
- Language-aware analysis. Craft folds characters differently per language, so content is now
  reduced in the language of the site it belongs to and a query is read in the language of the site
  it is searching, which is what Craft's own index does. Configured synonyms are held in the
  language of the site they were written for, and the built-in English stop word list is only
  applied to a search read in English. A Meilisearch rebuild declares the languages the index's
  content is written in, so Meilisearch tokenizes and stems it accordingly.
- Provider capabilities are shown on the index's own page, so what an index can be asked for is
  visible where its provider is chosen rather than discovered when a search is refused.
- A search covering sites written in different languages is read in each of them. No single language
  stands in for the others: every language the searched sites use reads the text for itself, and a
  word two of them fold differently is accepted in both readings, so neither site is searched for
  the other's spelling. Where the languages agree the term stays one term, and a provider that
  cannot be given alternatives refuses such a query rather than running one reading as the other.
  Where the languages do not read the text as the same words at all — a word to one of them and
  nothing to another — the search is refused as an invalid query rather than run as either reading.
- A synonym group written for one site is no longer applied to a search covering other sites, where
  it would have returned results those sites never had on their own. An expansion is applied only
  where it holds in every site being searched, and what was held back is reported on the result and
  in the debugger.
- Completions, corrections and no-result suggestions read only the sites the search covers, so a
  word another site holds is never offered — and each language reads what was typed for itself.
- The built-in English stop word list is applied only where every language being searched is
  English. A configured stop word still applies in any language, in each language's reading of it.
- A range written backwards is refused rather than quietly matching nothing, for numbers and dates.
- A GraphQL query refused over its parameters now says which one and why, as the REST layer does.

### Fixed

- A search covering more than one site recorded the application's language as the language it was
  read in, and recorded that language for every site-specific synonym group as well. A search
  spanning two languages now records neither a site nor a language, which needs
  `searchkit_searchevents.language` to be nullable.
- A newly saved search index reported a configuration generation it was not on, so a rebuild started
  from it could never report the index as current.
- Every search index was saved with the same placeholder identifier instead of one of its own,
  which a GraphQL schema now relies on to tell one index from another.
- A page that reached into the pinned and promoted results was read at a shifted offset whenever
  the results ran past the reordering window, which dropped the placed results below it and could
  ask the provider for a negative offset. Giving up the reordering no longer gives up assembling
  the page.
- A Meilisearch server error while checking whether an index exists was read as the index being
  absent, so a rebuild discarded nothing and then failed creating an index that was already there.
  Only a 404 now means absent; anything else is a failure.

