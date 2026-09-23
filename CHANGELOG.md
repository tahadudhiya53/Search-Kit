# Release Notes for Search Kit

## 1.0.0

Initial release.

### Search

- A provider-independent search layer: Search Kit's own query, result and document types, with each
  provider declaring what it can do. A query asking for something a provider cannot honour is
  refused rather than quietly run as something else.
- Two providers: Craft's own search index, which needs no external service, and Meilisearch 1.x,
  which gives each index a store of its own along with its scoring, highlighting and typo tolerance.
- Search from Twig and PHP, with filters, ranges, facets, sorting, pagination, snippets and
  highlighting.
- Explicit site scope: an index covers one site or every site, and a query may narrow that scope but
  never widen it. A search may name several sites at once.
- Query text is read in the language of each site being searched, with no single language standing
  in for the rest.
- Search covers published content. Anything else needs a signed-in administrator, and every result
  is put to Craft's own permission check.

### Query handling

- A query pipeline that settles what a query means in one place: normalization, search operators,
  stop words and synonyms, before any provider sees it.
- Search operators for phrases, exclusions, alternation and partial matching, configurable per
  index.
- Synonym groups, two-way or one-way, scoped to an index and a site.
- Spelling correction, run only when a search finds nothing, against the words the index holds.
- Autocomplete and no-result suggestions, drawn from the same words and checked against what is
  publicly visible — so a suggestion never leads to another empty result and never names content the
  person searching may not see.
- Optionally, completion from whole queries people have searched for before, subject to thresholds
  on repetition and the same visibility check.

### Indexing

- Indexes and searchable fields configured in the database, with per-field weighting.
- Content synchronisation on Craft's own element events, carried out on the queue: one piece of
  outstanding work per element per site per index, with retries, failure parking and administrator
  recovery.
- Rebuilds that run on the queue with progress reporting, cannot overlap ordinary processing, never
  swallow content that changes mid-run, and report themselves as incomplete rather than claiming a
  completion that did not happen.
- Configuration changes mark an index as owing a rebuild; a rebuild is never started automatically.

### Search rules

- Boost, bury, hide, pin, promote and redirect, triggered by the query text, scoped to an index and
  optionally a site, with priorities and schedules.
- Hidden, pinned and promoted results are settled before the search runs, so hiding is exact at any
  depth and totals describe the results a visitor can actually reach.
- Exclusive and accumulating actions resolve deterministically: the first rule to claim a result
  decides its fate, and adjustments accumulate only on results nothing claimed.
- A placed result is loaded and authorized exactly like one the search found itself, so a rule can
  arrange results but never reveal content a viewer may not see.

### Search activity and intelligence

- Recorded searches — what was searched for, what came back, how long it took and what was opened —
  with nothing identifying who searched.
- Opened results are tied back to their search by a token that identifies the search alone, and only
  where the search really did return that result.
- Insights read back as summaries, popular and zero-result queries, unopened queries, slow queries,
  trends and clicked results.
- A control panel dashboard with arrangeable panels and plain-SVG charts, and a search activity page.
- A *What to do next* page: a quality score out of 100 from real measurements, what has changed
  against the window before, content gaps, synonym candidates and explainable recommendations —
  every one shown with the numbers behind it, and none of it applied automatically.
- Query wording grouped by intent from a cue-word dictionary, which never affects a search.

### Debugger

- A control panel page that runs a real search and explains it: how the query was read, what the
  provider was asked and answered, which rules matched and what they did, what put each result where
  it is, what Search Kit kept out, and where the time went.
- The search records each step as it happens, so the page explains the search that ran rather than a
  reconstruction of it. Provider diagnostics are opt-in and provider settings are never shown.

### HTTP and GraphQL

- A REST search endpoint and a `searchKitSearch` GraphQL query, both running the same search service
  PHP and Twig run, and both searching published content only.
- API keys scoped to indexes, shown once and stored only as a digest, with optional per-key rate
  limiting.
- GraphQL indexes are schema components, so a schema decides which indexes and sites may be
  searched. A hit names its element rather than handing out its content.

### Craft Commerce

- Optional support for products and variants where Craft Commerce is installed, behind an
  integration boundary — nothing Commerce-related is registered without it.
- Products and variants are indexed, filtered, merchandised, recorded and explained by the same
  machinery as every other element type. Commerce query criteria are contributed for filtering, and
  a product is only reindexed on a variant change where that change can actually reach it.
- Pricing that depends on who is asking, and customer, order, payment and address data, are never
  indexed, filterable or returned.
