<?php

namespace Umutcangungormus\LaravelImportExport\Support;


/**
 * HasImportExport
 *
 * Add this trait to any Eloquent model to opt into the ImportExport system.
 * Field definitions live in config/import-export.php under the 'models' key —
 * no getImportableFields() / getExportableFields() needed in the model itself.
 *
 * Minimal model setup:
 *
 *   class Product extends Model implements Importable, Exportable
 *   {
 *       use HasImportExport;
 *   }
 *
 * Everything else (fields, aliases, validation, unique_by, export_with…)
 * is defined in `config('import-export.models')` under the model's FQCN.
 *
 * Field labels and aliases are pulled from the namespaced lang file:
 *   `trans('import-export::fields.<key>')`
 */
trait HasImportExport
{
    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve a lang key from `import-export::fields.<key>` and return
     * `['label', 'aliases']`. Falls back gracefully when the key does not exist.
     */
    private static function resolveFieldLang(string $langKey): array
    {
        // e.g. "product.sku" → import-export::fields.product.sku
        $path = 'import-export::fields.'.$langKey;

        // label: active locale
        $cur = trans($path);

        // aliases: merge EN + TR so both column-name variants are recognised
        $en = trans($path, [], 'en');
        $tr = trans($path, [], 'tr');

        // If the translation returns the key itself (missing), fall back to ''
        $label = is_array($cur) ? ($cur['label'] ?? $langKey) : (is_string($cur) ? $cur : $langKey);
        $aliasEn = is_array($en) ? ($en['aliases'] ?? []) : [];
        $aliasTr = is_array($tr) ? ($tr['aliases'] ?? []) : [];

        return [
            'label' => $label,
            'aliases' => array_values(array_unique(array_merge($aliasEn, $aliasTr))),
        ];
    }

    private static function modelConfig(): array
    {
        return config('import-export.models.'.static::class, []);
    }

    // ── Importable ────────────────────────────────────────────────────────
    //
    // Row-level lifecycle hooks (prepare / after) live exclusively on
    // ImportProcessorInterface, the documented public extension point —
    // see README "Extending the Importer". ProcessImportJob always resolves
    // the processor from config('import-export.models.<class>.processor')
    // and never calls back into the model's trait.

    public static function getImportableFields(): array
    {
        $cfg = static::modelConfig();
        $result = [];

        foreach ($cfg['fields'] ?? [] as $field => $def) {
            if (($def['type'] ?? null) === 'group') {
                $result += static::expandGroupField($field, $def);

                continue;
            }

            $lang = isset($def['lang'])
                ? static::resolveFieldLang($def['lang'])
                : ['label' => $field, 'aliases' => []];

            $result[$field] = array_merge(
                [
                    'label' => $lang['label'],
                    'aliases' => $lang['aliases'],
                ],
                $def,
            );

            // Remove internal-only key from the exposed array
            unset($result[$field]['lang']);
        }

        return $result;
    }

    /**
     * Flatten one repeating group definition into `"<group>.<slot>.<leaf>"` leaf
     * fields — one set per slot — so the rest of the pipeline keeps treating
     * every mapping target as a plain string key.
     *
     * Leaves deliberately carry NO `validation` rules: ProcessImportChunkJob
     * only builds rules for fields that declare them, and `validated()` strips
     * everything ruleless, so group values never reach `updateOrCreate()`. They
     * still travel in the mapped row to the processor's prepare()/after(), which
     * is where nested relations belong.
     *
     * @param  string  $group  Group key, e.g. `experience_information`
     * @param  array<string, mixed>  $def  The group definition from config
     * @return array<string, array<string, mixed>>
     */
    protected static function expandGroupField(string $group, array $def): array
    {
        $groupLang = isset($def['lang']) ? static::resolveFieldLang($def['lang']) : null;
        $groupLabel = $groupLang['label'] ?? $def['label'] ?? $group;
        $slots = max(1, (int) ($def['repeat']['max'] ?? 1));
        // Which number the export attaches to the FIRST repeat. eleman.net
        // writes "FİRMA ADI 1" for the first job (1); Kariyer.net leaves the
        // first unsuffixed and writes "IsTecrubesiIsyeriAdi1" for the second (0).
        // Declaring it per group keeps alias generation unambiguous — without it
        // the same spelling would be claimed by two different slots.
        $suffixStart = (int) ($def['repeat']['suffix_start'] ?? 1);

        $fields = [];

        for ($slot = 0; $slot < $slots; $slot++) {
            foreach ($def['fields'] ?? [] as $leaf => $leafDef) {
                $leafLang = isset($leafDef['lang'])
                    ? static::resolveFieldLang($leafDef['lang'])
                    : ['label' => $leafDef['label'] ?? $leaf, 'aliases' => []];

                $aliases = array_values(array_unique(array_merge(
                    $leafLang['aliases'],
                    (array) ($leafDef['aliases'] ?? []),
                )));

                $key = "{$group}.{$slot}.{$leaf}";

                $fields[$key] = array_merge($leafDef, [
                    // "İş Deneyimi 2 · Firma" reads better than the raw dotted key
                    // in the mapping UI, which shows one row per leaf.
                    'label' => $groupLabel.' '.($slot + 1).' · '.$leafLang['label'],
                    // Slot-suffixed variants ("Firma Adı 2") let the matcher hit
                    // an exact alias (0.9) rather than falling back to fuzzy.
                    'aliases' => static::slotAliases($aliases, $slot, $suffixStart),
                    'type' => $leafDef['type'] ?? 'string',
                    // A group slot is never mapping-required: a file may carry
                    // fewer slots than the group declares.
                    'required' => false,
                    'group' => $group,
                    'group_label' => $groupLabel,
                    'group_index' => $slot,
                    'group_field' => $leaf,
                ]);

                unset($fields[$key]['lang'], $fields[$key]['validation']);
            }
        }

        return $fields;
    }

