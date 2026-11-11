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

namespace KonradMichalik\Typo3AiMate\Command;

use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Command\Support\{ReadOnlyBackendUser, RecordQueryInput};
use KonradMichalik\Typo3AiMate\Support\{Cast, Redactor};
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\{NullSite, SiteInterface};
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function sprintf;

/**
 * BePermissionsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:be-permissions',
    description: 'Effective permissions of a backend user or group (merged group data, mounts, page bits, editable fields) as JSON.',
)]
final class BePermissionsCommand extends AbstractJsonCommand
{
    private const PAGE_PERMISSIONS = [
        Permission::PAGE_SHOW => 'show',
        Permission::PAGE_EDIT => 'edit',
        Permission::PAGE_DELETE => 'delete',
        Permission::PAGE_NEW => 'new',
        Permission::CONTENT_EDIT => 'editContent',
    ];

    /**
     * A group is evaluated as the only group of a user that exists nowhere: no
     * page can be owned by uid -1, so only group and everybody bits apply.
     */
    private const VIRTUAL_USER_UID = -1;

    /**
     * be_users.options bits 1 and 2: take page and file mounts from the groups.
     */
    private const MOUNTS_FROM_GROUPS = 3;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PageTsConfigFactory $pageTsConfigFactory,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Backend user uid')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Backend group uid, evaluated as the only group of a user')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'TCA table whose field access to report')
            ->addOption('page-id', null, InputOption::VALUE_REQUIRED, 'Page uid whose webmount and permission bits to report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $userUid = RecordQueryInput::intOption($input->getOption('user'), 'user');
            $groupUid = RecordQueryInput::intOption($input->getOption('group'), 'group');
            $pageId = RecordQueryInput::intOption($input->getOption('page-id'), 'page-id');
            $table = $this->table($input->getOption('table'));
            if ((null === $userUid) === (null === $groupUid)) {
                throw new InvalidArgumentException('Pass exactly one of --user <uid> or --group <uid>.', 1762850001);
            }

            $backendUser = null !== $userUid ? $this->loadUser($userUid) : $this->loadGroup(Cast::int($groupUid));
            $backendUser->fetchGroupData();
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        $result = [
            'subject' => null !== $userUid ? ['user' => $userUid] : ['group' => $groupUid],
            'admin' => $backendUser->isAdmin(),
            'groups' => $this->groups($backendUser),
        ];
        if ($backendUser->isAdmin()) {
            $result['_hint'] = 'Admin user: every table, field, language, mount and page permission check passes, so nothing restricts this user. Evaluate an editor or a group to see restrictions.';

            return $this->emit($output, $result);
        }

        $result += $this->groupData($backendUser, $table);
        if (null !== $pageId) {
            $result['page'] = $this->page($backendUser, $pageId);
        }
        if (null !== $table) {
            $result['table'] = $this->tableAccess($backendUser, $table, $pageId);
        }

        return $this->emit($output, $result);
    }

    private function table(mixed $value): ?string
    {
        $table = trim(Cast::string($value));
        if ('' === $table) {
            return null;
        }
        if (!is_array(Cast::array($GLOBALS['TCA'] ?? null)[$table] ?? null)) {
            throw new InvalidArgumentException(sprintf('Unknown TCA table "%s".', $table), 1762850002);
        }

        return $table;
    }

    private function loadUser(int $uid): ReadOnlyBackendUser
    {
        $backendUser = new ReadOnlyBackendUser();
        $backendUser->setBeUserByUid($uid);
        if (!is_array($backendUser->user)) {
            throw new RuntimeException(sprintf('Backend user with UID %d not found or disabled.', $uid), 1762850003);
        }

        return $backendUser;
    }

    private function loadGroup(int $uid): ReadOnlyBackendUser
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $exists = $queryBuilder->count('uid')->from('be_groups')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        if (0 === Cast::int($exists)) {
            throw new RuntimeException(sprintf('Backend group with UID %d not found or hidden.', $uid), 1762850004);
        }

        $backendUser = new ReadOnlyBackendUser();
        $backendUser->user = [
            'uid' => self::VIRTUAL_USER_UID,
            'pid' => 0,
            'admin' => 0,
            'usergroup' => (string) $uid,
            'options' => self::MOUNTS_FROM_GROUPS,
            'workspace_id' => 0,
        ];

        return $backendUser;
    }

    /**
     * @return list<array{uid: int, title: string}>
     */
    private function groups(ReadOnlyBackendUser $backendUser): array
    {
        return array_values(array_map(
            static fn (int $uid): array => [
                'uid' => $uid,
                'title' => Redactor::redact(Cast::string(Cast::array($backendUser->userGroups[$uid] ?? null)['title'] ?? '')),
            ],
            array_map(Cast::int(...), $backendUser->userGroupsUID),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function groupData(ReadOnlyBackendUser $backendUser, ?string $table): array
    {
        $groupData = Cast::array($backendUser->groupData);
        $nonExcludeFields = self::stringList($groupData['non_exclude_fields'] ?? '');
        if (null !== $table) {
            $nonExcludeFields = array_values(array_filter($nonExcludeFields, static fn (string $field): bool => str_starts_with($field, $table.':')));
        }
        $languages = self::intList($groupData['allowed_languages'] ?? '');

        return [
            'tables' => [
                'select' => self::stringList($groupData['tables_select'] ?? ''),
                'modify' => self::stringList($groupData['tables_modify'] ?? ''),
            ],
            'nonExcludeFields' => $nonExcludeFields,
            'explicitAllowDeny' => self::stringList($groupData['explicit_allowdeny'] ?? ''),
            'languages' => ['restricted' => [] !== $languages, 'allowed' => $languages],
            'webmounts' => $backendUser->getWebmounts(),
            'filemounts' => self::intList($groupData['filemounts'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(ReadOnlyBackendUser $backendUser, int $pageId): array
    {
        $row = BackendUtility::getRecord('pages', $pageId);
        if (!is_array($row)) {
            return ['uid' => $pageId, 'found' => false];
        }

        $webmount = $backendUser->isInWebMount($row);
        $permissions = Cast::int($backendUser->calcPerms($row));

        return [
            'uid' => $pageId,
            'found' => true,
            'inWebmount' => null !== $webmount,
            'webmount' => null !== $webmount ? Cast::int($webmount) : null,
            'permissions' => $permissions,
            'granted' => array_values(array_filter(
                self::PAGE_PERMISSIONS,
                static fn (int $bit): bool => ($permissions & $bit) === $bit,
                \ARRAY_FILTER_USE_KEY,
            )),
        ];
    }

    /**
     * Mirrors the backend form: an exclude field needs a non_exclude_fields
     * grant, and TCEFORM.<table>.<field>.disabled hides it regardless.
     *
     * @return array<string, mixed>
     */
    private function tableAccess(ReadOnlyBackendUser $backendUser, string $table, ?int $pageId): array
    {
        $columns = Cast::array(Cast::array(Cast::array($GLOBALS['TCA'] ?? null)[$table] ?? null)['columns'] ?? null);
        $tceForm = Cast::array(Cast::array($this->pageTsConfig($backendUser, $pageId)['TCEFORM.'] ?? null)[$table.'.'] ?? null);
        $modify = $backendUser->check('tables_modify', $table);

        $excluded = [];
        $disabled = [];
        $editable = [];
        foreach ($columns as $field => $configuration) {
            $field = (string) $field;
            $isExcluded = (bool) (Cast::array($configuration)['exclude'] ?? false)
                && !$backendUser->check('non_exclude_fields', $table.':'.$field);
            $isDisabled = (bool) (Cast::array($tceForm[$field.'.'] ?? null)['disabled'] ?? false);
            if ($isExcluded) {
                $excluded[] = $field;
            }
            if ($isDisabled) {
                $disabled[] = $field;
            }
            if ($modify && !$isExcluded && !$isDisabled) {
                $editable[] = $field;
            }
        }

        return [
            'name' => $table,
            'select' => $backendUser->check('tables_select', $table),
            'modify' => $modify,
            'editableFields' => $editable,
            'excludedFields' => $excluded,
            'disabledByTsconfig' => $disabled,
        ];
    }

    /**
     * Built for the evaluated user rather than through BackendUtility, which
     * merges the page.* overrides of the CLI process's own backend user.
     *
     * @return array<mixed>
     */
    private function pageTsConfig(ReadOnlyBackendUser $backendUser, ?int $pageId): array
    {
        $rootLine = [];
        $site = new NullSite();
        if (null !== $pageId && $pageId > 0) {
            $rootLine = BackendUtility::BEgetRootLine($pageId, '', true);
            ksort($rootLine);
            $site = $this->site($pageId);
        }

        return $this->pageTsConfigFactory->create($rootLine, $site, $backendUser->getUserTsConfig())->getPageTsConfigArray();
    }

    private function site(int $pageId): SiteInterface
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return new NullSite();
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return GeneralUtility::trimExplode(',', Cast::string($value), true);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        return array_map(Cast::int(...), self::stringList($value));
    }
}
