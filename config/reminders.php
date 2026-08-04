<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fatigue cap
    |--------------------------------------------------------------------------
    |
    | Ceiling on reminder emails one person can receive in a rolling day.
    | Transactional mail (receipts, order status, chat) is never counted or
    | blocked — this only governs the nudges defined below, so a bad day in the
    | data cannot turn into a mailbox flood.
    |
    */
    'daily_cap_per_user' => (int) env('REMINDER_DAILY_CAP', 2),

    /*
    |--------------------------------------------------------------------------
    | Publisher: accept the order
    |--------------------------------------------------------------------------
    |
    | Hours after payment at which to nudge a publisher who has not accepted
    | yet. Nothing is happening on the order at all in this state, so it is the
    | most urgent of the two publisher tracks. The last stage copies an admin.
    |
    */
    'publisher_accept' => [
        'stages_hours' => [12, 36, 72],
        'admin_alert_from_stage' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher: publish the article
    |--------------------------------------------------------------------------
    |
    | Anchored on the deadline the publisher set themselves — accepted_at plus
    | the site's own turnaround_time — rather than a flat interval, so the email
    | can hold them to their own promise and nobody inside their SLA is nagged.
    |
    | Stage 1 is pre-emptive: it fires once the remaining window drops to
    | `due_soon_fraction` of the total (with a floor so a 24h turnaround still
    | gets useful warning). Stages 2+ are hours past the deadline.
    |
    */
    'publisher_publish' => [
        'due_soon_fraction' => 0.25,
        'due_soon_min_hours' => 12,
        'overdue_stages_hours' => [24, 72, 168, 336],
        'admin_alert_from_stage' => 3,
        // Stage at which the order joins the admin stalled queue.
        'stalled_from_stage' => 4,
        // One email per publisher per run once they have this many late items,
        // instead of one per item. Below it, individual emails read better.
        'batch_threshold' => (int) env('REMINDER_PUBLISHER_BATCH_THRESHOLD', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advertiser: review the live URL
    |--------------------------------------------------------------------------
    |
    | Expressed as a fraction of orders.auto_approve_hours so raising the review
    | window shifts the nudge with it instead of silently breaking the cadence.
    | The later reminder (24h before auto-complete) already exists and is owned
    | by orders:auto-approve.
    |
    */
    'advertiser_review' => [
        'nudge_at_fraction' => 0.33,
    ],

    /*
    |--------------------------------------------------------------------------
    | Advertiser: the order has stalled
    |--------------------------------------------------------------------------
    |
    | Waiting in silence is the actual complaint, so tell the advertiser their
    | publisher is late, that support is chasing, and what happens next.
    |
    */
    'advertiser_stalled' => [
        'hours_after_deadline' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | New sites digest
    |--------------------------------------------------------------------------
    |
    | Per-advertiser clock rather than one global blast, so recipients are
    | spread across days and a missed run self-corrects. Only advertisers who
    | have actually paid for an order receive it.
    |
    */
    'new_sites_digest' => [
        'every_days' => (int) env('REMINDER_NEW_SITES_DAYS', 15),
        // Skip the send entirely below the minimum: a digest showing one site
        // is worse than no digest.
        'min_sites' => 3,
        'max_sites' => 6,
        // How recent a listing counts as "new". Discounted sites qualify
        // regardless of age.
        'new_within_days' => 45,
    ],

];
