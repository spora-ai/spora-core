# Error Handling

## Overview

Spora uses a consistent JSON error envelope across all API endpoints and a toast-based notification system on the frontend. The backend is the single source of truth for error codes; the frontend maps them to severity levels and display strategies.

---

## Backend Error Format

All API error responses follow this envelope (`docs/04_api.md:5-9`):

```json
{
  "error": {
    "code": "MACHINE_CODE",
    "message": "Human-readable description."
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `code` | `string` | Machine-readable identifier. Deterministic — the frontend can use this to route error handling. |
| `message` | `string` | User-safe message. Shown as-is in toasts. May contain technical detail in `development` mode. |

**Note:** Rate-limited responses carry the retry interval via the standard `Retry-After` HTTP response header, not as a JSON body field (`app/Http/AuthController.php:421`). The `retryAfter` and `action` fields shown in earlier drafts are not part of the current envelope.

### Error Code Registry

Codes below are returned by controllers. `LLM_PROVIDER_ERROR` and `LLM_RATE_LIMIT` are listed in `frontend/src/utils/errorMapper.ts:55-79` but are NOT used by any controller — they exist for forward compatibility and are mapped if a future endpoint surfaces them.

| Code | HTTP Status | Severity | Description |
|------|-------------|----------|-------------|
| `VALIDATION_ERROR` | 422 | `warning` | Form input failed server-side validation. |
| `INVALID_JSON` | 400 | `warning` | Malformed request body. |
| `BAD_REQUEST` | 400 | `warning` | Malformed path parameter (`app/Core/Router.php:64`). |
| `UNAUTHENTICATED` | 401 | `error` | No valid session. Frontend shows expiry toast and routes to `/login`. |
| `INVALID_CREDENTIALS` | 401 | `error` | Login failed — wrong email or password (`app/Http/AuthController.php:106`). |
| `WRONG_PASSWORD` | 401 | `error` | Current-password check failed during a password change (`app/Http/AuthController.php:194`). |
| `INVALID_PASSWORD` | 422 | `error` | New password does not meet the policy (`app/Http/AuthController.php:192`). |
| `ACCOUNT_UNVERIFIED` | 403 | `error` | Account email not verified. |
| `EMAIL_NOT_VERIFIED` | 403 | `error` | Must verify current email before changing it (`app/Http/AuthController.php:357`). |
| `REGISTRATION_DISABLED` | 403 | `error` | Public registration is disabled. |
| `FORBIDDEN` | 403 | `error` | User lacks permission for this resource. |
| `NOT_FOUND` | 404 | `warning` | Resource does not exist or is not visible to this user. |
| `NOT_CONFIGURED` | 503 | `warning` | Subsystem not configured (e.g. SSE; `app/Http/SseController.php:55`). |
| `EMAIL_TAKEN` | 409 | `warning` | Registration or email-change attempted with an existing email. |
| `INVALID_STATE` | 409 | `warning` | Operation invalid in current state (e.g., approve non-pending task). |
| `INVALID_TOKEN` | 400 | `warning` | Confirmation / reset selector-token pair is invalid. |
| `TOKEN_EXPIRED` | 400 | `warning` | Confirmation / reset token has expired. |
| `RESET_DISABLED` | 403 | `warning` | Password reset is disabled in this build. |
| `TOO_MANY_REQUESTS` | 429 | `warning` | Auth endpoint rate limit hit. Check the `Retry-After` header. |
| `CSRF_TOKEN_MISSING` | 403 | `warning` | Required `X-CSRF-Token` header missing on a state-changing request. |
| `CSRF_INVALID` | 403 | `warning` | CSRF token did not match the session value. |
| `AUTH_ERROR` | 500 | `error` | Unspecified auth library failure (catch-all). |
| `LLM_PROVIDER_ERROR` | — | `warning` | Reserved. Currently unused by controllers; mapped in `errorMapper.ts`. |
| `LLM_RATE_LIMIT` | — | `warning` | Reserved. Currently unused by controllers; mapped in `errorMapper.ts`. |
| `DECRYPTION_FAILED` | 422 | `error` | Stored settings could not be decrypted (key mismatch or corruption). |
| `INTERNAL_SERVER_ERROR` | 500 | `error` | Unexpected server failure. Technical detail shown in `development` mode only. |

### HTTP Success Envelope

Successful responses wrap data in a `data` key:

```json
{
  "data": { ... }
}
```

---

## Backend Exception Handling

`app/Core/Kernel.php` owns global exception handling via `Kernel::handleException()` (`app/Core/Kernel.php:101-157`).

### Development Mode

When `app_env` is `development` or `local` (`app/Core/Kernel.php:126`), the error response includes a `debug` object:

```json
{
  "error": {
    "code": "INTERNAL_SERVER_ERROR",
    "message": "An unexpected error occurred."
  },
  "debug": {
    "exception": "ClassName",
    "message": "Original message",
    "file": "/path/to/file.php",
    "line": 42
  }
}
```

### Production Mode

No stack traces, no file paths. The `message` is a generic fallback for 5xx errors.

---

## Frontend Architecture

### Severity Levels

| Level | Style | Auto-dismiss |
|-------|-------|--------------|
| `error` | Red, alert icon | No — persistent until user dismisses |
| `warning` | Amber, warning icon | 8 seconds |
| `success` | Green, check icon | 4 seconds |
| `info` | Blue, info icon | 4 seconds |

### HTTP Status → Severity Mapping

Implemented in `frontend/src/utils/errorMapper.ts:15-48`:

| HTTP Status Range | Severity |
|-------------------|----------|
| 200–299 | (success — no error display) |
| 400–499 (except 401, 403, 404, 429) | `warning` |
| 401 | `error` → redirect to `/login` |
| 403 | `error` |
| 404 | `warning` |
| 429 | `warning` |
| 500+ | `error` |

---

## Toast Notification System

### File Structure

```
frontend/src/
├── components/ui/
│   ├── Toast.vue              # Single toast: icon, message, dismiss button, progress bar
│   └── ToastContainer.vue    # Portal-mounted queue, bottom-right (desktop) / top-center (mobile)
├── composables/
│   └── useToast.ts            # toast.success / warning / error / info(message, opts?)
└── utils/
    └── errorMapper.ts         # HTTP status → { severity, code, action }
