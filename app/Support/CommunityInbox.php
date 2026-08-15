<?php

namespace App\Support;

/**
 * Per-tab status vocabulary for the admin Community inbox.
 *
 * Claims use approved/rejected; website suggestions use accepted;
 * problems and suggestions use resolved. A shared dropdown used to
 * mix these and made approved claims unfilterable.
 */
class CommunityInbox
{
    public const TAB_PROBLEMS = 'problems';

    public const TAB_SUGGESTIONS = 'suggestions';

    public const TAB_WEBSITES = 'websites';

    public const TAB_CLAIMS = 'claims';

    public const DEFAULT_TAB = self::TAB_PROBLEMS;

    /** @var array<string, string> */
    public const TABS = [
        self::TAB_PROBLEMS => 'Problem reports',
        self::TAB_SUGGESTIONS => 'Suggestion box',
        self::TAB_WEBSITES => 'Website suggestions',
        self::TAB_CLAIMS => 'Site claims',
    ];

    /** @var array<string, list<string>> */
    public const STATUSES = [
        self::TAB_PROBLEMS => ['pending', 'reviewed', 'resolved', 'rejected'],
        self::TAB_SUGGESTIONS => ['pending', 'reviewed', 'resolved', 'rejected'],
        self::TAB_WEBSITES => ['pending', 'reviewed', 'accepted', 'rejected'],
        self::TAB_CLAIMS => ['pending', 'approved', 'rejected'],
    ];

    public static function normalizeTab(mixed $tab): string
    {
        $tab = search_text($tab);

        return array_key_exists($tab, self::TABS) ? $tab : self::DEFAULT_TAB;
    }

    /**
     * @return list<string>
     */
    public static function statusesFor(string $tab): array
    {
        return self::STATUSES[self::normalizeTab($tab)] ?? self::STATUSES[self::DEFAULT_TAB];
    }

    public static function allowsStatus(string $tab, string $status): bool
    {
        return in_array($status, self::statusesFor($tab), true);
    }

    /**
     * Valid status for the tab, or null (meaning "all") when missing/invalid.
     */
    public static function normalizeStatus(string $tab, mixed $status): ?string
    {
        $status = search_text($status);
        if ($status === '' || ! self::allowsStatus($tab, $status)) {
            return null;
        }

        return $status;
    }

    /**
     * Query string for a tab link: keep search, keep status only when legal there.
     *
     * @return array{tab: string, q?: string, status?: string}
     */
    public static function tabQuery(string $targetTab, mixed $q = null, mixed $status = null): array
    {
        $tab = self::normalizeTab($targetTab);
        $params = ['tab' => $tab];

        $q = search_text($q);
        if ($q !== '') {
            $params['q'] = $q;
        }

        $normalized = self::normalizeStatus($tab, $status);
        if ($normalized !== null) {
            $params['status'] = $normalized;
        }

        return $params;
    }
}
