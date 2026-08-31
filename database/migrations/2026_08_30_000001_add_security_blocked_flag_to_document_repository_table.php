<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate flag, not a new global_status enum value — global_status is
 * a hard DB-level ENUM (see 2024_01_01_000004_create_document_repository_
 * table.php) and Doctrine DBAL (required to alter an enum's value set)
 * isn't installed in this project, same reasoning already documented on
 * disputed_at/is_legacy_import. A security-blocked upload reuses the
 * existing 'rejected' status (so the tracker/resubmit flow both already
 * work with zero changes) with this flag layered on top to distinguish
 * it from an ordinary human rejection — see WorkflowService::
 * blockForSecurity() and MalwareScanService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->boolean('is_security_blocked')->default(false)->after('global_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('is_security_blocked');
        });
    }
};
