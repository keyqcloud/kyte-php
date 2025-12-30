#!/usr/bin/env php
<?php
/**
 * Cron Version Management Utility
 *
 * Command-line tool for managing cron job versions, viewing history,
 * comparing versions, and performing rollbacks.
 *
 * Usage:
 *   php bin/cron-version.php <command> [options]
 *
 * Commands:
 *   history <job_id>              View version history for a job
 *   show <job_id> <version>       Show specific version code
 *   compare <job_id> <v1> <v2>    Compare two versions
 *   rollback <job_id> <version>   Rollback to specific version
 *   stats                         View content deduplication statistics
 *   prune <job_id> [keep=10]      Prune old versions (keep N recent)
 *
 * Examples:
 *   php bin/cron-version.php history 5
 *   php bin/cron-version.php show 5 3
 *   php bin/cron-version.php compare 5 2 4
 *   php bin/cron-version.php rollback 5 3
 *   php bin/cron-version.php stats
 *   php bin/cron-version.php prune 5 15
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kyte\Core\Api;
use Kyte\Core\DBI;
use Kyte\Core\Model;
use Kyte\Cron\CronJobManager;
use Kyte\Cron\CronVersionControl;

// Initialize
$api = Api::getInstance();
$manager = new CronJobManager($api);
$versionControl = new CronVersionControl();

// Parse command
$command = isset($argv[1]) ? $argv[1] : 'help';

try {
    switch ($command) {
        case 'history':
            if (!isset($argv[2])) {
                echo "Usage: php bin/cron-version.php history <job_id> [limit=50]\n";
                exit(1);
            }

            $jobId = (int)$argv[2];
            $limit = isset($argv[3]) ? (int)$argv[3] : 50;

            showHistory($manager, $jobId, $limit);
            break;

        case 'show':
            if (!isset($argv[2]) || !isset($argv[3])) {
                echo "Usage: php bin/cron-version.php show <job_id> <version_number>\n";
                exit(1);
            }

            $jobId = (int)$argv[2];
            $versionNumber = (int)$argv[3];

            showVersion($manager, $jobId, $versionNumber);
            break;

        case 'compare':
            if (!isset($argv[2]) || !isset($argv[3]) || !isset($argv[4])) {
                echo "Usage: php bin/cron-version.php compare <job_id> <version1> <version2>\n";
                exit(1);
            }

            $jobId = (int)$argv[2];
            $version1 = (int)$argv[3];
            $version2 = (int)$argv[4];

            compareVersions($manager, $jobId, $version1, $version2);
            break;

        case 'rollback':
            if (!isset($argv[2]) || !isset($argv[3])) {
                echo "Usage: php bin/cron-version.php rollback <job_id> <version_number>\n";
                exit(1);
            }

            $jobId = (int)$argv[2];
            $versionNumber = (int)$argv[3];

            rollbackVersion($manager, $jobId, $versionNumber);
            break;

        case 'stats':
            showStats($manager);
            break;

        case 'prune':
            if (!isset($argv[2])) {
                echo "Usage: php bin/cron-version.php prune <job_id> [keep=10]\n";
                exit(1);
            }

            $jobId = (int)$argv[2];
            $keepCount = isset($argv[3]) ? (int)$argv[3] : 10;

            pruneVersions($manager, $jobId, $keepCount);
            break;

        case 'help':
        default:
            showHelp();
            break;
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Show version history for a job
 */
