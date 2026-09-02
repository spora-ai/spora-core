# Upgrading

## 0.19.0 → 0.20.0

### Media Archive routes moved to the plugin

The following routes have moved from `spora-core` into the `spora-plugin-media-archive`
package:

- `GET /api/v1/media/{id}` → `spora-plugin-media-archive`'s `MediaArchiveAdminController::show`
- `PATCH /api/v1/media/{id}` → `...::update`
- `DELETE /api/v1/media/{id}` → `...::destroy`
- `POST /api/v1/media/{id}/public-token/refresh` → `...::refresh`

If you depend on these endpoints, install and enable `spora-plugin-media-archive` — see
its README. Without the plugin enabled, these endpoints will return `404`.