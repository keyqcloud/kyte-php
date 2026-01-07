<?php

namespace Kyte\AI;

use Kyte\Core\Model;
use Kyte\Core\ModelObject;

/**
 * AI Function Matcher
 *
 * Identifies which Function record contains the code that needs fixing
 * by matching function signatures from AI-suggested fixes against stored functions.
 *
 * Handles cases where:
 * - Function names in DB don't match actual function names
 * - Multiple functions are stored in one Function record ("helper functions")
 * - Action overrides (get, new, update, delete) with empty names
 *
 * @package Kyte\AI
 */
class AIFunctionMatcher
{
    /**
     * Find the Function record that contains the code matching the AI fix
     *
     * @param string $suggestedFix AI-generated function code
     * @param int $controllerId Controller ID
     * @return array|null ['function_id' => int, 'function_name' => string, 'function_type' => string] or null
     */
    public static function findMatchingFunction($suggestedFix, $controllerId)
    {
        // Parse function signature from AI fix
        $signature = self::parseFunctionSignature($suggestedFix);
        if (!$signature) {
            error_log("AIFunctionMatcher: Could not parse function signature from suggested fix");
            return null;
        }

        error_log("AIFunctionMatcher: Looking for function with signature: {$signature['full']}");

        // Get all Function records for this controller
        $functionModel = new Model(constant("Function"));
        $functionModel->retrieve('controller', $controllerId, false, [
            ['field' => 'deleted', 'value' => 0]
        ], false);

        if ($functionModel->count() === 0) {
            error_log("AIFunctionMatcher: No functions found for controller {$controllerId}");
            return null;
        }

        error_log("AIFunctionMatcher: Searching through {$functionModel->count()} Function records");

        // Search through all Function records
        foreach ($functionModel->objects as $functionRecord) {
            if (empty($functionRecord->code)) {
                continue;
            }

            // Decompress function code
            $code = bzdecompress($functionRecord->code);
            if ($code === false) {
                error_log("AIFunctionMatcher: Failed to decompress code for Function ID {$functionRecord->id}");
                continue;
            }

            // Check if this Function record contains the matching signature
            if (self::codeContainsSignature($code, $signature)) {
                error_log("AIFunctionMatcher: Match found in Function ID {$functionRecord->id} (name: '{$functionRecord->name}', type: {$functionRecord->type})");
                return [
                    'function_id' => $functionRecord->id,
                    'function_name' => $signature['name'], // Use actual function name from code, not DB name
                    'function_type' => $functionRecord->type,
                ];
            }
        }

        error_log("AIFunctionMatcher: No matching Function record found for signature: {$signature['full']}");
        return null;
    }

    /**
     * Parse function signature from PHP code
     *
     * Extracts: visibility, function name, parameters
     *
     * @param string $code PHP function code
     * @return array|null ['full' => string, 'name' => string, 'params' => string, 'visibility' => string]
     */
    public static function parseFunctionSignature($code)
    {
        // Match function signature
        // Handles: public/protected/private/static function name($params)
        $pattern = '/\b(public|protected|private)?\s*(static)?\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/';

        if (preg_match($pattern, $code, $matches)) {
            $visibility = trim(($matches[1] ?? '') . ' ' . ($matches[2] ?? ''));
            $name = $matches[3];
            $params = $matches[4];

            // Normalize parameters (remove defaults, type hints for matching)
            $normalizedParams = self::normalizeParameters($params);

            return [
                'full' => trim("$visibility function $name($normalizedParams)"),
                'name' => $name,
                'params' => $normalizedParams,
                'visibility' => $visibility ?: 'public',
            ];
        }

        return null;
    }

