<?php

namespace App\Support;

/**
 * First-party click-through for a catalog listing host.
 *
 * Advertiser Orders / chat must not put the publisher site_url in href —
 * "Copy link address" would bypass catalog copy-track the same way the
 * catalog eye used to. Display text can still show the host they bought.
 */
final class CatalogVisitUrl
{
    public static function forSiteId(int|string|null $siteId): ?string
    {
        $id = (int) $siteId;
        if ($id <= 0) {
            return null;
        }

        return route('advertiser.catalog.visit', $id, false);
    }
}
