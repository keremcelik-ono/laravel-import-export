# Changelog

All notable changes to `umutcangungormus/laravel-import-export` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Repeating group fields + header-misaligned exports (2026-08-07)

#### Added

- **Repeating "group" fields.** A field declared as `'type' => 'group'` with a
  `fields` map and `repeat.max` expands into `<group>.<slot>.<leaf>` mapping
  targets, one set per slot, so a file carrying several records per row (jobs,
  schools, languages) can be mapped column by column. Group leaves deliberately
  carry no `validation` rules, so they never reach `updateOrCreate()`; they travel
  in the mapped row to the processor, where nested relations belong.
  `repeat.suffix_start` declares which number the export attaches to the FIRST
  repeat (`1` for "FİRMA ADI 1", `0` when the first repeat is unsuffixed and the
  second is "…1"), keeping slot alias generation unambiguous.
- `HasImportExport::getImportGroups()` — group metadata (label, leaves, slot
  count) so a mapping UI can render collapsible sections instead of a flat list
  of dotted keys.
- **`RowNormalizerContract`** — an optional per-session hook, applied by
  `ProcessImportChunkJob` *before* column mappings, for exports whose data rows
  drift out of step with their header row (repeating sections written with more
  slots than the header declares, by an amount that varies per row — a shape no
  `source_column => target_field` map can describe). Selected via the
  `row_normalizer` session option and absent by default.
- `FileReaderService::readChunks()` / `readRange()` accept `withRawRow` (default
  `false`), which additionally exposes each row's untouched positional cells
  under `FileReaderService::RAW_ROW_KEY`. A row normalizer needs those to realign
  a row. Off by default so existing callers keep the exact row shape they had.

#### Fixed

- **`FileReaderService::readHeaders()` no longer loses columns to blank or
  repeated headers.** Rows are keyed by header name downstream, so every blank
  header used to collapse onto a single `''` key and a repeated header overwrote
  the earlier one — silently dropping data. Blanks now become `__col_<1-based
  index>` and later duplicates get a `__2`, `__3`, … suffix.
  *Behaviour change:* `detected_headers` differs for files that contain blank or
  duplicate headers, so a saved mapping template whose `source_column` was `''`
  (or a duplicated name) will no longer match such a file and needs re-saving.
  Files with unique, non-empty headers are unaffected.
- **`ColumnMatcherService::normalize()` is now multibyte-safe.** It lower-cased
  via the byte-based `strtolower()`, which leaves every multi-byte letter
  untouched, so an upper-case Turkish header never matched its own alias
  ("FİRMA ADI" vs "Firma Adı") and fell through to a fuzzy score well below the
  auto-confirm threshold. Diacritics are folded to ASCII before lower-casing,
  which also makes spelling variants agree ("Sehir" / "Şehir").
  *Behaviour change:* such columns now score as exact label/alias matches (0.9+)
  and therefore auto-confirm, where previously they were proposed unconfirmed and
  waited for the user. Two fields whose aliases differ only by diacritics or case
  now collide, and the first declared wins.


### Back-ported from source app (2026-06-18)

Generic import/export improvements synced back from the consuming application,
all framework-agnostic and carrying no host-app coupling:

#### Changed

- `ProcessImportJob` is now a thin **planner** that fans the import out into a `Bus::batch()` of `ProcessImportChunkJob` (one job per `import-export.batch_size` data rows) with `FinalizeImportJob` as the `then()` callback. Each chunk job streams only its own `[start, start + limit)` window via the new `FileReaderService::readRange()`, so jobs stay short (survive worker timeouts), row failures are isolated to a slice, and progress is real instead of one long-running job. The class name is unchanged, so `StartImportAction` and existing dispatch sites need no change. Empty/zero-row files finalize directly without a batch.

#### Added

- `config('import-export.batch_size')` (env `IMPORT_EXPORT_BATCH_SIZE`, default 500) — rows per queued chunk job — plus queue-config guidance documenting that large imports should run on a connection whose `retry_after` exceeds `job_timeout` with `job_tries` of 1, so a still-running import is never silently re-dispatched and rows are not double-processed.
- `FinalizeImportJob` — runs once after the batch completes; invokes the optional `afterComplete(ImportSession $session)` processor hook (guarded by `method_exists`, documented on `ImportProcessorInterface`) for order-independent resolution such as linking self-referential foreign keys (`manager_id`, `parent_id`, category trees) whose target rows may appear later in the file, then marks the session `Completed` / `CompletedWithErrors`.
- `ProcessImportChunkJob` — processes one contiguous slice end-to-end (map, prepare, validate, persist, record failures) with the row-level logic moved out of `ProcessImportJob`.
- `FileReaderService::readRange()` — streams a contiguous slice of data rows and stops reading as soon as the window ends, so a multi-thousand-row file is never fully read per chunk.
- `ImportSession::confirmedMappingLookup()` — builds the source→target mapping de-duplicated by target field, keeping the highest-confidence source per target (ties by iteration order) so near-duplicate fuzzy-matched headers cannot silently claim the same target via last-write-wins.

#### Fixed

