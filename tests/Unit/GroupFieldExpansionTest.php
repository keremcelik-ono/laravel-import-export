<?php

use Umutcangungormus\LaravelImportExport\Services\ColumnMatcherService;
use Umutcangungormus\LaravelImportExport\Tests\Fixtures\FakeImportModel;

/**
 * Repeating "group" fields: one config entry expands into `<group>.<slot>.<leaf>`
 * leaf fields so a sheet carrying several jobs/schools/languages per row can be
 * mapped column-by-column, without teaching the rest of the pipeline about
 * nesting.
 */
beforeEach(function () {
    config()->set('import-export.models.'.FakeImportModel::class, [
        'unique_by' => ['email'],
        'fields' => [
            'email' => [
                'required' => true,
                'type' => 'string',
                'validation' => ['required', 'email'],
            ],
            // eleman.net numbers the first repeat "1"
            'experience_information' => [
                'type' => 'group',
                'label' => 'İş Deneyimi',
                'repeat' => ['max' => 3],
                'fields' => [
                    'company' => ['type' => 'string', 'label' => 'Firma', 'aliases' => ['Firma Adı']],
                    'title' => ['type' => 'string', 'label' => 'Pozisyon', 'aliases' => ['Görevi']],
                ],
            ],
            // Kariyer.net leaves the first repeat unsuffixed
            'education_information' => [
                'type' => 'group',
                'label' => 'Eğitim',
                'repeat' => ['max' => 2, 'suffix_start' => 0],
                'fields' => [
                    'school_name' => ['type' => 'string', 'label' => 'Okul', 'aliases' => ['UniversiteAdi']],
                ],
            ],
        ],
    ]);
});

it('expands a group into one leaf field per slot', function () {
    $fields = FakeImportModel::getImportableFields();

    expect($fields)->toHaveKeys([
        'experience_information.0.company',
        'experience_information.0.title',
        'experience_information.1.company',
        'experience_information.2.title',
    ]);

    // max = 3 → slots 0..2 only
    expect($fields)->not->toHaveKey('experience_information.3.company');
    // The group key itself is never a mapping target
    expect($fields)->not->toHaveKey('experience_information');
});

it('leaves group slots ruleless so they never reach updateOrCreate', function () {
    $fields = FakeImportModel::getImportableFields();

    // ProcessImportChunkJob only builds validation rules for fields that
    // declare them, and validated() strips everything ruleless — that is how
    // group values bypass the flat upsert and reach the processor instead.
    expect($fields['experience_information.0.company'])->not->toHaveKey('validation');
    // A slot is never mapping-required: a file may carry fewer repeats.
    expect($fields['experience_information.0.company']['required'])->toBeFalse();
});

it('carries group metadata and a readable label on each leaf', function () {
    $leaf = FakeImportModel::getImportableFields()['experience_information.1.company'];

    expect($leaf['group'])->toBe('experience_information');
    expect($leaf['group_index'])->toBe(1);
    expect($leaf['group_field'])->toBe('company');
    expect($leaf['label'])->toBe('İş Deneyimi 2 · Firma');
});

it('suffixes slot aliases from suffix_start so no spelling is claimed twice', function () {
    $fields = FakeImportModel::getImportableFields();

    // suffix_start defaults to 1 → the first slot owns "Firma Adı 1"
    expect($fields['experience_information.0.company']['aliases'])->toContain('Firma Adı 1');
    expect($fields['experience_information.1.company']['aliases'])->toContain('Firma Adı 2');
    // …and the bare spelling belongs to nobody, so it cannot silently win
    expect($fields['experience_information.0.company']['aliases'])->not->toContain('Firma Adı');

    // suffix_start = 0 → the first slot owns the bare spelling, second owns "1"
    expect($fields['education_information.0.school_name']['aliases'])->toContain('UniversiteAdi');
    expect($fields['education_information.1.school_name']['aliases'])->toContain('UniversiteAdi1');
    expect($fields['education_information.0.school_name']['aliases'])->not->toContain('UniversiteAdi1');
});

it('auto-matches suffixed headers onto the right slot', function () {
    $matcher = new ColumnMatcherService;

    $proposals = collect($matcher->match(
        ['EPOSTA', 'Firma Adı 1', 'Görevi 3', 'UniversiteAdi1'],
        FakeImportModel::getImportableFields(),
    ))->keyBy('source_column');

    expect($proposals['Firma Adı 1']['target_field'])->toBe('experience_information.0.company');
    expect($proposals['Görevi 3']['target_field'])->toBe('experience_information.2.title');
    expect($proposals['UniversiteAdi1']['target_field'])->toBe('education_information.1.school_name');

    // An exact alias hit scores 0.9, clearing the 0.8 auto-confirm threshold —
    // so a provider export maps itself without the user touching the UI.
    expect($proposals['Firma Adı 1']['confidence_score'])->toBeGreaterThanOrEqual(0.9);
});

it('reports group metadata for the mapping UI', function () {
    $groups = FakeImportModel::getImportGroups();

    expect($groups)->toHaveKeys(['experience_information', 'education_information']);
    expect($groups['experience_information']['label'])->toBe('İş Deneyimi');
    expect($groups['experience_information']['max'])->toBe(3);
    expect($groups['experience_information']['fields'])->toHaveKeys(['company', 'title']);
});

it('leaves models without group fields untouched', function () {
    config()->set('import-export.models.'.FakeImportModel::class, [
        'fields' => [
            'name' => ['required' => true, 'type' => 'string', 'validation' => ['required']],
        ],
    ]);

    $fields = FakeImportModel::getImportableFields();

    expect($fields)->toHaveKey('name');
    expect($fields['name']['required'])->toBeTrue();
    expect($fields['name']['validation'])->toBe(['required']);
    expect(FakeImportModel::getImportGroups())->toBe([]);
});
