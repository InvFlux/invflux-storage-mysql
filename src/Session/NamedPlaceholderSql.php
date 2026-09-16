<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\InvFlux\Exceptions\PersistenceException;

/**
 * Normalize named placeholders into ordered positional parameters.
 *
 * @internal
 */
final class NamedPlaceholderSql
{
    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return array{sql: string, params: list<scalar|null>}
     */
    public static function positional(string $sql, array $params): array
    {
        if (array_is_list($params)) {
            /** @var list<scalar|null> $params */
            return ['sql' => $sql, 'params' => $params];
        }

        /** @var list<scalar|null> $ordered */
        $ordered = [];
        $normalizedSql = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $matches) use ($params, &$ordered): string {
                $name = $matches[1];
                if (!array_key_exists($name, $params)) {
                    throw new PersistenceException(
                        sprintf('Missing SQL parameter "%s".', $name),
                        'missing_sql_parameter',
                        ['parameter' => $name],
                    );
                }

                $value = $params[$name];
                is_scalar($value) || null === $value || throw new PersistenceException(
                    sprintf('SQL parameter "%s" must be scalar or null.', $name),
                    'invalid_sql_parameter',
                    ['parameter' => $name],
                );
                $ordered[] = $value;

                return '?';
            },
            $sql,
        );

        return [
            'sql'    => $normalizedSql ?? throw new PersistenceException('SQL placeholder normalization failed.', 'sql_placeholder_normalization_failed'),
            'params' => $ordered,
        ];
    }
}
