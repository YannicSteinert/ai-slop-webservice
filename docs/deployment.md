# Deployment Checklist

A practical checklist for putting the service into production. It is **not**
"just edit config" — there are a few operational steps and dev defaults to flip.

`vendor/` is committed, so `composer install` is optional. The app boots without
a database (the connection is lazy); only DB-backed routes (OAuth) need it.

## 1. Server prerequisites

- [ ] **PHP 8.3+**.
- [ ] **`ext-pdo_sqlsrv`** loaded (plus Microsoft's ODBC driver at the OS level —
      the usual MSSQL-on-PHP requirement). Check: `php -m | grep sqlsrv`.
- [ ] Document root points at **`public/`**; route non-file requests to
      `public/index.php`.
      - Apache: the included `public/.htaccess` handles it.
      - nginx: `location / { try_files $uri /index.php$is_args$args; }`
- [ ] **`storage/logs/`** exists and is writable by the web server user.

## 2. Configuration (`config/config.php`)

- [ ] **`database`** → your MSSQL `host`, `port`, `database`, `username`,
      `password` (`encrypt` on for Azure SQL / enforced TLS).
- [ ] **`auth.saml.settings`** → real `idp` (`entityId`, `singleSignOnService.url`,
      `x509cert`) and `sp` (`entityId`, `assertionConsumerService.url` pointing at
      `https://<you>/login/saml/callback`).
- [ ] **`auth.ldap`** → real `host` / `dn_format` (and `base_dn`, `start_tls` as
      needed).

## 3. Flip the dev defaults ⚠️

These ship as development-friendly values and **must** change for production:

| Key | Ship value | Production value |
|---|---|---|
| `debug` | `true` (leaks exception traces in 500s) | **`false`** |
| `auth.ldap.fake` | `true` — enables login `devuser` / `secret` | **`false`** |
| `session.secure` | `false` | **`true`** (behind HTTPS) |
| `auth.default` | `ldap` | `saml` (your primary provider) |
| `auth.enabled` | `['ldap', 'saml']` | drop providers you don't use |
| `logging.level` | `debug` | `info` or `warning` |

> Leaving `auth.ldap.fake => true` in production means the hard-coded
> `devuser` / `secret` account can log in. Turn it off.

## 4. Database setup

- [ ] Run migrations (creates `oauth_tokens`, `oauth_clients`, `users`):
      ```bash
      php bin/console migrate
      php bin/console migrate:status   # verify all applied
      ```
- [ ] Register each inward OAuth client (prints the secret once — store it):
      ```bash
      php bin/console oauth:client:create "Onboarding App"
      ```
- [ ] Schedule token cleanup (MSSQL also self-purges via a trigger, but a cron is
      good hygiene): `php bin/console oauth:purge`.

## 5. Smoke test after deploy

```bash
curl -i https://<you>/login                 # 200 (LDAP form) or 302 (SAML redirect)
curl -i https://<you>/users/1/show           # 200 JSON
# inward:
curl -X POST https://<you>/oauth/token \
     -d 'grant_type=client_credentials&client_id=<id>&client_secret=<secret>'   # 200 + access_token
curl -H 'Authorization: Bearer <token>' https://<you>/login/whoami              # 200 client identity
```

Check `storage/logs/app.log` — each request logs an `info` line with a
`request_id`; unhandled errors log at `error` with a stack trace.

## Notes

- **Secrets** currently live in `config/config.php` as plaintext. For a hardened
  deploy, read them from environment variables / a secret store instead of
  committing real values. (Ask if you want the env-var config variant.)
- **`UsersWebservice`** returns hard-coded demo data — it is the example module,
  not wired to the `users` table. Replace it with real logic (and inject
  `UsersRepository`) before relying on it.
- **Sessions** keep SAML/LDAP logins alive for the session lifetime
  (`session.lifetime`); there is no separate remember-me layer.
