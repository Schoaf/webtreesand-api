# Changelog

## 0.3.0 – 2026-09-17
- Module description is translatable (German source, English for all other languages).
- Update notice in the webtrees control panel (`latest-version.txt`).
- Friendlier labels for vendor tags such as `_INET`.

## 0.2.1 – 2026-09-17
- Domain errors are returned as HTTP 200 with `{"ok":false,"error":…,"status":…}`: many web servers
  (e.g. Synology Web Station) replace the body of 4xx/5xx answers with their own error page.

## 0.2.0 – 2026-09-17
- Write access: `Fact`, `DeleteFact`, `AddIndividual`, `Media`, plus `Tags`.
- People list sorted by primary name (married names no longer change the sort position).

## 0.1.0 – 2026-09-17
- Read access: `Info`, `Individuals`, `Individual`, `Family`, `Pedigree`, `Descendants`.
