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

## Maps, timing, and road data

- Admin can generate an area-shaped player map from the Maa- ja Ruumiamet grayscale WMS. The output switches between landscape and portrait, targets 300 DPI at approximately 1:80,000, and caps very large games at 24 megapixels. Checkpoints use a transparent red difficulty outline and an exact red centre dot.
- A selected game's registration page shows a grayscale overview with a padded red area envelope. Individual checkpoint coordinates and markers are never sent to that page.
- GPX export is disabled per game by default and can be enabled in game settings.
- Timed games use each team's own start time. A paused team may resume only within 100 metres of its pause location.
- Temporary road restrictions come from the Tarktee ArcGIS REST service.
- Numeric normal speed limits are synchronized from OpenStreetMap through Overpass. Tarktee numeric variable/increased limits override OSM, and manually configured zones override both.
- Speed penalties require more than 10 seconds above 110% of a known numeric limit. GPS points with poor accuracy or implausible movement are ignored by the speed calculation.

Public Overpass instances are used only during an admin-triggered synchronization. Players never query Overpass directly. Map and road data require the attribution and licensing terms of their respective providers.

Nägemata Eesti maintenance commands:

```sh
php bin/create-nagemata-totals.php
php bin/generate-player-maps.php
php bin/generate-player-maps.php --all-generated
```

The first command creates or refreshes the Total Kruus and Total Asfalt games unless they already contain submissions. The second enables six-hour timing and GPX export for imported Nägemata Eesti games and regenerates their player maps. The final variant regenerates every automatically generated map without replacing manually uploaded images.

The superadmin dashboard can check and synchronize the 20 latest completed public Nägemata Eesti events directly from Nutilogi. The first synchronization links legacy imports by `eventId`; later source changes update only games that have no teams. New games use `running`, automatic team approval, six-hour per-team timing, GPX export, and a generated player map. The player's six-hour clock starts only after pressing the start button.

## Apache proxy sketch

Run on the server with sudo rights:

```sh
cd /home/kanna/pimepunkt
bash deploy/install-apache-proxy.sh
```
