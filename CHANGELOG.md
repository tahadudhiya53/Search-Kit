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