function showHistory($manager, $jobId, $limit)
{
    $job = Model::one('CronJob', $jobId);
    if (!$job) {
        throw new \Exception("Job not found: {$jobId}");
    }

    echo "Version History for Job #{$jobId}: {$job->name}\n";
    echo str_repeat("=", 80) . "\n\n";

    $versions = $manager->getVersionHistory($jobId, $limit);

    if (empty($versions)) {
        echo "No versions found.\n";
        return;
    }

    foreach ($versions as $version) {
        $current = $version['is_current'] ? " (CURRENT)" : "";
        $hash = substr($version['content_hash'], 0, 12);
        $date = date('Y-m-d H:i:s', $version['date_created']);
        $refs = $version['reference_count'];

        echo "Version {$version['version_number']}{$current}\n";
        echo "  Date: {$date}\n";
        echo "  Content Hash: {$hash}... (refs: {$refs})\n";

        if (!empty($version['changes_from_previous'])) {
            $changes = $version['changes_from_previous'];
            echo "  Changes: ";

            $parts = [];
            if ($changes['lines_added'] > 0) {
                $parts[] = "+{$changes['lines_added']} lines";
            }
            if ($changes['lines_removed'] > 0) {
                $parts[] = "-{$changes['lines_removed']} lines";
            }
            if ($changes['lines_changed'] > 0) {
                $parts[] = "~{$changes['lines_changed']} lines";
            }

            echo implode(', ', $parts) . "\n";
            echo "  Total Lines: {$changes['total_lines_before']} → {$changes['total_lines_after']}\n";
        }

        echo "\n";
    }
}

/**
 * Show specific version code
 */
function showVersion($manager, $jobId, $versionNumber)
{
    $job = Model::one('CronJob', $jobId);
    if (!$job) {
        throw new \Exception("Job not found: {$jobId}");
    }

    $code = $manager->getVersionCode($jobId, $versionNumber);

    if ($code === null) {
        throw new \Exception("Version {$versionNumber} not found for job {$jobId}");
    }

    echo "Job #{$jobId}: {$job->name} - Version {$versionNumber}\n";
    echo str_repeat("=", 80) . "\n\n";
    echo $code . "\n";
}

/**
 * Compare two versions
 */
function compareVersions($manager, $jobId, $version1, $version2)
{
    $job = Model::one('CronJob', $jobId);
    if (!$job) {
        throw new \Exception("Job not found: {$jobId}");
    }

    $comparison = $manager->compareVersions($jobId, $version1, $version2);
    $diff = $comparison['diff'];

    echo "Comparing Job #{$jobId}: {$job->name}\n";
    echo "Version {$version1} → Version {$version2}\n";
    echo str_repeat("=", 80) . "\n\n";

    echo "Summary:\n";
    echo "  Lines added: {$diff['lines_added']}\n";
    echo "  Lines removed: {$diff['lines_removed']}\n";
    echo "  Lines changed: {$diff['lines_changed']}\n";
    echo "  Total lines: {$diff['total_lines_before']} → {$diff['total_lines_after']}\n\n";

    if (!empty($diff['changes']) && count($diff['changes']) > 0) {
        echo "Changes (showing first 50):\n";
        echo str_repeat("-", 80) . "\n";

        foreach ($diff['changes'] as $change) {
            switch ($change['type']) {
                case 'added':
                    echo "+ Line {$change['line']}: {$change['content']}\n";
                    break;
                case 'removed':
                    echo "- Line {$change['line']}: {$change['content']}\n";
                    break;
                case 'changed':
                    echo "~ Line {$change['line']}:\n";
                    echo "  - {$change['old']}\n";
                    echo "  + {$change['new']}\n";
                    break;
            }
        }

        if (count($diff['changes']) >= 50) {
            echo "\n(More changes not shown - limit reached)\n";
        }
    } else {
        echo "No differences found.\n";
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "Full Code Versions:\n\n";
    echo "--- Version {$version1} ---\n";
    echo $comparison['code_1'] . "\n\n";
    echo "--- Version {$version2} ---\n";
    echo $comparison['code_2'] . "\n";
}

/**
 * Rollback to specific version
 */
function rollbackVersion($manager, $jobId, $versionNumber)
{
    $job = Model::one('CronJob', $jobId);
    if (!$job) {
        throw new \Exception("Job not found: {$jobId}");
    }

    echo "Rolling back Job #{$jobId}: {$job->name}\n";
    echo "Target version: {$versionNumber}\n";
    echo str_repeat("=", 80) . "\n\n";

    // Confirm rollback
    echo "Are you sure you want to rollback? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'yes') {
        echo "Rollback cancelled.\n";
        return;
    }

    $result = $manager->rollback($jobId, $versionNumber, null);

    if ($result['status'] === 'success') {
        echo "\n✓ Successfully rolled back from version {$result['from_version']} to version {$result['to_version']}\n";
        echo "\nThe job will use version {$result['to_version']} code on next execution.\n";
    } else {
        echo "\n" . $result['message'] . "\n";
    }
}

