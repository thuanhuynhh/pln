<?php

/**
 * @file classes/migration/upgrade/I114_BackfillDepositObjectDateModified.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I114_BackfillDepositObjectDateModified
 *
 * @brief Backfill null pln_deposit_objects.date_modified from the related deposit date_status.
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\install\DowngradeNotSupportedException;

class I114_BackfillDepositObjectDateModified extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = Core::getCurrentDate();
        $rows = DB::table('pln_deposit_objects AS do')
            ->leftJoin('pln_deposits AS d', 'd.deposit_id', '=', 'do.deposit_id')
            ->whereNull('do.date_modified')
            ->select('do.deposit_object_id', 'd.date_status')
            ->get();

        foreach ($rows as $row) {
            DB::table('pln_deposit_objects')
                ->where('deposit_object_id', $row->deposit_object_id)
                ->update(['date_modified' => $row->date_status ?: $now]);
        }
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
