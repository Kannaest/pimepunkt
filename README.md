# Pimepunkt

Pimepunkt is a small PHP/MySQL browser game for blind-map checkpoint events.

## Local or server setup

1. Copy `.env.example` to `.env` and fill secrets.
2. Create the MySQL database and user.
3. Run migrations:

```sh
docker compose run --rm pimepunkt-app php bin/migrate.php
```

4. Start the app:

```sh
docker compose up -d --build
```

The container listens on `127.0.0.1:8088`. On kand.ee, Apache should proxy `/pimepunkt` to that port.
Admin and player login use one-time magic links sent by e-mail. With `MAIL_MODE=log`, links are written to `storage/mail/magic-links.log`.
See `docs/email-setup.md` for SMTP setup examples.

## Apache proxy sketch

Run on kandee with sudo rights:

```sh
cd /home/kanna/pimepunkt
bash deploy/install-apache-proxy.sh
```
