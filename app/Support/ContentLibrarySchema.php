<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Required Content Library columns for Hostinger / production parity.
 * Migrations already create these; this is the deploy smoke check.
 */
final class ContentLibrarySchema
{
    /**
     * @return array<string, list<string>>
     */
    public static function requiredColumns(): array
    {
        return [
            'content_submissions' => [
                'archived_at',
                'country',
                'language',
                'expires_at',
                'path',
                'moderation_status',
                'order_id',
            ],
            'order_items' => [
                'content_submission_id',
            ],
        ];
    }

    /**
     * @return list<array{table: string, column: string}>
     */
    public static function missing(): array
    {
        $missing = [];

        foreach (self::requiredColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = ['table' => $table, 'column' => '*'];

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = ['table' => $table, 'column' => $column];
                }
            }
        }

        return $missing;
    }

    public static function ready(): bool
    {
        return self::missing() === [];
    }
}
