# WP REST API Cache

Caches `GET` responses from the WordPress REST API in transients, so repeated API calls (headless front-ends, mobile apps, JS widgets) skip PHP and the database entirely.

## How it works

- `rest_pre_dispatch` serves a stored transient when the route + sorted query string matches.
- `rest_post_dispatch` stores successful (2xx) responses for the configured TTL (default 300 seconds).
- Every cached response carries an `X-WRAC-Cache: HIT` header; fresh responses carry `MISS`.
- The cache is flushed automatically on `save_post`, post/term deletion, term edits, and theme switches.

## Safety

- Only `GET` requests are cached, and only for logged-out visitors by default (logged-in users usually see personalized data).
- Sensitive routes are excluded by default: `/wp/v2/users`, `/wp/v2/settings`, `/wp/v2/search`.
- Exclusions are prefix matches, so `/wp/v2/users` also excludes `/wp/v2/users/123`.
- Only JSON-serializable payloads are stored, and only 2xx responses.

## Settings

**Settings → REST API Cache**

| Setting | Default |
|---|---|
| Enable caching | On |
| Cache lifetime (seconds) | 300 |
| Cache for logged-in users | Off |
| Excluded routes | `/wp/v2/users`, `/wp/v2/settings`, `/wp/v2/search` |

A **Flush Cache Now** button on the same page deletes every cached response immediately.

## Filters

- `wrac_is_cacheable( bool $cacheable, WP_REST_Request $request )` — decide per-request whether a response may be cached.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
