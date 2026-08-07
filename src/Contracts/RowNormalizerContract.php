<?php

namespace Umutcangungormus\LaravelImportExport\Contracts;

use Umutcangungormus\LaravelImportExport\Models\ImportSession;

/**
 * Host applications implement this to repair a raw row BEFORE column mappings
 * are applied.
 *
 * Column mapping assumes every data row lines up with the header row. Some real
 * exports break that assumption: repeating sections (jobs, schools, languages)
 * are written with more slots than the header row declares, so from that point
 * on a cell's position no longer matches its label — and the offset can differ
 * from row to row. No `source_column => target_field` map can describe such a
 * sheet.
 *
 * A normalizer receives the header-keyed row plus the untouched positional row
 * (under {@see \Umutcangungormus\LaravelImportExport\Services\FileReaderService::RAW_ROW_KEY})
 * and returns a row that IS aligned to $headers. Everything downstream —
 * mapping, validation, processors, templates — then works unchanged.
 *
 * Wire one up per session via the `row_normalizer` option, holding the class name:
 *
 *   $session->update(['options' => ['row_normalizer' => MyRowNormalizer::class] + $options]);
 */
interface RowNormalizerContract
{
    /**
     * Realign one raw row against the detected headers.
     *
     * @param  array<string, mixed>  $row  Header-keyed row, plus the raw positional row
     * @param  list<string>  $headers  The session's detected headers, in order
     * @param  ImportSession  $session  Carries options/tenant for provider-specific behaviour
     * @return array<string, mixed> A row keyed by the same header names
     */
    public function normalize(array $row, array $headers, ImportSession $session): array;
}
