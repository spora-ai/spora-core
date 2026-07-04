# Spora Frontend (spora-frontend)

The operator-facing admin UI lives in its own repository: [`spora-ai/spora-frontend`](https://github.com/spora-ai/spora-frontend).
It is a Vue 3 single-page application built with Vite, shipped as a
Composer package (`spora-frontend`, type `spora-frontend`), and served by
the Spora host out of `public/dist/`.

This document covers how the frontend is structured, how it talks to the
backend, and where to plug in new pages and apps.

---

## Tech stack

| Concern | Choice | Notes |
|---|---|---|
| Framework | Vue 3 (`<script setup>`) | Composition API everywhere; SFCs are the unit of reuse. |
| Build tool | Vite | `npm run dev` for HMR, `npm run build` for production. |
| Language | TypeScript (`vue-tsc`) | Strict mode; `npm run build` runs `vue-tsc` before bundling. |
| Styling | Tailwind CSS + radix-vue primitives | Utility-first; radix-vue supplies accessible headless components (dialogs, dropdowns, tooltips). |
| State | Pinia | One store per resource; composables wrap derived state. |
| Icons | `lucide-vue-next` | Tree-shaken; the shared `<Icon>` component resolves names from the lucide palette. |
| HTTP | `fetch` via the shared `api/client.ts` | Same envelope handling as the backend (`{ data }` / `{ error }`). |
| Routing | `vue-router` 4 | Route guards read the auth store. |

---

## Build & deploy

From `spora-frontend/`:

```bash
npm install
npm run dev      # Vite dev server with HMR (proxies /api/v1 to the host)
npm run build    # vue-tsc + vite build → dist/
npm run lint     # ESLint
npm test         # Vitest
```

The operator skeleton pulls the prebuilt `spora-frontend` Composer package,
which lands at `public/dist/`. The host serves `dist/index.html` and
`dist/assets/*` directly — there is no Node runtime in production.

> **Build provenance:** the package's `composer.json` ships a `dist/` directory
> containing the Vite output. Operators do **not** run `npm install` /
> `npm run build` in production; rebuilding the frontend happens in the
> `spora-frontend` repo and is published as a new tag of the
> `spora-frontend` Composer package.

---

## Talking to the backend

All HTTP traffic goes through one client: `src/api/client.ts`. It handles:

- **Base URL** — `import.meta.env.VITE_API_URL` (empty string in production; the SPA is served from the same origin as the API).
- **CSRF** — `X-CSRF-Token` header injected on POST/PUT/PATCH/DELETE, sourced from the auth store. Falls back to `GET /auth/me` when the token is missing but the user looks logged in.
- **Session expiry** — `setupSessionHandler()` lets the router register a callback that fires on a 401 `UNAUTHENTICATED` response, so the user gets a single toast instead of a flood of failures.
- **Envelope unwrapping** — `body.data ?? body` returns the inner payload transparently; `body.error` is parsed into an `ApiError` (with `code`, `status`, `message`) and thrown.
- **Logging** — `request()` routes 5xx to `log.error`, 4xx to `log.warn`, and 401 to `log.debug` (the toast is the user-facing signal).

Pages never call `fetch` directly — they import `api` from `@/api/client`:

```ts
import { api } from '@/api/client'

const { agents } = await api.get<{ agents: Agent[] }>('/agents')
await api.post('/agents', { name: 'Researcher', prompt: '...' })
```

`ApiError` carries `code` and `status` so UI code can branch on machine-readable failures:

```ts
try {
  await api.post('/agents', payload)
} catch (e) {
  if (e instanceof ApiError && e.code === 'VALIDATION_FAILED') {
    form.setErrors(e.fieldErrors)
  } else {
    toast.error(e.message)
  }
}
```

---

## Composables

Composables in `src/composables/` hold cross-cutting logic that doesn't
belong in a Pinia store but is reused by multiple pages. Two are wired
into nearly every page:

### `useAdminAuth()`

Returns reactive `isAdmin` and `isForbidden` flags derived from the auth
store's `user.is_admin`. Pages use it to gate admin-only UI (tool settings,
plugin management, etc.) without re-reading the auth store directly:

