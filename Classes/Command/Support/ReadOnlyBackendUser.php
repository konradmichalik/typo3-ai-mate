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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * ReadOnlyBackendUser.
 *
 * A backend user whose group data the core computes as usual, without the one
 * write fetchGroupData() can trigger: the core persists a corrected workspace
 * to be_users and logs it to sys_log when the stored one is no longer valid.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ReadOnlyBackendUser extends BackendUserAuthentication
{
    /**
     * @param int $workspaceId
     */
    public function setWorkspace($workspaceId): void
    {
        if (!$this->setTemporaryWorkspace($workspaceId)) {
            $this->setDefaultWorkspace();
        }
        $this->checkWorkspaceCurrent_cache = null;
    }
}
