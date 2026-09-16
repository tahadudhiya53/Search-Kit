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
