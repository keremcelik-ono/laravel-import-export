<?php

use Illuminate\Support\Facades\Storage;
use Umutcangungormus\LaravelImportExport\Services\FileReaderService;

/**
 * Rows are keyed by header name downstream, so blank and repeated headers used
 * to destroy columns: every blank collapsed onto a single '' key and a repeated
 * header overwrote the earlier one. Real provider exports contain both. The
 * untouched positional row is also preserved, because a sheet whose data columns
 * drift out of step with its header row can only be repaired positionally.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->reader = new FileReaderService;
});

it('gives blank headers a positional name instead of collapsing them', function () {
    Storage::disk('local')->put('t.csv', "Ad,,Soyad,\nAli,x,Veli,y\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');

    expect($headers)->toBe(['Ad', '__col_2', 'Soyad', '__col_4']);
});

it('suffixes repeated headers so neither column is lost', function () {
    Storage::disk('local')->put('t.csv', "Dil,Seviye,Dil,Seviye\nİngilizce,Orta,Almanca,İleri\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');

    expect($headers)->toBe(['Dil', 'Seviye', 'Dil__2', 'Seviye__2']);
});

it('keeps every value reachable once headers are unique', function () {
    Storage::disk('local')->put('t.csv', "Dil,Seviye,Dil,Seviye\nİngilizce,Orta,Almanca,İleri\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readChunks('t.csv', 'local', $headers, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    });

    expect($rows[0]['data']['Dil'])->toBe('İngilizce');
    expect($rows[0]['data']['Seviye'])->toBe('Orta');
    expect($rows[0]['data']['Dil__2'])->toBe('Almanca');
    expect($rows[0]['data']['Seviye__2'])->toBe('İleri');
});

it('exposes the untouched positional row under the reserved key', function () {
    Storage::disk('local')->put('t.csv', "Ad,Soyad\nAli,Veli\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readChunks('t.csv', 'local', $headers, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    }, withRawRow: true);

    expect($rows[0]['data'][FileReaderService::RAW_ROW_KEY])->toBe(['Ali', 'Veli']);
});

it('reaches columns the header row does not describe', function () {
    // The header row is narrower than the data rows — exactly the shape that
    // makes a header-keyed row insufficient on its own.
    Storage::disk('local')->put('t.csv', "Ad,Soyad\nAli,Veli,fazladan,bir,daha\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readChunks('t.csv', 'local', $headers, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    }, withRawRow: true);

    expect($headers)->toHaveCount(2);
    expect($rows[0]['data'][FileReaderService::RAW_ROW_KEY])->toBe(['Ali', 'Veli', 'fazladan', 'bir', 'daha']);
});

it('renames a real column that would shadow the reserved raw row key', function () {
    Storage::disk('local')->put('t.csv', "Ad,__row\nAli,x\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readChunks('t.csv', 'local', $headers, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    }, withRawRow: true);

    expect($headers)->toBe(['Ad', '__col_2']);
    expect($rows[0]['data'][FileReaderService::RAW_ROW_KEY])->toBe(['Ali', 'x']);
});

it('exposes the positional row through readRange too', function () {
    Storage::disk('local')->put('t.csv', "Ad,Soyad\nAli,Veli\nAyse,Yilmaz\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readRange('t.csv', 'local', $headers, 1, 2, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    }, withRawRow: true);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['data'][FileReaderService::RAW_ROW_KEY])->toBe(['Ayse', 'Yilmaz']);
});

it('omits the raw row unless it is asked for, so existing callers see the same shape', function () {
    Storage::disk('local')->put('t.csv', "Ad,Soyad\nAli,Veli\n");

    $headers = $this->reader->readHeaders('t.csv', 'local');
    $rows = [];
    $this->reader->readChunks('t.csv', 'local', $headers, 1, 10, function (array $chunk) use (&$rows) {
        $rows = array_merge($rows, $chunk);
    });

    expect(array_keys($rows[0]['data']))->toBe(['Ad', 'Soyad']);
});
