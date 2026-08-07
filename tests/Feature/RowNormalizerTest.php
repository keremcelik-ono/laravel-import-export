<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Umutcangungormus\LaravelImportExport\Actions\InitializeImportAction;
use Umutcangungormus\LaravelImportExport\Actions\StartImportAction;
use Umutcangungormus\LaravelImportExport\Contracts\RowNormalizerContract;
use Umutcangungormus\LaravelImportExport\Data\InitializeImportData;
use Umutcangungormus\LaravelImportExport\Enums\ImportStatus;
use Umutcangungormus\LaravelImportExport\Jobs\ProcessImportJob;
use Umutcangungormus\LaravelImportExport\Models\ImportFailure;
use Umutcangungormus\LaravelImportExport\Models\ImportSession;
use Umutcangungormus\LaravelImportExport\Services\FileReaderService;
use Umutcangungormus\LaravelImportExport\Tests\Fixtures\FakeImportModel;
use Umutcangungormus\LaravelImportExport\Tests\Fixtures\FakeImportProcessor;

/**
 * The row normalizer hook: a host-supplied repair pass that runs BEFORE column
 * mappings are applied, for exports whose data rows drift out of step with the
 * header row. The fixture below mimics that shape — the sku/name values sit one
 * column to the right of where the header says they should — which no
 * `source_column => target_field` map can express on its own.
 */
class ShiftingRowNormalizer implements RowNormalizerContract
{
    public static int $calls = 0;

    public static array $seenRawRows = [];

    public function normalize(array $row, array $headers, ImportSession $session): array
    {
        self::$calls++;
        self::$seenRawRows[] = $row[FileReaderService::RAW_ROW_KEY] ?? null;

        $raw = $row[FileReaderService::RAW_ROW_KEY] ?? [];
        $shift = (int) $session->getOption('test_shift', 1);

        // Re-key the row against the headers, skipping the leading filler cells.
        $fixed = [];
        foreach ($headers as $i => $header) {
            $fixed[$header] = $raw[$i + $shift] ?? null;
        }
        $fixed[FileReaderService::RAW_ROW_KEY] = $raw;

        return $fixed;
    }
}

beforeEach(function () {
    Storage::fake('local');

    // Header declares 2 columns; every data row carries a leading filler cell,
    // so positions no longer line up with labels.
    Storage::disk('local')->put('imports/shifted.csv', implode("\n", [
        'sku,name',
        'FILLER,SKU-1,Birinci',
        'FILLER,SKU-2,İkinci',
    ])."\n");

    $this->app['db']->connection()->getSchemaBuilder()->create('fake_import_items', function ($t) {
        $t->id();
        $t->string('sku');
        $t->string('name');
        $t->timestamps();
    });

    if (! Schema::hasTable('job_batches')) {
        Schema::create('job_batches', function ($t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });
    }

    config()->set('import-export.models.'.FakeImportModel::class, [
        'processor' => FakeImportProcessor::class,
        'unique_by' => ['sku'],
        'fields' => [
            'sku' => ['required' => true, 'type' => 'string', 'validation' => ['required', 'string', 'max:64']],
            'name' => ['required' => true, 'type' => 'string', 'validation' => ['required', 'string', 'max:255']],
        ],
    ]);

    FakeImportProcessor::reset();
    ShiftingRowNormalizer::$calls = 0;
    ShiftingRowNormalizer::$seenRawRows = [];
});

function initShiftedSession(array $extraOptions = []): ImportSession
{
    $session = app(InitializeImportAction::class)->execute(new InitializeImportData(
        model_class: FakeImportModel::class,
        file_path: 'imports/shifted.csv',
        file_name: 'shifted.csv',
        file_disk: 'local',
        tenant_id: null,
        header_row: 1,
        chunk_size: 100,
    ), userId: null);

    if ($extraOptions) {
        $session->update(['options' => array_merge($session->options ?? [], $extraOptions)]);
        $session->refresh();
    }

    return $session;
}

it('imports a header-misaligned file correctly once a normalizer is configured', function () {
    $session = initShiftedSession(['row_normalizer' => ShiftingRowNormalizer::class]);

    app(StartImportAction::class)->execute($session, dispatch: false);
    app(ProcessImportJob::class, ['sessionId' => $session->id])->handle();

    $session->refresh();

    expect($session->status)->toBe(ImportStatus::Completed);
    expect($session->successful_rows)->toBe(2);
    expect(ShiftingRowNormalizer::$calls)->toBe(2);

    // The shifted values landed in the right columns.
    expect(FakeImportModel::pluck('name', 'sku')->all())->toBe([
        'SKU-1' => 'Birinci',
        'SKU-2' => 'İkinci',
    ]);
});

it('hands the normalizer the full positional row, not just the header-keyed one', function () {
    $session = initShiftedSession(['row_normalizer' => ShiftingRowNormalizer::class]);

    app(StartImportAction::class)->execute($session, dispatch: false);
    app(ProcessImportJob::class, ['sessionId' => $session->id])->handle();

    // Three cells per row, while the header only describes two — the third is
    // reachable only through the reserved raw row.
    expect(ShiftingRowNormalizer::$seenRawRows[0])->toBe(['FILLER', 'SKU-1', 'Birinci']);
});

it('fails those same rows without a normalizer, proving the hook is what fixes them', function () {
    $session = initShiftedSession();

    app(StartImportAction::class)->execute($session, dispatch: false);
    app(ProcessImportJob::class, ['sessionId' => $session->id])->handle();

    $session->refresh();

    // Header-keyed reading puts 'FILLER' in sku and 'SKU-n' in name: no
    // validation rule rejects that, so the rows import — with wrong values.
    expect(FakeImportModel::pluck('name', 'sku')->all())->toBe(['FILLER' => 'SKU-2']);
    expect(ShiftingRowNormalizer::$calls)->toBe(0);
});

it('ignores a row_normalizer option that is not a usable normalizer', function () {
    $session = initShiftedSession(['row_normalizer' => 'App\\Does\\Not\\Exist']);

    app(StartImportAction::class)->execute($session, dispatch: false);
    app(ProcessImportJob::class, ['sessionId' => $session->id])->handle();

    $session->refresh();

    // Falls back to plain header-keyed reading rather than blowing up the batch.
    expect($session->status)->toBe(ImportStatus::Completed);
    expect(ShiftingRowNormalizer::$calls)->toBe(0);
});

it('keeps the reserved raw row out of recorded failures', function () {
    $session = initShiftedSession(['row_normalizer' => ShiftingRowNormalizer::class]);
    app(StartImportAction::class)->execute($session, dispatch: false);

    FakeImportProcessor::$throwOnRow2 = true;

    app(ProcessImportJob::class, ['sessionId' => $session->id])->handle();

    $failure = ImportFailure::where('import_session_id', $session->id)->first();

    expect($failure)->not->toBeNull();
    // The failures CSV should show the row as the user sees it — not an extra
    // positional copy of every cell.
    expect($failure->row_data)->not->toHaveKey(FileReaderService::RAW_ROW_KEY);
    expect($failure->row_data)->toHaveKeys(['sku', 'name']);
});
