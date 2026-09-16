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

