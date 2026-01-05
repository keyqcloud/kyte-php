<?php

namespace Kyte\Cron;

use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\AI\AIErrorAnalyzer;
use Kyte\AI\AIErrorFixApplier;
use Kyte\AI\AILoopDetector;

/**
 * AI Error Analysis Cron Job
 *
 * Processes queued error analyses asynchronously.
 * Runs every 5 minutes (configurable per application).
 *
 * Features:
 * - Batch processing (configurable batch size)
 * - Rate limiting enforcement
 * - Loop detection and auto-disable
 * - Auto-fix application (if enabled and confidence high enough)
 * - Retry logic for failed analyses
 * - Cost tracking and budget enforcement
 *
 * @package Kyte\Cron
 */
class AIErrorAnalysisCron extends \Kyte\Core\CronJobBase
{
    private $processed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $autoFixed = 0;

    /**
     * Setup - runs before execute
     */
    public function setUp() {
        // Verify AWS Bedrock credentials are available
        if (!defined('AWS_ACCESS_KEY_ID') || !defined('AWS_SECRET_KEY')) {
            throw new \Exception("AWS credentials not configured for AI Error Correction");
        }

        if (!defined('AI_ERROR_CORRECTION') || !AI_ERROR_CORRECTION) {
            throw new \Exception("AI Error Correction is not enabled (AI_ERROR_CORRECTION constant)");
        }

        $this->log("AI Error Analysis Cron Job starting...");
    }

    /**
     * Execute - main cron job logic
     */
    public function execute() {
        // Get all queued analyses grouped by application
        $queuedByApp = $this->getQueuedAnalysesByApplication();

        if (empty($queuedByApp)) {
            $this->log("No pending analyses found.");
            return;
        }

        $this->log("Found analyses queued in " . count($queuedByApp) . " application(s).");

        // Process each application's queue
        foreach ($queuedByApp as $appId => $analyses) {
            try {
                $this->processApplicationQueue($appId, $analyses);
            } catch (\Exception $e) {
                $this->logError("Failed to process application {$appId} queue: " . $e->getMessage());
            }

            // Extend lease periodically for long-running batches
            $this->heartbeat();
        }

        $this->log("Batch complete. Processed: {$this->processed}, Auto-fixed: {$this->autoFixed}, Failed: {$this->failed}, Skipped: {$this->skipped}");
    }

    /**
     * Teardown - runs after execute
     */
    public function tearDown() {
        // Optional: Clean up old completed analyses (retention policy)
        // For now, we keep all analyses for historical purposes
        $this->log("AI Error Analysis Cron Job completed.");
    }

    /**
     * Get queued analyses grouped by application
     *
     * @return array Application ID => array of analyses
     */
    private function getQueuedAnalysesByApplication() {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('analysis_status', 'queued', false, null, false,
            [['field' => 'queued_at', 'direction' => 'ASC']]);

        $byApp = [];
        foreach ($model->objects as $analysis) {
            $appId = $analysis->application ?? 0;
            if (!isset($byApp[$appId])) {
                $byApp[$appId] = [];
            }
            $byApp[$appId][] = $analysis;
        }

        return $byApp;
    }

    /**
     * Process queue for a specific application
     *
     * @param int $appId Application ID
     * @param array $analyses Array of analysis objects
     * @return void
     */
    private function processApplicationQueue($appId, $analyses) {
        // Load application configuration
        $config = \Kyte\AI\AIErrorCorrection::getConfig($appId, $analyses[0]->kyte_account);

        if (!$config || !$config->enabled) {
            $this->log("Skipping application {$appId} - AI Error Correction not enabled");
            $this->skipped += count($analyses);
            return;
        }

        // Determine batch size for this application
        $batchSize = $config->batch_size ?? 10;
        $maxConcurrent = $config->max_concurrent_bedrock_calls ?? 3;

        $this->log("Processing application {$appId} - {count($analyses)} queued, batch size: {$batchSize}");

        // Process up to batch_size analyses
        $toProcess = array_slice($analyses, 0, $batchSize);

        foreach ($toProcess as $analysis) {
            try {
                $this->processAnalysis($analysis, $config);
            } catch (\Exception $e) {
                $this->logError("Analysis {$analysis->id} failed: " . $e->getMessage());
                $this->failed++;
            }

            // Small delay to avoid hammering Bedrock API
            usleep(100000); // 100ms
        }
    }

