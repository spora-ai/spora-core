# Spora Bruno Collection

A Bruno API collection for the Spora API.

## Install Bruno CLI

```bash
npm install -g @usebruno/cli
```

## Running Requests

Run the full collection against the dev environment:

```bash
cd bruno && bru run --env dev
```

Run a specific folder only:

```bash
cd bruno && bru run auth/ --env dev
```

## Authentication

1. Run `auth/login.bru` first — Bruno stores the session cookie automatically.
2. After the first request, retrieve the `XSRF-TOKEN` cookie value from the server response and set `xsrfToken` in your environment file.
3. All subsequent authenticated endpoints will use the `X-XSRF-TOKEN` header with the value from the environment variable.
