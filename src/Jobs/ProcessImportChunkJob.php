<?php

namespace Umutcangungormus\LaravelImportExport\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Umutcangungormus\LaravelImportExport\Contracts\FailureHandlerContract;
use Umutcangungormus\LaravelImportExport\Contracts\RowNormalizerContract;
use Umutcangungormus\LaravelImportExport\Enums\ImportStatus;
use Umutcangungormus\LaravelImportExport\Exceptions\ProcessorNotRegistered;
use Umutcangungormus\LaravelImportExport\Models\ImportSession;
use Umutcangungormus\LaravelImportExport\Services\FileReaderService;

/**
 * Processes one contiguous slice ([startRow, startRow + limit)) of an import
 * file. The planner (ProcessImportJob) fans these out as a Bus batch — one
 * per config('import-export.batch_size') data rows — so each job is short,
 * failures are isolated to a slice, and progress is real. Any
 * order-independent post-pass is deferred to FinalizeImportJob (runs once
 * after the whole batch).
 *
 * No Horizon dependency: uses only Illuminate's queue contracts so any queue
 * driver (database, redis, sync, sqs, …) works.
 */
class ProcessImportChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $startRow,
        public readonly int $limit,
    ) {
        $this->timeout = (int) config('import-export.job_timeout', 600);
        $this->tries = (int) config('import-export.job_tries', 3);

        $connection = config('import-export.queue.connection');
        $queue = config('import-export.queue.queue', 'default');

        if ($connection) {
            $this->onConnection($connection);
        }
        $this->onQueue($queue);
    }

    public function handle(FileReaderService $fileReader, FailureHandlerContract $failureHandler): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $session = ImportSession::find($this->sessionId);

        if (! $session || $session->status === ImportStatus::Cancelled) {
            return;
        }

        $modelClass = $session->importable_type;
        $importableFields = method_exists($modelClass, 'getImportableFields')
            ? $modelClass::getImportableFields()
            : [];

        $uniqueBy = method_exists($modelClass, 'getImportUniqueBy')
            ? ($modelClass::getImportUniqueBy() ?? [])
            : [];

        // Resolve processor through config registry (single source of truth).
        $processorClass = config('import-export.models.'.$modelClass.'.processor');
        if (! $processorClass || ! class_exists($processorClass)) {
            throw ProcessorNotRegistered::forModel($modelClass);
        }
        $processor = app($processorClass);

        // De-duplicated by target field so near-duplicate headers don't let
        // the wrong source column silently claim a target.
        $mappingLookup = $session->confirmedMappingLookup();

        $chunkSize = (int) $session->getOption('chunk_size', config('import-export.chunk_size', 1000));
        $headerRow = (int) $session->getOption('header_row', 1);
        $headers = $session->detected_headers ?? [];

        // Optional per-session row repair for exports whose data rows drift out
        // of step with their header row. Resolved once per job, not per row.
        $normalizer = $this->resolveRowNormalizer($session);

        $fileReader->readRange(
            $session->file_path,
            $session->file_disk,
            $headers,
            $headerRow,
            $this->startRow,
            $this->limit,
            $chunkSize,
            function (array $chunk) use ($session, $modelClass, $importableFields, $mappingLookup, $uniqueBy, $failureHandler, $processor, $normalizer, $headers) {
                foreach ($chunk as $item) {
                    $this->processRow(
                        $session,
                        $modelClass,
                        $importableFields,
                        $mappingLookup,
                        $uniqueBy,
                        $item['row_number'],
                        $item['data'],
                        $failureHandler,
                        $processor,
                        $normalizer,
                        $headers,
                    );
                }
            },
        );
    }

    /**
     * Resolve the session's row normalizer, or null when none is configured.
     *
     * @param  ImportSession  $session  Reads the `row_normalizer` option
     */
    private function resolveRowNormalizer(ImportSession $session): ?RowNormalizerContract
    {
        $class = $session->getOption('row_normalizer');

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $normalizer = app($class);

        return $normalizer instanceof RowNormalizerContract ? $normalizer : null;
    }

    private function processRow(
        ImportSession $session,
        string $modelClass,
        array $importableFields,
        array $mappingLookup,
        array $uniqueBy,
        int $rowNumber,
        array $rawData,
        FailureHandlerContract $failureHandler,
        object $processor,
        ?RowNormalizerContract $normalizer = null,
        array $headers = [],
    ): void {
        // Recorded on failure without the bulky reserved raw row — the CSV should
        // show the row as the user sees it, not an extra positional copy.
        $reportedData = $rawData;
        unset($reportedData[FileReaderService::RAW_ROW_KEY]);

        try {
            // 0. Optional row repair for header-misaligned exports
            if ($normalizer) {
                $rawData = $normalizer->normalize($rawData, $headers, $session);
                $reportedData = $rawData;
                unset($reportedData[FileReaderService::RAW_ROW_KEY]);
            }

            // 1. Transform raw row using mappings
            $mapped = $this->mapRow($rawData, $mappingLookup, $importableFields);

            // 2. Processor pre-processing hook
            $mapped = $processor->prepare($session, $mapped);

            // 3. Validate
            $validationRules = $this->buildValidationRules($importableFields);
            $validator = Validator::make($mapped, $validationRules);

            if ($validator->fails()) {
                $failureHandler->record($session, $rowNumber, $reportedData, $validator->errors()->all());
                $session->increment('processed_rows');

                return;
            }

            $validated = $validator->validated();

            // 4. Persist inside transaction. afterImport receives the merged
            // shape (processor-prepared + validated) so processor-added fields
            // outside the validation rule set — like `_tenant_id` or resolved
            // foreign keys — survive into the after-hook.
            DB::transaction(function () use ($modelClass, $mapped, $validated, $uniqueBy, $session, $processor) {
                if (! empty($uniqueBy)) {
                    // Lookup keys must come from $mapped, not $validated:
                    // processor-stamped scoping keys like tenant_id/company_id
                    // and resolved FKs have no validation rule, so validated()
                    // strips them. Without them in $lookupKeys, updateOrCreate
                    // would match across tenants — or insert a row with NULL
                    // scoping key.
                    $lookupKeys = array_intersect_key($mapped, array_flip($uniqueBy));
                    $fillValues = array_diff_key($validated, array_flip($uniqueBy));

                    $model = forward_static_call([$modelClass, 'updateOrCreate'], $lookupKeys, $fillValues);
                } else {
                    $model = forward_static_call([$modelClass, 'create'], $validated);
                }

                $processor->after($model, array_merge($mapped, $validated));

                $session->increment('successful_rows');
            });
        } catch (Throwable $e) {
            $failureHandler->record($session, $rowNumber, $reportedData, [], $e->getMessage());
        }

        $session->increment('processed_rows');
    }

    private function mapRow(array $rawData, array $mappingLookup, array $importableFields): array
    {
        $mapped = [];

        foreach ($mappingLookup as $sourceColumn => $targetField) {
            $rawValue = $rawData[$sourceColumn] ?? null;

            $transform = $importableFields[$targetField]['transform'] ?? null;
            $mapped[$targetField] = $transform && $rawValue !== null ? $transform($rawValue) : $rawValue;
        }

        foreach ($importableFields as $fieldKey => $fieldConfig) {
            if (! array_key_exists($fieldKey, $mapped) && array_key_exists('default', $fieldConfig)) {
                $mapped[$fieldKey] = $fieldConfig['default'];
            }
        }

        return $mapped;
    }

    private function buildValidationRules(array $importableFields): array
    {
        $rules = [];

        foreach ($importableFields as $fieldKey => $fieldConfig) {
            if (! empty($fieldConfig['validation'])) {
                $rules[$fieldKey] = $fieldConfig['validation'];
            }
        }

        return $rules;
    }
}