    /**
     * Process a single analysis
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @param ModelObject $config AIErrorCorrectionConfig object
     * @return void
     */
    private function processAnalysis($analysis, $config) {
        // Mark as processing
        $analysis->save([
            'analysis_status' => 'processing',
            'processing_started_at' => time(),
        ]);

        // Check rate limits
        if (!$this->checkRateLimits($config)) {
            $this->log("Rate limit exceeded for application {$config->application}, skipping analysis {$analysis->id}");
            $analysis->save([
                'analysis_status' => 'queued',
                'processing_started_at' => null,
                'retry_count' => $analysis->retry_count + 1,
                'last_error' => 'Rate limit exceeded',
            ]);
            $this->skipped++;
            return;
        }

        // Check for loop before analyzing
        $loopInfo = AILoopDetector::detectLoop(
            $analysis->error_signature,
            $analysis->application,
            $analysis->kyte_account
        );

        if ($loopInfo) {
            $this->log("Loop detected for analysis {$analysis->id}: {$loopInfo['message']}");

            // Disable auto-fix
            AILoopDetector::disableAutoFix($analysis->application, $analysis->kyte_account, $loopInfo);

            // Mark analysis as completed but don't analyze
            $analysis->save([
                'analysis_status' => 'completed',
                'analysis_stage' => 'completed',
                'processing_completed_at' => time(),
                'ai_diagnosis' => "Loop detected: {$loopInfo['message']}. Auto-fix disabled.",
            ]);

            $this->skipped++;
            return;
        }

        // Run AI analysis
        $analyzer = new AIErrorAnalyzer($this->api);
        $result = $analyzer->analyze($analysis);

        if ($result['success']) {
            $analysis->save([
                'analysis_status' => 'completed',
            ]);

            $this->processed++;

            // If auto-fix enabled and confidence high enough, apply fix
            if ($result['should_auto_fix']) {
                $this->log("Auto-fixing analysis {$analysis->id} (confidence: {$analysis->fix_confidence})");

                $applier = new AIErrorFixApplier($this->api);
                $applied = $applier->apply($analysis, null); // null = auto-applied

                if ($applied) {
                    $this->autoFixed++;
                    $this->log("Auto-fix applied successfully for analysis {$analysis->id}");

                    // Update config stats
                    $config->save([
                        'total_successful_fixes' => $config->total_successful_fixes + 1,
                    ]);
                } else {
                    $this->logError("Auto-fix failed for analysis {$analysis->id}");
                    $config->save([
                        'total_failed_fixes' => $config->total_failed_fixes + 1,
                    ]);
                }
            }
        } else {
            $this->failed++;
            $analysis->save([
                'analysis_status' => 'failed',
                'retry_count' => $analysis->retry_count + 1,
            ]);

            // If retries exhausted, mark as permanently failed
            if ($analysis->retry_count >= 3) {
                $this->log("Analysis {$analysis->id} failed after {$analysis->retry_count} retries");
            } else {
                // Reset to queued for retry
                $analysis->save([
                    'analysis_status' => 'queued',
                    'processing_started_at' => null,
                ]);
            }
        }
    }

    /**
     * Check rate limits for application
     *
     * @param ModelObject $config Configuration object
     * @return bool True if within limits
     */
    private function checkRateLimits($config) {
        // Check hourly limit
        $hourAgo = time() - 3600;
        $hourlyCount = $this->getAnalysisCount($config->application, $config->kyte_account, $hourAgo);

        if ($hourlyCount >= $config->max_analyses_per_hour) {
            return false;
        }

        // Check daily limit
        $dayAgo = time() - 86400;
        $dailyCount = $this->getAnalysisCount($config->application, $config->kyte_account, $dayAgo);

        if ($dailyCount >= $config->max_analyses_per_day) {
            return false;
        }

        // Check monthly cost budget
        $monthAgo = time() - (30 * 86400);
        $monthlyCost = $this->getTotalCost($config->application, $config->kyte_account, $monthAgo);

        if ($monthlyCost >= $config->max_monthly_cost_usd) {
            return false;
        }

        return true;
    }

    /**
     * Get analysis count since timestamp
     *
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @param int $since Unix timestamp
     * @return int Count
     */
    private function getAnalysisCount($appId, $accountId, $since) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('application', $appId, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'date_created', 'value' => $since, 'operator' => '>='],
        ]);

        return $model->count();
    }

    /**
     * Get total cost since timestamp
     *
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @param int $since Unix timestamp
     * @return float Total cost in USD
     */
    private function getTotalCost($appId, $accountId, $since) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('application', $appId, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'date_created', 'value' => $since, 'operator' => '>='],
        ]);

        $total = 0.0;
        foreach ($model->objects as $analysis) {
            if ($analysis->estimated_cost_usd) {
                $total += floatval($analysis->estimated_cost_usd);
            }
        }

        return $total;
    }
}
