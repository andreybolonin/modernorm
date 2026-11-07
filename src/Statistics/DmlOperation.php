<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Statistics;

/** Executed client statements, not entity counts or server-side trigger activity. */
enum DmlOperation: string
{
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';

    /** @internal Conservative single direct-statement classification; CTE/EXPLAIN/COPY remain SQL-only. */
    public static function fromSql(string $sql): ?self
    {
        $length = strlen($sql);
        $offset = 0;
        self::skipTrivia($sql, $offset, $length);
        if (!preg_match('/\G(INSERT|UPDATE|DELETE)\b/i', $sql, $match, 0, $offset)) {
            return null;
        }
        $operation = self::from(strtolower($match[1]));
        $offset += strlen($match[1]);
        while ($offset < $length) {
            self::skipTrivia($sql, $offset, $length);
            if ($offset >= $length) {
                break;
            }
            $character = $sql[$offset];
            if ($character === ';') {
                ++$offset;
                self::skipTrivia($sql, $offset, $length);
                return $offset === $length ? $operation : null;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $escaped = $quote === "'" && $offset > 0 && in_array($sql[$offset - 1], ['e', 'E'], true)
                    && ($offset < 2 || !preg_match('/[a-zA-Z0-9_]/', $sql[$offset - 2]));
                ++$offset;
                while ($offset < $length) {
                    if ($sql[$offset] === $quote) {
                        ++$offset;
                        if ($offset < $length && $sql[$offset] === $quote) {
                            ++$offset;
                            continue;
                        }
                        break;
                    }
                    if ($escaped && $sql[$offset] === '\\') {
                        $offset += 2;
                    } else {
                        ++$offset;
                    }
                }
                continue;
            }
            if ($character === '$' && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/', $sql, $tag, 0, $offset)) {
                $end = strpos($sql, $tag[0], $offset + strlen($tag[0]));
                if ($end === false) {
                    return null;
                }
                $offset = $end + strlen($tag[0]);
                continue;
            }
            ++$offset;
        }
        return $operation;
    }

    private static function skipTrivia(string $sql, int &$offset, int $length): void
    {
        while ($offset < $length) {
            if (str_contains(" \t\n\r\f\v", $sql[$offset])) {
                ++$offset;
            } elseif (substr_compare($sql, '--', $offset, 2) === 0) {
                $offset += 2;
                while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
                    ++$offset;
                }
            } elseif (substr_compare($sql, '/*', $offset, 2) === 0) {
                $offset += 2;
                $depth = 1;
                while ($offset < $length && $depth > 0) {
                    if (substr_compare($sql, '/*', $offset, 2) === 0) {
                        ++$depth;
                        $offset += 2;
                    } elseif (substr_compare($sql, '*/', $offset, 2) === 0) {
                        --$depth;
                        $offset += 2;
                    } else {
                        ++$offset;
                    }
                }
            } else {
                return;
            }
        }
    }
}
