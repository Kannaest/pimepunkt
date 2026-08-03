# Pimepunkt — Nägemata Eesti import

## Eesmärk

Importida Pimepunkti kõik Nutilogist kättesaadavad varasemate Nägemata Eesti
sündmuste kontrollpunktid.

## Valmis sisend

- GPX-kaust: `outputs/nagemata-eesti-gpx/`
- ZIP: `outputs/nagemata-eesti-gpx.zip`
- Faile: 64
- Waypoint'e: 5 967
- Aastad: 2022–2026
- Iga sündmusevariant on eraldi GPX-failis.

## GPX waypoint'i väljad

Standardsed GPX 1.1 väljad:

- `wpt/@lat`, `wpt/@lon`
- `name`: `KP <number>: <question>`
- `cmt`: küsimus
- `desc`: pikem kirjeldus või küsimus
- `type`: `Nägemata Eesti / RA <difficulty>`
- `link`: Nutilogi sündmuse tulemuste leht

Pimepunkti laiendused, namespace `https://pimepunkt.ee/ns/gpx/1`:

- `pimepunkt:eventId`
- `pimepunkt:eventName`
- `pimepunkt:sourcePointId`
- `pimepunkt:number`
- `pimepunkt:difficulty`
- `pimepunkt:question`
- `pimepunkt:longDescription`
- `pimepunkt:option id="..."`

## Teadlikult välistatud

- õiged vastused;
- osalejate andmed;
- osalejate vastuste logid;
- GPS-jäljed;
- administraatorite andmed.

## Erand

`SooromooASF2022` ei ole ekspordis. Nutilogi arhiivis on selle sündmuse KP
kirjed olemas, kuid aktiivsete kontrollpunktide koordinaadid puuduvad. Sama
sündmuse kruusaversioon `Sooromoo2022.gpx` on olemas.

## Pimepunkti soovituslik import

1. Parsida iga fail eraldi sündmusena.
2. Kasutada sündmuse loomulikuks võtmekandidaadiks `eventId`.
3. Kasutada punkti loomulikuks võtmekandidaadiks paari
   `(eventId, sourcePointId)`.
4. Teha import idempotentse upsert'ina, et sama komplekti korduv import ei
   tekitaks duplikaate.
5. Hoida Nutilogi lähte-ID-d alles, et hilisem värskendamine oleks võimalik.
6. Käivitada esmalt dry-run ning kontrollida sündmuste, punktide, puuduvate
   koordinaatide ja konfliktide arvu.

## Ekspordi taastootmine

Ekspordiskript: `scripts/export-gpx.mjs`

Skript loeb avaliku Nutilogi sündmuste registri, A1/A2 arhiivid ja lõppenud
aktiivsed sündmused ning loob GPX-failid uuesti.