    /**
     * Build the alias list for one slot: the bare aliases (only where this slot
     * is the unsuffixed one) plus the spaced and unspaced suffix spellings.
     *
     * @param  list<string>  $aliases  The leaf's base aliases
     * @param  int  $slot  Zero-based slot index
     * @param  int  $suffixStart  Number the export attaches to the first repeat
     * @return list<string>
     */
    protected static function slotAliases(array $aliases, int $slot, int $suffixStart = 1): array
    {
        $suffix = $slot + $suffixStart;

        // suffix_start = 0 means the first repeat carries no number at all, so
        // slot 0 owns the bare spelling and slot 1 owns "…1".
        $result = $suffix === 0 ? $aliases : [];

        foreach ($aliases as $alias) {
            if ($suffix === 0) {
                continue;
            }

            $result[] = $alias.' '.$suffix;
            $result[] = $alias.$suffix;
        }

        return array_values(array_unique($result));
    }

    /**
     * Group metadata for the HTTP/UI layer: what groups exist, their leaves and
     * how many slots each declares. Lets a mapping UI render collapsible group
     * sections instead of a flat list of dotted keys.
     *
     * @return array<string, array{label: string, max: int, fields: array<string, array<string, mixed>>}>
     */
    public static function getImportGroups(): array
    {
        $groups = [];

        foreach (static::modelConfig()['fields'] ?? [] as $field => $def) {
            if (($def['type'] ?? null) !== 'group') {
                continue;
            }

            $lang = isset($def['lang']) ? static::resolveFieldLang($def['lang']) : null;

            $groups[$field] = [
                'label' => $lang['label'] ?? $def['label'] ?? $field,
                'max' => max(1, (int) ($def['repeat']['max'] ?? 1)),
                'fields' => $def['fields'] ?? [],
            ];
        }

        return $groups;
    }

    public static function getImportUniqueBy(): ?array
    {
        return static::modelConfig()['unique_by'] ?? null;
    }

    // ── Exportable ────────────────────────────────────────────────────────

    public static function getExportableFields(): array
    {
        $cfg = static::modelConfig();
        $result = [];

        foreach ($cfg['export_fields'] ?? [] as $field => $def) {
            $lang = isset($def['lang'])
                ? static::resolveFieldLang($def['lang'])
                : ['label' => $field, 'aliases' => []];

            $result[$field] = array_merge(['label' => $lang['label']], $def);
            unset($result[$field]['lang']);
        }

        return $result;
    }

    public static function modifyExportQuery($query)
    {
        $relations = static::modelConfig()['export_with'] ?? [];

        return empty($relations) ? $query : $query->with($relations);
    }

    // ── Export formatting ─────────────────────────────────────────────────

    public static function transformForExport($model): array
    {
        $data = [];

        foreach (static::getExportableFields() as $field => $cfg) {
            $accessor = $cfg['accessor'] ?? $field;

            $value = is_callable($accessor)
                ? call_user_func($accessor, $model)
                : data_get($model, $accessor);

            if (isset($cfg['format']) && ! is_null($value)) {
                $value = static::formatExportValue($value, $cfg['format']);
            }

            $data[$field] = $value;
        }

        return $data;
    }

    protected static function formatExportValue($value, string $format): mixed
    {
        return match ($format) {
            'date' => $value instanceof \Carbon\Carbon ? $value->format('Y-m-d') : $value,
            'datetime' => $value instanceof \Carbon\Carbon ? $value->format('Y-m-d H:i:s') : $value,
            'time' => $value instanceof \Carbon\Carbon ? $value->format('H:i:s') : $value,
            'boolean' => $value ? __('import-export::export.yes') : __('import-export::export.no'),
            'number' => is_numeric($value) ? number_format((float) $value, 2) : $value,
            'currency' => is_numeric($value) ? number_format((float) $value, 2, '.', ',') : $value,
            default => $value,
        };
    }
}
