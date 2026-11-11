<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command\Support;

use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function count;
use function is_object;
use function strlen;

/**
 * ViewHelperLookup.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ViewHelperLookup
{
    private const DESCRIPTION_LENGTH = 200;

    private const CLASS_SUFFIX = 'ViewHelper';

    /**
     * Accepts the name as written in a template too, e.g. `<f:link.page />`.
     *
     * @return array{prefix: string, name: string}|null
     */
    public static function parseName(string $value): ?array
    {
        $parts = explode(':', trim($value, " \t\n\r\0\x0B</>"), 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return ['prefix' => $parts[0], 'name' => $parts[1]];
    }

    /**
     * The template name Fluid resolves to this class, the inverse of
     * `ucfirst` per dot-separated segment plus the `ViewHelper` suffix.
     */
    public static function nameFromClass(string $phpNamespace, string $className): ?string
    {
        $namespace = rtrim($phpNamespace, '\\').'\\';
        if (!str_starts_with($className, $namespace) || !str_ends_with($className, self::CLASS_SUFFIX)) {
            return null;
        }

        $relative = substr($className, strlen($namespace), -strlen(self::CLASS_SUFFIX));
        if ('' === $relative) {
            return null;
        }

        return implode('.', array_map(lcfirst(...), explode('\\', $relative)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeArgument(ArgumentDefinition $argument): array
    {
        $default = $argument->getDefaultValue();
        $description = $argument->getDescription();
        if (mb_strlen($description) > self::DESCRIPTION_LENGTH) {
            $description = mb_substr($description, 0, self::DESCRIPTION_LENGTH).'…';
        }

        return array_filter([
            'type' => $argument->getType(),
            'required' => $argument->isRequired(),
            'default' => is_object($default) ? $default::class : $default,
            'description' => $description,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