```

### Toast.vue Props

| Prop | Type | Description |
|------|------|-------------|
| `id` | `string` | Unique identifier (used by container to track dismissals) |
| `severity` | `'error' \| 'warning' \| 'success' \| 'info'` | Controls icon, color, auto-dismiss |
| `message` | `string` | Primary text content |
| `action` | `string?` | Optional button label (e.g., `"Retry"`, `"Login"`) |
| `onAction` | `(() => void)?` | Callback when action button is clicked |
| `onDismiss` | `() => void` | Remove this toast from the queue |

### useToast.ts API

```typescript
const toast = useToast()

toast.success('Agent saved successfully.')
toast.warning('Validation failed. Check your input.')
toast.error('Session expired. Please log in again.', { action: 'login' })
```

`ToastOptions` (`frontend/src/composables/useToast.ts:17-21`) accepts `action`, `onAction`, and `retryAfter`. The `retryAfter` field is currently stored on the toast item but is not consumed by `Toast.vue` (auto-dismiss is severity-based, not time-relative); the value is available for future UI work.

### Global Error Handler

Session-expiry handling is registered in `frontend/src/App.vue:26-49` via `setupSessionHandler` (set up in `frontend/src/api/client.ts:21-23`). When the API client sees a 401 with `code: "UNAUTHENTICATED"` on a request made while the user is logged in, it invokes the registered handler, which shows a toast and either lets the user click an action button or auto-redirects after 3 seconds. `main.ts` does not register a `app.config.errorHandler` — uncaught Vue errors fall through to the browser console.

---

## Error Display Strategies

### Strategy 1: Inline (Existing Pattern)

For form validation errors on the same page as the form. No toast — error appears next to the relevant field.

**Used by:** Login, Register, Agent creation/edit, LLM Config creation/edit.

```vue
<p role="alert" class="text-sm text-destructive">{{ error }}</p>
```

### Strategy 2: Toast

For operations triggered by buttons, async polling, or background actions where no form field is directly tied to the error.

**Used by:** Task approve/reject failures, agent deletion, LLM config set-default, and 401 session-expiry. Uncaught Vue runtime errors currently fall through to the browser console (see "Global Error Handler" above).

### Strategy 3: Inline + Toast

When both the field and a broader context matter. Validation errors on a form also show a toast with the summary.

**Used by:** None currently — reserved for future use if complexity grows.

### Strategy 4: Redirect

Only for `UNAUTHENTICATED` (401). The router guard on `authStore` (`frontend/src/router/index.ts:161-175`) redirects unauthenticated visitors to `/login`. The attempted URL is **not** currently passed through as a `?redirect=` query parameter — that round-trip is on the roadmap. The 401 session-expiry flow is handled separately by `App.vue:26-49`, which redirects after the user dismisses the toast (or after 3 s).

---

## Existing Patterns to Preserve

- **Store-level errors:** thrown from store actions, caught by calling pages, displayed inline. Stores do NOT show toasts — they propagate.
- **Polling errors (tasks.ts):** silently swallowed in polling loops (no toast for stale polls). Only surfaced on user-initiated actions.
- **Auth store (auth.ts):** `init()` silently handles "no session" as non-error (`frontend/src/stores/auth.ts:42-50`). `logout()` is optimistic — errors swallowed via `.catch(() => {})` (`frontend/src/stores/auth.ts:83`).

---

## Backend Changes

1. `app/Core/Kernel.php` — add `retryAfter` and `action` fields to rate-limit error responses.
2. Add `LLMProviderError` and `LLMRateLimit` exception classes that carry structured context from driver responses.
3. Add `DECRYPTION_FAILED` code to `DecryptionFailedException` handler.

## Frontend Changes

1. Create `Toast.vue` component.
2. Create `ToastContainer.vue` (teleport to `<body>`, manages queue).
3. Create `useToast.ts` composable.
4. Create `errorMapper.ts` utility.
5. Register global error handler in `main.ts`.
6. Update `api/client.ts` to dispatch toasts on non-2xx responses (opt-in per call via `options.showToast`).
7. Update `stores/auth.ts` to show toast + redirect on 401.

---

## Implementation Status (Audit)

The "Backend Changes" and "Frontend Changes" sections above were an early design plan. Most items are now implemented:

- `Toast.vue`, `ToastContainer.vue`, `useToast.ts`, `errorMapper.ts` exist (`frontend/src/components/ui/`, `frontend/src/composables/useToast.ts`, `frontend/src/utils/errorMapper.ts`).
- `LLMProviderException` and `LLMRateLimitException` exist (`app/Drivers/Exceptions/`).
- Session expiry is handled in `App.vue` (not `main.ts`).
- `api/client.ts` does not implement an `options.showToast` flag — the API client throws `ApiError` on non-2xx, and the calling code decides whether to surface it as a toast.

---

## Out of Scope

- Error deduplication (future, only if rapid duplicate errors become a problem)
- Error logging service integration (handled by PSR-3 Monolog already)
- Field-level validation error mapping (server returns flat `message`; inline pattern is sufficient for current forms)