# Error Handling

## Overview

Spora uses a consistent JSON error envelope across all API endpoints and a toast-based notification system on the frontend. The backend is the single source of truth for error codes; the frontend maps them to severity levels and display strategies.

---

## Backend Error Format

All API error responses follow this envelope:

```json
{
  "error": {
    "code": "MACHINE_CODE",
    "message": "Human-readable description.",
    "retryAfter": 30,
    "action": "retry"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `code` | `string` | Machine-readable identifier. Deterministic — the frontend can use this to route error handling. |
| `message` | `string` | User-safe message. Shown as-is in toasts. May contain technical detail in `development` mode. |
| `retryAfter` | `int?` | Seconds until the client should retry. Present on rate-limit errors. |
| `action` | `string?` | Hint for the frontend: `"retry"`, `"login"`, `"contact"`. |

### Error Code Registry

| Code | HTTP Status | Severity | Description |
|------|-------------|----------|-------------|
| `VALIDATION_ERROR` | 422 | `warning` | Form input failed server-side validation. |
| `INVALID_JSON` | 400 | `warning` | Malformed request body. |
| `UNAUTHENTICATED` | 401 | `error` | No valid session. Frontend redirects to `/login`. |
| `ACCOUNT_UNVERIFIED` | 403 | `error` | Account email not verified. |
| `REGISTRATION_DISABLED` | 403 | `error` | Public registration is disabled. |
| `FORBIDDEN` | 403 | `error` | User lacks permission for this resource. |
| `NOT_FOUND` | 404 | `warning` | Resource does not exist or is not visible to this user. |
| `EMAIL_TAKEN` | 409 | `warning` | Registration attempted with an existing email. |
| `INVALID_STATE` | 409 | `warning` | Operation invalid in current state (e.g., approve non-pending task). |
| `RATE_LIMIT` | 429 | `warning` | Too many requests. Check `retryAfter`. |
| `LLM_PROVIDER_ERROR` | 502 | `warning` | Upstream LLM API error (rate limit, timeout, invalid response). |
| `LLM_RATE_LIMIT` | 429 | `warning` | LLM provider hit its quota or rate limit. |
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

`app/Core/Kernel.php` owns global exception handling via `Kernel::handleException()`.

### Development Mode

When `app_env` is `development` or `local`, the error response includes a `debug` object:

```json
{
  "error": {
    "code": "INTERNAL_SERVER_ERROR",
    "message": "Something went wrong."
  },
  "debug": {
    "exception": "ClassName",
    "message": "Original message",
    "file": "/path/to/file.php",
    "line": 42,
    "trace": ["..."]
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

| HTTP Status Range | Severity |
|-------------------|----------|
| 200–299 | (success — no error display) |
| 400–499 (except 401) | `warning` |
| 401 | `error` → redirect to `/login` |
| 403 | `error` |
| 404 | `warning` |
| 429 | `warning` |
| 500+ | `error` |
| Network failure | `error` |

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
toast.info('Rate limited. Retrying in 30 seconds.', { retryAfter: 30 })
```

### Global Error Handler

Registered in `main.ts` via `app.config.errorHandler`:

```typescript
app.config.errorHandler = (err, instance, info) => {
  // Uncaught Vue errors → error toast
  useToast().error('An unexpected error occurred.')
  console.error(err, info)
}
```

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

**Used by:** Task approve/reject failures, agent deletion, LLM config set-default, network errors, uncaught exceptions.

### Strategy 3: Inline + Toast

When both the field and a broader context matter. Validation errors on a form also show a toast with the summary.

**Used by:** None currently — reserved for future use if complexity grows.

### Strategy 4: Redirect

Only for `UNAUTHENTICATED` (401). The router guard on `authStore` redirects to `/login` and preserves the attempted URL so the user can return after authenticating.

---

## Existing Patterns to Preserve

- **Store-level errors:** thrown from store actions, caught by calling pages, displayed inline. Stores do NOT show toasts — they propagate.
- **Polling errors (tasks.ts):** silently swallowed in polling loops (no toast for stale polls). Only surfaced on user-initiated actions.
- **Auth store (auth.ts):** `init()` silently handles "no session" as non-error. `logout()` is optimistic — errors swallowed.
- **LLM provider errors:** surfaced as toasts with context from the driver response.

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

## Out of Scope

- Error deduplication (future, only if rapid duplicate errors become a problem)
- Error logging service integration (handled by PSR-3 Monolog already)
- Field-level validation error mapping (server returns flat `message`; inline pattern is sufficient for current forms)