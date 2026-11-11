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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\ViewHelperLookup;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

/**
 * ViewHelperLookupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ViewHelperLookupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{prefix: string, name: string}|null}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['f:link.page', ['prefix' => 'f', 'name' => 'link.page']];
        yield 'surrounding whitespace' => [' f:if ', ['prefix' => 'f', 'name' => 'if']];
        yield 'written as a tag' => ['<f:format.date />', ['prefix' => 'f', 'name' => 'format.date']];
        yield 'no prefix' => ['link.page', null];
        yield 'empty name' => ['f:', null];
        yield 'empty prefix' => [':link.page', null];
        yield 'path segments in the name' => ['f:../../tmp/evil', null];
        yield 'namespace separator in the name' => ['f:Foo\\Bar', null];
    }

    /**
     * @param array{prefix: string, name: string}|null $expected
     */
    #[Test]
    #[DataProvider('names')]
    public function parseNameSplitsPrefixAndName(string $value, ?array $expected): void
    {
        self::assertSame($expected, ViewHelperLookup::parseName($value));
    }

    #[Test]
    public function nameFromClassTurnsTheClassPathIntoTheTemplateName(): void
    {
        self::assertSame(
            'link.page',
            ViewHelperLookup::nameFromClass('TYPO3\\CMS\\Fluid\\ViewHelpers', 'TYPO3\\CMS\\Fluid\\ViewHelpers\\Link\\PageViewHelper'),
        );
        self::assertSame(
            'if',
            ViewHelperLookup::nameFromClass('TYPO3Fluid\\Fluid\\ViewHelpers\\', 'TYPO3Fluid\\Fluid\\ViewHelpers\\IfViewHelper'),
        );
    }

    #[Test]
    public function nameFromClassIgnoresClassesOutsideTheNamespaceOrWithoutTheSuffix(): void
    {
        self::assertNull(ViewHelperLookup::nameFromClass('My\\ViewHelpers', 'Other\\ViewHelpers\\IfViewHelper'));
        self::assertNull(ViewHelperLookup::nameFromClass('My\\ViewHelpers', 'My\\ViewHelpers\\Traits\\CompileWithRenderStatic'));
        self::assertNull(ViewHelperLookup::nameFromClass('My\\ViewHelpers', 'My\\ViewHelpersExtra\\IfViewHelper'));
    }

    #[Test]
    public function describeArgumentKeepsTheRequiredFlagAndDropsEmptyEntries(): void
    {
        self::assertSame(
            ['type' => 'string', 'required' => true, 'description' => 'The target URI'],
            ViewHelperLookup::describeArgument(new ArgumentDefinition('uri', 'string', 'The target URI', true)),
        );
        self::assertSame(
            ['type' => 'bool', 'required' => false, 'default' => false],
            ViewHelperLookup::describeArgument(new ArgumentDefinition('absolute', 'bool', '', false, false)),
        );
    }

    #[Test]
    public function describeArgumentReportsAnObjectDefaultByItsType(): void
    {
        $described = ViewHelperLookup::describeArgument(new ArgumentDefinition('context', 'object', '', false, new stdClass()));

        self::assertSame('stdClass', $described['default']);
    }

    #[Test]
    public function describeArgumentShortensALongDescription(): void
    {
        $described = ViewHelperLookup::describeArgument(new ArgumentDefinition('text', 'string', str_repeat('a', 500), false));

        self::assertIsString($described['description']);
        self::assertSame(201, mb_strlen($described['description']));
    }
}
