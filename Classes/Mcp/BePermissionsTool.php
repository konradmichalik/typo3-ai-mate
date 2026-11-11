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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * BePermissionsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BePermissionsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int|null    $user   backend user uid to evaluate; pass exactly one of user or group
     * @param int|null    $group  backend group uid, evaluated as a user whose only group it is (subgroups included)
     * @param string|null $table  TCA table, e.g. tt_content: report which of its fields the user can edit, which are exclude fields not granted and which TCEFORM TSconfig disables
     * @param int|null    $pageId page uid: whether it lies inside a webmount and the effective page permission bits; also the page whose TSconfig decides TCEFORM for table
     */
    #[MateTool(
        name: 'typo3-be-permissions',
        title: 'TYPO3 Backend Permissions',
        description: 'Effective permissions of a backend user (or of a group) as the backend computes them: the resolved group chain, tables allowed for select/modify, granted exclude fields, explicit allow/deny values, allowed languages, webmounts and file mounts. With pageId: whether the page is inside a webmount and the effective page permission bits. With table: which fields can be edited, which are exclude fields not granted and which TCEFORM TSconfig disables. Admin users are reported as admin. Answers "why can this editor not see field X / page Y / language Z?". Returns uids and group titles only, never user names or e-mail addresses.',
    )]
    public function inspect(?int $user = null, ?int $group = null, ?string $table = null, ?int $pageId = null): string
    {
        $options = array_filter([
            'user' => $user,
            'group' => $group,
            'table' => $table,
            'page-id' => $pageId,
        ], static fn (int|string|null $value): bool => null !== $value && '' !== $value);

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:be-permissions', [], $options));
    }
}
