# Pimepunkt Email Setup

Pimepunkt uses email magic links for admin login and team registration. There are no player or admin passwords.

## Modes

Use `.env`:

```env
MAIL_MODE=log
MAIL_FROM=no-reply@kand.ee
```

`MAIL_MODE=log` is for testing. Links are written to:

```text
storage/mail/magic-links.log
```

For real email delivery, use SMTP:

```env
MAIL_MODE=smtp
MAIL_FROM=no-reply@kand.ee
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=no-reply@kand.ee
SMTP_PASSWORD=replace-with-smtp-password
SMTP_SECURE=tls
```

Supported `SMTP_SECURE` values:

- `tls` for STARTTLS, usually port `587`
- `ssl` for implicit TLS, usually port `465`
- `none` for unencrypted local relay only

After changing `.env`, restart the app:

```sh
cd /home/kanna/pimepunkt
docker compose up -d
```

## Gmail / Google Workspace

Use SMTP with an app password:

```env
MAIL_MODE=smtp
MAIL_FROM=your-address@gmail.com
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-address@gmail.com
SMTP_PASSWORD=your-16-character-app-password
SMTP_SECURE=tls
```

Notes:

- The Google account must have 2-Step Verification enabled.
- Use an app password, not the normal Google account password.
- For Google Workspace, `MAIL_FROM` should normally be the same mailbox or an allowed alias.

## Cloudflare

Cloudflare Email Routing is mainly for receiving/forwarding mail, not ordinary SMTP sending.

Cloudflare has a newer Email Sending product for transactional outbound mail, but it is not a generic SMTP account for this app. Pimepunkt's current implementation expects SMTP, so use Gmail, Google Workspace, Mailgun, Brevo, SMTP2GO, Zoho, mailbox.org, or another SMTP provider.

## Tuta

Tuta custom-domain mailboxes are good for human mailbox use, but Tuta does not provide standard IMAP/POP3/SMTP access. That means Pimepunkt cannot send magic-link mail directly through Tuta SMTP.

Use one of these options:

- Keep your domain mailbox at Tuta, but use a separate transactional SMTP provider for `no-reply@kand.ee`.
- Use Gmail/Google Workspace SMTP with an allowed sender/alias.
- Use a dedicated SMTP provider and configure SPF/DKIM/DMARC for better deliverability.

## Recommended Production Setup

Use a dedicated sender such as:

```text
no-reply@kand.ee
```

Configure the SMTP provider's DNS records:

- SPF
- DKIM
- DMARC

Then test:

1. Set `MAIL_MODE=smtp`.
2. Restart the container.
3. Open `/pimepunkt/admin/login`.
4. Enter the admin email.
5. Confirm the link arrives and opens `/pimepunkt/admin`.

If delivery fails, temporarily switch back to:

```env
MAIL_MODE=log
```

This keeps login usable while debugging SMTP.
