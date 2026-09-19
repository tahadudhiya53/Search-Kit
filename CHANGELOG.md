# Release Notes for SearchKit

## 1.0.0

### Added

- Initial Craft CMS 5 plugin foundation.
- Core search architecture: a search provider abstraction with explicit capability declarations,
  provider-independent search query and search result models, and a search service that resolves an
  index and its provider, validates the request, and returns normalized results.
- Database-backed search indexes and searchable field configuration, including field weighting.
- A search provider backed by Craft's own search index, declaring only the capabilities Craft can
  actually serve.
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
  SearchKit checks that a document anybody may find still uses it, so nothing from a draft, a
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
- A control panel dashboard, which the SearchKit section itself opens: the totals for a period as
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

### Fixed

- A newly saved search index reported a configuration generation it was not on, so a rebuild started
  from it could never report the index as current.

