<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Label;

use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Label\LabelTranslation;
use Nandan108\InvFlux\Domain\Label\LabelTranslationRepository;

/**
 * MySQL-backed {@see LabelTranslationRepository} over the shared `invflux_label_translations` set.
 *
 * Every read is one statement over `(entity_type, entity_key, field, locale)` — the unique key —
 * so resolving a list of labels costs the same query whether it holds one row or two hundred.
 *
 * @api
 */
final class MysqlLabelTranslationRepository implements LabelTranslationRepository
{
    #[\Override]
    public function forEntities(string $entityType, array $keys, string $field = LabelTranslation::FIELD_NAME): array
    {
        if ([] === $keys) {
            return [];
        }

        $where = WhereClause::match(['entity_type' => $entityType, 'field' => $field])
            ->andWhere(WhereClause::whereIn('entity_key', array_values(array_unique($keys))));

        $byEntity = [];
        foreach (LabelTranslation::find($where) as $row) {
            $byEntity[$row->entity_key][$row->locale] = $row->text;
        }

        return $byEntity;
    }

    #[\Override]
    public function forType(string $entityType, string $field = LabelTranslation::FIELD_NAME): array
    {
        $where = WhereClause::match(['entity_type' => $entityType, 'field' => $field]);

        $byEntity = [];
        foreach (LabelTranslation::find($where) as $row) {
            $byEntity[$row->entity_key][$row->locale] = $row->text;
        }

        return $byEntity;
    }

    #[\Override]
    public function forEntity(string $entityType, string $entityKey): array
    {
        $where = WhereClause::match(['entity_type' => $entityType, 'entity_key' => $entityKey]);

        $byField = [];
        foreach (LabelTranslation::find($where) as $row) {
            $byField[$row->field][$row->locale] = $row;
        }

        return $byField;
    }

    #[\Override]
    public function replace(string $entityType, string $entityKey, string $field, array $byLocale, ?string $sourceText = null): void
    {
        $wanted = [];
        foreach ($byLocale as $locale => $text) {
            $locale = trim($locale);
            $text = trim($text);
            // An absent locale and a cleared box mean the same thing — no translation — so a blank
            // is dropped here rather than stored. That is how the editor expresses "reset this one"
            // without needing a second control.
            if ('' !== $locale && '' !== $text) {
                $wanted[$locale] = $text;
            }
        }

        $where = WhereClause::match([
            'entity_type' => $entityType,
            'entity_key'  => $entityKey,
            'field'       => $field,
        ]);

        $existing = [];
        foreach (LabelTranslation::find($where) as $row) {
            $existing[$row->locale] = $row;
        }

        $stale = [];
        foreach ($existing as $locale => $row) {
            if (!isset($wanted[$locale])) {
                $stale[] = (int) $row->id;
            }
        }
        if ([] !== $stale) {
            LabelTranslation::deleteWhere(WhereClause::whereIn('id', $stale));
        }

        foreach ($wanted as $locale => $text) {
            $row = $existing[$locale] ?? null;
            if (null === $row) {
                // Built with its text in one go: `newWith()` validates, and a row assembled without
                // one fails the "never store a blank" rule before the text can be assigned.
                LabelTranslation::newWith([
                    'entity_type' => $entityType,
                    'entity_key'  => $entityKey,
                    'field'       => $field,
                    'locale'      => $locale,
                    'text'        => $text,
                    'source_text' => $sourceText,
                ])->save();

                continue;
            }

            // save() writes only dirty columns, so re-submitting an unchanged set is a no-op per
            // row rather than a table's worth of pointless UPDATEs.
            $row->text = $text;
            // Every row this call writes was written against the label as it reads now, including
            // one that is merely being re-saved: the caller looked at the current text either way.
            // A null caller leaves what is on file rather than erasing a source it cannot replace.
            //
            // Deliberately unlike `replaceLocale()`, which skips an unchanged row entirely — do not
            // "fix" one to match the other. There the set spans every entity of a type, so a write
            // sweeps rows the caller never looked at, and re-stamping their source was a real loss.
            // Here the set is one entity's languages on one form: whoever saved it was looking at
            // this label, so its current text is genuinely what they wrote against.
            if (null !== $sourceText) {
                $row->source_text = $sourceText;
            }
            $row->save();
        }
    }

    #[\Override]
    public function replaceLocale(
        string $entityType,
        string $locale,
        string $field,
        array $byEntity,
        array $sourceByKey = [],
    ): void {
        // Two sets, because three intents arrive here and collapsing any two of them loses data.
        // `$mentioned` is every key the caller had an opinion about — it bounds what may be
        // deleted, so a key it never names survives. `$wanted` is the subset to write; a mentioned
        // key that is null or blank is a removal, not an omission.
        $mentioned = [];
        $wanted = [];
        foreach ($byEntity as $key => $text) {
            $key = trim($key);
            if ('' === $key) {
                continue;
            }
            $mentioned[$key] = true;
            $text = null === $text ? '' : trim($text);
            if ('' !== $text) {
                $wanted[$key] = $text;
            }
        }

        $where = WhereClause::match([
            'entity_type' => $entityType,
            'field'       => $field,
            'locale'      => $locale,
        ]);

        $existing = [];
        foreach (LabelTranslation::find($where) as $row) {
            $existing[$row->entity_key] = $row;
        }

        $stale = [];
        foreach ($existing as $key => $row) {
            // Bounded by `$mentioned`: a row the caller never named is not theirs to remove.
            if (isset($mentioned[$key]) && !isset($wanted[$key])) {
                $stale[] = (int) $row->id;
            }
        }
        if ([] !== $stale) {
            LabelTranslation::deleteWhere(WhereClause::whereIn('id', $stale));
        }

        foreach ($wanted as $key => $text) {
            $source = $sourceByKey[$key] ?? null;
            $row = $existing[$key] ?? null;
            if (null === $row) {
                // With its text, for the same reason as {@see replace()}.
                LabelTranslation::newWith([
                    'entity_type' => $entityType,
                    'entity_key'  => $key,
                    'field'       => $field,
                    'locale'      => $locale,
                    'text'        => $text,
                    'source_text' => $source,
                ])->save();

                continue;
            }

            if ($row->text === $text) {
                // Nothing to write. `source_text` records what the label read when the merchant
                // authored `text`, so it moves exactly when `text` moves — never as a side effect
                // of a write that touched this row for some other reason. Re-stamping it here
                // destroyed the one signal that tells a merchant their translation predates a
                // rename, and did it to every row a single unrelated rename passed through.
                continue;
            }

            $row->text = $text;
            if (null !== $source) {
                $row->source_text = $source;
            }
            $row->save();
        }
    }

    #[\Override]
    public function purge(string $entityType, string $entityKey): void
    {
        LabelTranslation::deleteWhere(WhereClause::match([
            'entity_type' => $entityType,
            'entity_key'  => $entityKey,
        ]));
    }
}
