<?php

namespace Modules\Data\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Data\Models\Backup;
use Modules\Data\Services\DatabaseBackups;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloading an archive.
 *
 * The backups disk lives outside the web root, so a route is the only way to
 * reach it — which is the point. A route can be put behind `auth`; a public
 * directory cannot.
 */
class BackupController extends Controller
{
    public function download(DatabaseBackups $backups, Backup $backup): StreamedResponse
    {
        return $backups->stream($backup);
    }
}