```ts
const { isAdmin, isForbidden } = useAdminAuth()
```

```vue
<template>
  <section v-if="isAdmin">…</section>
  <div v-else-if="isForbidden" class="text-muted-foreground">…</div>
</template>
```

### `useFeatureEnabled(key)`

Resolves a runtime feature flag against the public config (`GET /api/v1/config`).
Returns a `computed` boolean that flips when the user reloads or the config
refreshes. Use it for opt-in features that operators toggle via environment
variables — for example, the Web-UI plugin installer is only shown when
`SPORA_PLUGIN_INSTALL_ENABLED=true`, so its mount point reads:

```ts
const pluginInstallEnabled = useFeatureEnabled('plugin_install')
```

```vue
<RouterLink v-if="pluginInstallEnabled" :to="{ name: 'plugins-install' }">
  Install plugin
</RouterLink>
```

> **Why a composable, not a constant?** Feature flags can change at
> runtime (the config endpoint is polled by some admin flows). Hiding the
> link in a `computed` keeps the UI honest without forcing a full
> refresh.

---

## Pinia stores

One store per resource, defined in `src/stores/`. Convention:

- **State** is plain `ref()`s (`agents`, `loading`, `error`).
- **Actions** are async functions that call `api.*` and translate `ApiError` into a user-readable message.
- **No normalisation** — stores keep the shape the backend returns, so resource objects round-trip cleanly through SSR/serialisation if we ever add it.

Stores expose a `load()` action that pages call from `onMounted` and from refresh buttons. App-scoped stores (e.g. the plugin inventory at `src/apps/plugins/stores/plugins.ts`) live under their app folder, not in the global `stores/`.

---

## The `apps/` directory — extending the frontend

Each "app" is a self-contained bundle of pages, components, types, stores, and
an API client. The pattern keeps feature work isolated — adding a new top-level
page (e.g. **Plugins**) does **not** touch the rest of the app.

```
src/apps/
└── plugins/           ← "Plugins" page, its store, and API
    ├── api/           ← typed wrappers around /api/v1/plugins/*
    ├── components/    ← PluginCard, MigrationStatusBadge, PluginDetailDialog
    ├── pages/         ← PluginsPage.vue (mounted by the router)
    ├── stores/        ← Pinia store for plugin inventory state
    └── types/         ← PluginResource, MigrationInfo, etc.
```

### Use `plugins/` as the template for new apps

When you add a new top-level page:

1. Create a folder under `src/apps/<your-app>/`.
2. Mirror the layout: `api/`, `components/`, `pages/`, `stores/`, `types/`.
3. Define resource shapes in `types/` and mirror them on the backend (`PluginsService` ↔ `PluginResource`).
4. Register the page in `src/router/index.ts` under a clear route name and gate it behind a composable if it requires an admin or a feature flag.
5. Add tests alongside the source (`*.test.ts` co-located with the module they cover).

The existing `plugins/` app is the canonical reference implementation — read it before adding a new one.

---

## Routing

`src/router/index.ts` declares every page. Routes use named navigation
guards for auth and admin gating:

```ts
{
  path: '/admin/plugins',
  name: 'admin-plugins',
  component: () => import('@/apps/plugins/pages/PluginsPage.vue'),
  meta: { requiresAdmin: true },
}
```

The global guard reads `meta.requiresAdmin` / `meta.requiresAuth` and
short-circuits to `/login` or `/403` accordingly.

---

## Where to look next

- **`src/api/client.ts`** — every HTTP call in the app routes through this module.
- **`src/composables/useAdminAuth.ts`** — the canonical admin-gate pattern.
- **`src/composables/useFeatureEnabled.ts`** — runtime feature flags from `/api/v1/config`.
- **`src/apps/plugins/`** — the template for adding a new top-level app.
- **`src/stores/auth.ts`** — session, login, CSRF token.