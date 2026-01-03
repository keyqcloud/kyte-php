<?php

namespace Kyte\Cron;

use Kyte\Core\DBI;
use Kyte\Core\Model;

/**
 * CronJobCodeGenerator
 *
 * Assembles complete cron job PHP class code from individual function bodies.
 * Similar to how Controller classes are assembled from Function records.
 *
 * Security Model:
 * - Users write only method bodies (execute, setUp, tearDown)
 * - System wraps them in a secure class structure
 * - Prevents malicious class definitions, constructors, custom properties
 * - Each method body is validated before storage
 */
class CronJobCodeGenerator
{
    /**
     * Generate complete class code from job functions
     *
     * @param int $cronJobId The cron job ID
     * @param string $className Optional custom class name (default: CronJob_{id})
     * @return string Complete PHP class code (not wrapped in <?php tags)
     */
    public static function generateClassCode(int $cronJobId, string $className = null): string
    {
        if ($className === null) {
            $className = "CronJob_{$cronJobId}";
        }

        // Load all functions for this job
        $functions = Model::all('CronJobFunction', [
            ['field' => 'cron_job', 'value' => $cronJobId],
            ['field' => 'deleted', 'value' => 0]
        ]);

        // Build function code map
        $functionBodies = [
            'execute' => null,
            'setUp' => null,
            'tearDown' => null
        ];

        foreach ($functions as $function) {
            $functionName = $function->name;

            // Get current content
            if ($function->content_hash) {
                $contentSql = "SELECT content FROM CronJobFunctionContent WHERE content_hash = ?";
                $contentResult = DBI::prepared_query($contentSql, 's', [$function->content_hash]);

                if (!empty($contentResult)) {
                    $compressedContent = $contentResult[0]['content'];
                    $decompressed = bzdecompress($compressedContent);

                    if ($decompressed !== false) {
                        $functionBodies[$functionName] = $decompressed;
                    }
                }
            }
        }

        // Generate class code
        $code = self::assembleClass($className, $functionBodies);

        return $code;
    }

    /**
     * Assemble the complete class from function bodies
     *
     * @param string $className The class name
     * @param array $functionBodies Map of function names to bodies
     * @return string Complete class code
     */
    private static function assembleClass(string $className, array $functionBodies): string
    {
        $executeBody = $functionBodies['execute'] ?? self::getDefaultExecuteBody();
        $setUpBody = $functionBodies['setUp'] ?? self::getDefaultSetUpBody();
        $tearDownBody = $functionBodies['tearDown'] ?? self::getDefaultTearDownBody();

        // Indent function bodies (add 2 levels of indentation)
        $executeBody = self::indentCode($executeBody, 2);
        $setUpBody = self::indentCode($setUpBody, 2);
        $tearDownBody = self::indentCode($tearDownBody, 2);

        $code = "class {$className} extends \\Kyte\\Core\\CronJobBase
{
    /**
     * Main execution method
     *
     * This method is called when the cron job runs.
     * Return value will be stored as execution output.
     *
     * @return mixed Execution result
     * @throws \\Exception Any exception will be caught and logged as an error
     */
    public function execute()
    {
{$executeBody}
    }

    /**
     * Optional: Setup before execution
     *
     * Use this to initialize resources, connect to external APIs, etc.
     */
    public function setUp()
    {
{$setUpBody}
    }

    /**
     * Optional: Cleanup after execution
     *
     * This runs even if execute() throws an exception.
     * Use this to close connections, release resources, etc.
     */
    public function tearDown()
    {
{$tearDownBody}
    }
}
";

        return $code;
    }

    /**
     * Indent code by adding spaces
     *
     * @param string $code The code to indent
     * @param int $levels Number of indentation levels (1 level = 4 spaces)
     * @return string Indented code
     */
    private static function indentCode(string $code, int $levels): string
    {
        if (empty(trim($code))) {
            return '';
        }

        $indent = str_repeat('    ', $levels);
        $lines = explode("\n", $code);
        $indentedLines = array_map(function($line) use ($indent) {
            // Don't add indent to empty lines
            if (trim($line) === '') {
                return '';
            }
            return $indent . $line;
        }, $lines);

        return implode("\n", $indentedLines);
    }

    /**
     * Get default execute() body
     */
    private static function getDefaultExecuteBody(): string
    {
        return '$this->log("Job started");

// Add your job logic here

$this->log("Job completed");
return "Success";';
    }

    /**
     * Get default setUp() body
     */
    private static function getDefaultSetUpBody(): string
    {
        return '// Initialize resources here (optional)';
    }

    /**
     * Get default tearDown() body
     */
    private static function getDefaultTearDownBody(): string
    {
        return '// Cleanup resources here (optional)';
    }

    /**
     * Validate function body code
     *
     * @param string $code The function body code
     * @param string $functionName The function name (execute, setUp, tearDown)
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateFunctionBody(string $code, string $functionName): array
    {
        // Check for dangerous patterns
        if (preg_match('/class\s+\w+/i', $code)) {
            return [
                'valid' => false,
                'error' => 'Function body cannot contain class definitions'
            ];
        }

        if (preg_match('/namespace\s+/i', $code)) {
            return [
                'valid' => false,
                'error' => 'Function body cannot contain namespace declarations'
            ];
        }

        if (preg_match('/(public|private|protected)\s+function\s+\w+/i', $code)) {
            return [
                'valid' => false,
                'error' => 'Function body cannot contain method definitions'
            ];
        }

        // Wrap code in a test function for syntax checking
        $testCode = "<?php\nclass TestCronJob extends \\Kyte\\Core\\CronJobBase {\n";
        $testCode .= "    public function {$functionName}() {\n";
        $testCode .= "        " . str_replace("\n", "\n        ", $code) . "\n";
        $testCode .= "    }\n";
        $testCode .= "}\n";

        // PHP syntax check
        $tempFile = tempnam(sys_get_temp_dir(), 'cron_validate_');
        file_put_contents($tempFile, $testCode);

        $output = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $return);

        unlink($tempFile);

        if ($return !== 0) {
            return [
                'valid' => false,
                'error' => 'PHP syntax error: ' . implode("\n", $output)
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Regenerate and update the full class code for a cron job
     *
     * @param int $cronJobId The cron job ID
     * @return bool Success
     */
    public static function regenerateJobCode(int $cronJobId): bool
    {
        $code = self::generateClassCode($cronJobId);
        $compressed = bzcompress($code, 9);

        // Update CronJob.code field
        $sql = "UPDATE CronJob SET code = ? WHERE id = ?";
        DBI::prepared_query($sql, 'si', [$compressed, $cronJobId]);

        return DBI::affected_rows() > 0;
    }
}
