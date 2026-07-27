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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Support\OwnPackages;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\Environment;

/**
 * OwnPackagesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class OwnPackagesTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = Environment::getProjectPath();
        mkdir($this->projectPath.'/vendor/acme/dependency', 0o777, true);
        mkdir($this->projectPath.'/packages/site_package', 0o777, true);
        // Composer path repository: the package is symlinked into vendor/.
        symlink($this->projectPath.'/packages/site_package', $this->projectPath.'/vendor/acme/site_package');
    }

    #[Test]
    public function ownCodeOutsideVendorIsOwn(): void
    {
        self::assertTrue(OwnPackages::isOwn($this->projectPath.'/packages/site_package'));
        self::assertSame('own', OwnPackages::origin($this->projectPath.'/packages/site_package'));
    }

    #[Test]
    public function aRealVendorPackageIsThirdParty(): void
    {
        self::assertFalse(OwnPackages::isOwn($this->projectPath.'/vendor/acme/dependency'));
        self::assertSame('thirdParty', OwnPackages::origin($this->projectPath.'/vendor/acme/dependency'));
    }

    #[Test]
    public function aPathRepositorySymlinkedIntoVendorIsStillOwn(): void
    {
        // getPackagePath() reports the vendor/ symlink, but realpath resolves it
        // back to packages/* — so it must classify as own, not third-party.
        self::assertTrue(OwnPackages::isOwn($this->projectPath.'/vendor/acme/site_package'));
    }
}
