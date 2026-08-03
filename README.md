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

The container listens on `127.0.0.1:8088`. On your server, configure Apache or another reverse proxy to proxy `/pimepunkt` to that port.

Admin and player login use one-time magic links sent by e-mail. With `MAIL_MODE=log`, links are written to `storage/mail/magic-links.log`.
See `docs/email-setup.md` for SMTP setup examples.

## Checkpoint difficulty

When the game visit-point default is 3, checkpoint difficulty uses these shapes and default scores:

| Difficulty | Shape | Default score |
|---|---|---:|
| 1, easy | Circle | 3 |
| 2, harder | Triangle | 5 |
| 3, difficult | Square | 7 |
| 4, especially difficult | Pentagon | 10 |
| 5, very difficult | Hexagon | 13 |
| 6, extreme | Heptagon | 16 |

A checkpoint-specific visit score overrides the difficulty default. GPX imports can provide the level as `<extensions><difficulty>1</difficulty></extensions>`.

## Apache proxy sketch

Run on the server with sudo rights:

```sh
cd /home/kanna/pimepunkt
bash deploy/install-apache-proxy.sh
```
