# Nagemata Eesti GPX Import

This directory contains import handoff material copied from server `192.168.31.110`:

`/home/pi/Documents/Codex/2026-08-02-https-nutilogi-ee-ne2026tarvajahtkruus-mina-osalesin/outputs/`

Files:

- `PIMEPUNKT_IMPORT_HANDOFF.md` - import notes, data shape, exclusions, and recommended import flow.
- `nagemata-eesti-gpx.zip` - sanitized GPX import package with 64 files and 5,967 waypoints from years 2022-2026.
- `nagemata-eesti-oiged-vastused.md` - correct-answer mappings collected from completed public events. Do not publish this file to players.

SHA256:

- `PIMEPUNKT_IMPORT_HANDOFF.md`: `894029B3F51CCCCDCD1EF5D80AB43A1CE3DAE91A0E0D1D11FA536D472C32A273`
- `nagemata-eesti-gpx.zip`: `04CFA6945CB1D596D968306BA53FA9B3B1072AA5D1B4F4FFAD3D5FA78E011F41`

The GPX package excludes correct answers, participant data, answer logs, GPS tracks, and admin data. Correct answers are supplied separately in the restricted answer mapping file.

Before committing the ZIP to GitHub, public contact details found in GPX descriptions were replaced with placeholders.

After extracting the ZIP, first run a dry check and then apply the import:

```bash
php bin/import-nagemata-eesti.php /path/to/gpx /path/to/nagemata-eesti-oiged-vastused.md
php bin/import-nagemata-eesti.php /path/to/gpx /path/to/nagemata-eesti-oiged-vastused.md --apply
```

The importer creates games in `waiting_start` state with automatic team approval enabled. It will not replace a game that already has submissions unless `--force` is explicitly supplied.