- `FileReaderService` CSV reader now sniffs the field delimiter from the header line (handles `;` from Turkish/European Excel locales, plus tab and pipe exports), strips a leading UTF-8 BOM written by Excel on Windows, and forces every cell to valid UTF-8 (re-encoding from Windows-1252 / ISO-8859-9 / ISO-8859-1 when needed) so non-ASCII headers no longer corrupt the JSON-cast `detected_headers`.
- `ImportSession::progress_percentage` is clamped at 100% because concurrent chunk increments can transiently push `processed_rows` past `total_rows` before counts settle.
- `ImportSession::markAsProcessing()` now resets the per-run progress counters (`processed_rows`, `successful_rows`, `failed_rows`) and clears stale failure rows, so a retried/re-dispatched import reprocesses from a clean slate instead of accumulating on top of a previous attempt (which previously made `processed_rows` overshoot `total_rows` and the import never finish).

### Fixed

- `HasImportExport::formatExportValue` and `ModelExportService::formatValue` now emit the localized "Yes" / "No" cell value for boolean export columns instead of the literal lang key. The lang files have been split into per-group files (`status.php`, `session.php`, `mapping.php`, `template.php`, `export.php`, `errors.php`, `fields.php`) under `lang/{en,tr}/` so that `__('import-export::group.key')` resolves through Laravel's standard namespace.key path.
- `FileReaderService` now works on disks whose driver does not support `path()` (S3, GCS, Azure, in-memory). When `path()` throws, the file is spooled to a tempfile via `readStream()` and the tempfile is unlinked deterministically in a `finally` block after the read closure returns.
- `FileReaderService::xmlReaderFromStream` no longer leaks tempfiles via `register_shutdown_function`. Each XLSX read now cleans up its sidecar tempfiles (shared strings, styles, workbook, rels, worksheet) inline through a `finally` block tied to the iteration's lifetime, and copies the worksheet XML via `stream_copy_to_stream` instead of slurping it into RAM. A new XLSX integration test covers headers + data rows and asserts no `xlsx_*` tempfiles linger in `sys_get_temp_dir()` after reading.
- `ImportTemplateController::store` now forwards `TenantResolverContract::currentTenantId()` to `MappingTemplateService::create()` instead of hardcoding `null`, so templates created via the HTTP layer carry the host's tenant id — matching the headless API and `ImportSessionController`.

### Changed

- `FailureHandlerContract` now declares `summary(ImportSession $session): array` as part of the interface. The leaky `method_exists` probe in `ImportExportService::getFailuresSummary` is gone; any custom failure handler bound to the contract must now implement `summary()` (the bundled `FailureHandlerService` already did).

### Removed

- Removed `prepareForImport()` and `afterImport()` from the `Importable` contract and `HasImportExport` trait. Row-level lifecycle hooks live exclusively on `ImportProcessorInterface` (the documented public extension point) — the trait methods were dead code that `ProcessImportJob` never invoked, and shipping two overlapping integration points was a confusing extension surface.

- Dropped `maatwebsite/excel` from `require`: the package implements its own streaming CSV (`fgetcsv`) and XLSX (`XMLReader` + `ZipArchive`) readers, so consumers no longer pull `phpoffice/phpspreadsheet` transitively.
- Dropped `spatie/laravel-data` from `require`: every shipped DTO is a bare `readonly` constructor-promoted class, none extend `Spatie\LaravelData\Data`.

### Added

- Initial extraction of the framework-grade Import/Export subsystem as a standalone Laravel package.
- Contracts: `Importable`, `Exportable`, `ColumnMatcherContract`, `FailureHandlerContract`, `ImportProcessorInterface`.
- Tenancy abstraction: `TenantResolverContract` + `NullTenantResolver` (default binding).
- Eloquent models with config-driven table names: `ImportSession`, `ImportColumnMapping`, `ImportMappingTemplate`, `ImportFailure`.
- Enums: `ImportStatus`, `MatchMethod`.
- Services: `ImportExportService`, `ColumnMatcherService`, `FileReaderService` (streaming CSV/XLSX reader with full Excel date-system handling), `ModelExportService`, `FailureHandlerService`, `MappingTemplateService`, `ImportMappingTemplateService`.
- Pipelines: `DetectFileHeaders`, `AutoMatchColumns`, `ValidateRequiredMappings`, `ApplyDefaultTemplate`.
- Actions: `InitializeImportAction`, `StartImportAction`, `UpdateMappingAction`, `CreateTemplateAction`, `SaveTemplateAction`, `ApplyTemplateAction`.
- Queued worker: `ProcessImportJob` (Illuminate queue contracts only, Horizon-compatible).
- DTOs (readonly): `InitializeImportData`, `UpdateMappingData`, `ApplyTemplateData`, `CreateTemplateData`, `SaveTemplateData`, `UpdateTemplateData`.
- `HasImportExport` trait for config-driven Importable/Exportable wiring.
- Publishable resources: `config/import-export.php`, four migrations, English + Turkish lang files.
- Optional HTTP layer behind `routes.enabled` flag: three controllers, four resources, six form requests, a route file.
- Pest test suite (Orchestra Testbench) covering unit + feature flows: full pipeline happy path, failure persistence, tenancy injection, route enable/disable, gate-name discovery.
- GitHub Actions CI: 2×2 matrix on PHP 8.3 / 8.4 × Laravel 11 / 12.
- OSS hygiene: README, LICENSE (MIT), CHANGELOG, CONTRIBUTING, issue + pull-request templates.