/**
 * Show content deduplication statistics
 */
function showStats($manager)
{
    echo "Content Deduplication Statistics\n";
    echo str_repeat("=", 80) . "\n\n";

    $stats = $manager->getContentStats();

    if (empty($stats)) {
        echo "No statistics available.\n";
        return;
    }

    echo "Total Content Records: {$stats['total_content_records']}\n";
    echo "Total References: {$stats['total_references']}\n";
    echo "Average References per Content: " . round($stats['avg_references'], 2) . "\n";
    echo "Max References: {$stats['max_references']}\n";
    echo "Total Storage: {$stats['total_storage_mb']} MB (compressed)\n";

    if ($stats['total_content_records'] > 0) {
        $avgSize = round($stats['total_storage_bytes'] / $stats['total_content_records'] / 1024, 2);
        echo "Average Content Size: {$avgSize} KB\n";

        $deduplicationRate = round((1 - ($stats['total_content_records'] / $stats['total_references'])) * 100, 2);
        echo "Deduplication Rate: {$deduplicationRate}%\n";
    }

    echo "\n";
}

/**
 * Prune old versions for a job
 */
function pruneVersions($manager, $jobId, $keepCount)
{
    $job = Model::one('CronJob', $jobId);
    if (!$job) {
        throw new \Exception("Job not found: {$jobId}");
    }

    echo "Pruning old versions for Job #{$jobId}: {$job->name}\n";
    echo "Keeping {$keepCount} most recent versions (+ current version)\n";
    echo str_repeat("=", 80) . "\n\n";

    // Confirm prune
    echo "Are you sure you want to prune old versions? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'yes') {
        echo "Prune cancelled.\n";
        return;
    }

    $deletedCount = $manager->pruneVersions($jobId, $keepCount);

    echo "\n✓ Pruned {$deletedCount} old version(s)\n";
}

/**
 * Show help information
 */
function showHelp()
{
    echo "Cron Version Management Utility\n";
    echo str_repeat("=", 80) . "\n\n";
    echo "Usage: php bin/cron-version.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  history <job_id> [limit]       View version history for a job\n";
    echo "                                 Default limit: 50\n\n";
    echo "  show <job_id> <version>        Show specific version code\n\n";
    echo "  compare <job_id> <v1> <v2>     Compare two versions side-by-side\n";
    echo "                                 Shows diff and full code\n\n";
    echo "  rollback <job_id> <version>    Rollback job to specific version\n";
    echo "                                 Requires confirmation\n\n";
    echo "  stats                          View content deduplication statistics\n";
    echo "                                 Shows storage savings from deduplication\n\n";
    echo "  prune <job_id> [keep]          Prune old versions\n";
    echo "                                 Default keep: 10 (+ current version)\n";
    echo "                                 Requires confirmation\n\n";
    echo "  help                           Show this help message\n\n";
    echo "Examples:\n";
    echo "  php bin/cron-version.php history 5\n";
    echo "  php bin/cron-version.php show 5 3\n";
    echo "  php bin/cron-version.php compare 5 2 4\n";
    echo "  php bin/cron-version.php rollback 5 3\n";
    echo "  php bin/cron-version.php stats\n";
    echo "  php bin/cron-version.php prune 5 15\n\n";
}
