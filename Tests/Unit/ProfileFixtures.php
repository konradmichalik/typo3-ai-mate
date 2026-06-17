<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Unit;

/**
 * ProfileFixtures.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
trait ProfileFixtures
{
    private string $rootDir;
    private string $profilesDir;

    private function initProfilesDir(string $prefix): void
    {
        $this->rootDir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        $this->profilesDir = $this->rootDir.'/var/log/profiles';
        mkdir($this->profilesDir, 0777, true);
    }

    private function cleanupProfilesDir(): void
    {
        foreach (glob($this->profilesDir.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->profilesDir);
        @rmdir($this->rootDir.'/var/log');
        @rmdir($this->rootDir.'/var');
        @rmdir($this->rootDir);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeProfile(string $token, array $data, int $mtime, ?int $schemaVersion = 1): void
    {
        $base = ['token' => $token, 'time' => '2026-06-15T10:00:00+00:00'];
        if (null !== $schemaVersion) {
            $base['schemaVersion'] = $schemaVersion;
        }
        $file = $this->profilesDir.'/'.$token.'.json';
        file_put_contents($file, json_encode($base + $data, \JSON_THROW_ON_ERROR));
        touch($file, $mtime);
    }
}