    /**
     * Normalize function parameters for matching
     *
     * Removes default values, type hints to allow flexible matching
     *
     * @param string $params Parameter string
     * @return string Normalized parameters
     */
    private static function normalizeParameters($params)
    {
        // Split by comma
        $parts = array_map('trim', explode(',', $params));
        $normalized = [];

        foreach ($parts as $part) {
            if (empty($part)) continue;

            // Remove default value (= something)
            $part = preg_replace('/\s*=\s*[^,]+$/', '', $part);

            // Extract just variable name (remove type hints)
            if (preg_match('/(\$[a-zA-Z_][a-zA-Z0-9_]*)/', $part, $matches)) {
                $normalized[] = $matches[1];
            }
        }

        return implode(', ', $normalized);
    }

    /**
     * Check if code contains a matching function signature
     *
     * @param string $code PHP code (potentially containing multiple functions)
     * @param array $signature Signature to match
     * @return bool True if signature found
     */
    private static function codeContainsSignature($code, $signature)
    {
        // Look for exact function name first
        $functionName = $signature['name'];
        $params = $signature['params'];

        // Build flexible pattern that matches the function signature
        // Allow for different whitespace, visibility keywords
        $escapedName = preg_quote($functionName, '/');
        $escapedParams = preg_quote($params, '/');

        // Pattern: (visibility)? function functionName(params)
        $pattern = '/\b(public|protected|private)?\s*(static)?\s*function\s+' . $escapedName . '\s*\(\s*' . $escapedParams . '\s*\)/';

        return preg_match($pattern, $code) === 1;
    }

    /**
     * Replace function code within a Function record's code
     *
     * Handles cases where one Function record contains multiple functions.
     * Only replaces the specific function that matches the signature.
     *
     * @param string $existingCode Current code in Function record
     * @param string $newFunctionCode New function code from AI
     * @param array $signature Function signature to replace
     * @return string Updated code
     */
    public static function replaceFunctionInCode($existingCode, $newFunctionCode, $signature)
    {
        $functionName = $signature['name'];
        error_log("AIFunctionMatcher: Replacing function '{$functionName}' in code");

        // Pattern to match the entire function (including body)
        // Matches from signature to closing brace at same indentation level
        $escapedName = preg_quote($functionName, '/');

        // Match the function signature and capture everything until we find the matching closing brace
        $pattern = '/(\s*)(public|protected|private)?\s*(static)?\s*function\s+' . $escapedName . '\s*\([^)]*\)\s*\{/';

        if (preg_match($pattern, $existingCode, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1];
            $indent = $matches[1][0];

            // Find the matching closing brace
            $braceCount = 1;
            $pos = $startPos + strlen($matches[0][0]);
            $codeLength = strlen($existingCode);

            while ($pos < $codeLength && $braceCount > 0) {
                $char = $existingCode[$pos];
                if ($char === '{') $braceCount++;
                if ($char === '}') $braceCount--;
                $pos++;
            }

            if ($braceCount === 0) {
                // Found matching closing brace
                $endPos = $pos;

                // Replace the old function with new function
                $before = substr($existingCode, 0, $startPos);
                $after = substr($existingCode, $endPos);

                // Ensure new function has same indentation
                $indentedNewFunction = self::indentCode($newFunctionCode, $indent);

                $newCode = $before . $indentedNewFunction . $after;

                error_log("AIFunctionMatcher: Successfully replaced function '{$functionName}'");
                return $newCode;
            }
        }

        error_log("AIFunctionMatcher: Could not find function '{$functionName}' to replace, returning new function only");
        // If we can't find/replace, return just the new function
        // This handles the case where the Function record only contains this one function
        return $newFunctionCode;
    }

    /**
     * Add indentation to code
     *
     * @param string $code Code to indent
     * @param string $indent Indentation string (spaces or tabs)
     * @return string Indented code
     */
    private static function indentCode($code, $indent)
    {
        if (empty($indent)) return $code;

        $lines = explode("\n", $code);
        $indentedLines = array_map(function($line) use ($indent) {
            return empty(trim($line)) ? $line : $indent . $line;
        }, $lines);

        return implode("\n", $indentedLines);
    }
}
