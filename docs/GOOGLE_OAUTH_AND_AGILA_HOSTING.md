# Google OAuth + Agila / Hestia hosting — after deploy checklist

This is the **operator runbook** for [`HYBRID_AUTH_ADMIN_FIRST_PRD.md`](plans/HYBRID_AUTH_ADMIN_FIRST_PRD.md): wire **Google Sign-In** and **SMTP** on your Agila / Hestia server so hybrid auth works end-to-end.

## 1. Canonical site URL (`APP_URL`)

OAuth redirect URIs **must** match what you configure in Google Cloud and in `.env`.

- Set **`APP_URL`** to your **public HTTPS URL** with **no trailing slash**, e.g. `https://flexiqueue.example.com`.
- After changing it: `php artisan config:clear` (or your deploy script’s `config:cache` step).

If `APP_URL` is wrong, Google returns `redirect_uri_mismatch` or Socialite will build the wrong callback URL.

**Optional:** set **`GOOGLE_REDIRECT_URI`** explicitly (must equal the URI registered in Google Console):

```env
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

## 2. Google Cloud Console — OAuth client

1. Open [Google Cloud Console](https://console.cloud.google.com/) → select or create a project.
2. **APIs & Services** → **OAuth consent screen**  
   - User type: **External** (or Internal if Workspace-only).  
   - Add app name, support email, authorized domains (your production domain).  
   - Scopes: default **openid**, **email**, **profile** (Socialite’s Google driver uses these).
3. **APIs & Services** → **Credentials** → **Create credentials** → **OAuth client ID**  
   - Application type: **Web application**.
4. **Authorized JavaScript origins**  
   - `https://your-domain.com` (same origin as `APP_URL`, no path).
5. **Authorized redirect URIs**  
   - `https://your-domain.com/auth/google/callback`  
   - Add **staging** URLs too if you use them (each must match exactly).
6. Copy **Client ID** and **Client secret** into `.env`:

```env
GOOGLE_CLIENT_ID=....apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
```

7. Leave **`GOOGLE_CLIENT_ID` empty** on environments where Google login should be hidden (local dev without OAuth, internal QA).

## 3. Agila / Hestia — mail (password reset)

Per PRD **§0.2**: transactional mail uses **your domain’s SMTP** (Hestia mail stack), not SendGrid/Mailgun/Gmail API.

Typical `.env` (adjust host/port to your panel; **465 SSL** or **587 STARTTLS**):

```env
MAIL_MAILER=smtp
MAIL_HOST=mail.your-domain.com
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_USERNAME="full-mailbox@your-domain.com"
MAIL_PASSWORD="mailbox-password"
MAIL_FROM_ADDRESS="noreply@your-domain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Publish **SPF**, **DKIM**, and **DMARC** for the sending domain (see [`DEPLOYMENT.md`](DEPLOYMENT.md) § transactional mail).

**Forgot password** sends to each user’s **`recovery_gmail`** (Gmail on file), not necessarily `users.email`.

## 4. Deploy commands (central)

After uploading code and `.env`:

```bash
php artisan migrate --force
php artisan permission:cache-reset
php artisan config:cache
php artisan route:cache
```

If something breaks with cached config, `php artisan config:clear` then fix `.env` and rebuild cache.

## 5. Smoke tests (production)

| Step | What to verify |
|------|----------------|
| Login | Username + password works. |
| Google | “Sign in with Google” appears only if `GOOGLE_CLIENT_ID` is set; completes login for a user whose Google email matches **`recovery_gmail`** or an existing linked credential. |
| Profile | **Link Google** / **Unlink Google** on `/profile` when OAuth is enabled. |
| Forgot password | Request reset by **username**; email arrives at **recovery Gmail** via your SMTP. |
| Pending staff | User with `pending_assignment` can still open **Profile**, **Link Google**, and complete the OAuth round-trip. |

## 6. Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| `redirect_uri_mismatch` | Redirect URI in Google Console ≠ `${APP_URL}/auth/google/callback` or `APP_URL` wrong. |
| Google button missing | `GOOGLE_CLIENT_ID` empty or config cache stale. |
| “No FlexiQueue account matches…” | Google account email ≠ **`recovery_gmail`** and no prior `user_credentials` row for that Google subject. |
| Reset email never arrives | SMTP / firewall; SPF/DKIM; typo in `recovery_gmail`. |
| 419 on login after Google | Session domain / `SESSION_DOMAIN` / HTTPS mismatch — align cookie settings with your domain. |

## 7. References

- Product spec: [`docs/plans/HYBRID_AUTH_ADMIN_FIRST_PRD.md`](plans/HYBRID_AUTH_ADMIN_FIRST_PRD.md)
- Deploy overview: [`docs/DEPLOYMENT.md`](DEPLOYMENT.md)
- Example env keys: [`.env.example`](../.env.example)
