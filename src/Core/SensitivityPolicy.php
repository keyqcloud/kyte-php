<?php

namespace Kyte\Core;

/**
 * Single source of truth for the Phase 2.5 "sensitive" flag system.
 *
 * Three tiers, evaluated at log-write time:
 *
 *   1. Controller.sensitive        Blanket flag. When set, the controller's
 *                                  request body and response payload are
 *                                  dropped from activity and error logs
 *                                  entirely. This is the primary fix for
 *                                  virtual (no-model) pass-through controllers
 *                                  whose body content is regulated data.
 *
 *   2. DataModel.sensitive         Blanket flag at the model level. Same
 *                                  treatment when the model is the target
 *                                  of the request.
 *
 *   3. ModelAttribute.sensitive    Per-field flag. The field name is
 *                                  redacted in log payloads; other fields
 *                                  are kept. Distinct from the existing
 *                                  ModelAttribute.protected which blanks
 *                                  values in GET responses only — set both
 *                                  if you want both behaviors.
 *
 * Either of the first two tiers being true means the whole payload is
 * dropped from logs (shouldDropPayload). The third tier is a per-field
 * redaction layer applied when the first two are false (redactFields).
 *
 * Consumers:
 *   - ActivityLogger::log()           drops body/response or redacts fields
 *   - ErrorHandler::handleException() drops data/response or redacts
 *   - MCP read tools                  refuse or redact source per scope
 *   - AIErrorContextBuilder           skips sensitive-origin rows entirely
 *
 * The flag governs log/MCP/AI exposure only. Runtime API responses are
 * unaffected — a sensitive controller still returns its normal response
 * to the caller; we just don't store the body.
 *
 * Failure mode: any lookup failure is logged via error_log() and falls
 * back to permissive (return false). ActivityLogger's existing
 * SENSITIVE_FIELDS hardcoded list still runs as a baseline, so a
 * transient DB hiccup degrades to current behavior rather than to no
 * redaction at all.
 *
 * Caching: per-request singleton, in-memory keyed by (scope, name,
 * account). Exactly one DB hit per (scope, name, account) tuple per
 * request.
 */
final class SensitivityPolicy
{
    private static ?SensitivityPolicy $instance = null;

    /** @var array<string, bool> */
    private array $controllerCache = [];

    /** @var array<string, bool> */
    private array $modelCache = [];

    /** @var array<string, array<int, string>> */
    private array $fieldsCache = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drop the in-process cache. Test-only — production code should not
     * call this; the singleton is per-request and the request boundary
     * is the natural reset.
     */
    public static function resetForTests(): void
    {
        self::$instance = null;
    }

    /**
     * Is this controller flagged sensitive?
     *
     * Returns false for null/empty controller name, null account id,
     * a controller that doesn't exist or belongs to a different account,
     * or any DB lookup failure. The fail-permissive default is deliberate:
     * the hardcoded SENSITIVE_FIELDS baseline in ActivityLogger still
     * runs, so degraded mode matches current behavior.
     */
    public function isControllerSensitive(?string $controllerName, ?int $accountId): bool
    {
        if (!$controllerName || $accountId === null) {
            return false;
        }
        $key = $controllerName . '|' . $accountId;
        if (\array_key_exists($key, $this->controllerCache)) {
            return $this->controllerCache[$key];
        }
        if (!\defined('Controller')) {
            return $this->controllerCache[$key] = false;
        }
        try {
            $c = new ModelObject(\Controller);
            if ($c->retrieve('name', $controllerName) && (int)$c->kyte_account === $accountId) {
                return $this->controllerCache[$key] = ((int)($c->sensitive ?? 0) === 1);
            }
            return $this->controllerCache[$key] = false;
        } catch (\Throwable $e) {
            error_log('SensitivityPolicy: controller lookup failed for ' . $controllerName . ' - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Is this data model flagged sensitive?
     *
     * Same failure semantics as isControllerSensitive.
     */
    public function isModelSensitive(?string $modelName, ?int $accountId): bool
    {
        if (!$modelName || $accountId === null) {
            return false;
        }
        $key = $modelName . '|' . $accountId;
        if (\array_key_exists($key, $this->modelCache)) {
            return $this->modelCache[$key];
        }
        if (!\defined('DataModel')) {
            return $this->modelCache[$key] = false;
        }
        try {
            $dm = new ModelObject(\DataModel);
            if ($dm->retrieve('name', $modelName) && (int)$dm->kyte_account === $accountId) {
                return $this->modelCache[$key] = ((int)($dm->sensitive ?? 0) === 1);
            }
            return $this->modelCache[$key] = false;
        } catch (\Throwable $e) {
            error_log('SensitivityPolicy: model lookup failed for ' . $modelName . ' - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Return the set of sensitive field names for a model.
     *
     * Empty array when nothing is flagged, when the model is unknown,
     * or when the lookup fails. Names are returned as stored (the
     * redactFields() caller does case-insensitive matching).
     *
     * @return array<int, string>
     */
    public function getSensitiveFields(?string $modelName, ?int $accountId): array
    {
        if (!$modelName || $accountId === null) {
            return [];
        }
        $key = $modelName . '|' . $accountId;
        if (\array_key_exists($key, $this->fieldsCache)) {
            return $this->fieldsCache[$key];
        }
        if (!\defined('DataModel') || !\defined('ModelAttribute')) {
            return $this->fieldsCache[$key] = [];
        }
        try {
            $dm = new ModelObject(\DataModel);
            if (!$dm->retrieve('name', $modelName) || (int)$dm->kyte_account !== $accountId) {
                return $this->fieldsCache[$key] = [];
            }
            $attrs = new Model(\ModelAttribute);
            $attrs->retrieve('dataModel', (int)$dm->id, false, [
                ['field' => 'kyte_account', 'value' => $accountId],
                ['field' => 'sensitive', 'value' => 1],
            ]);
            $fields = [];
            foreach ($attrs->objects as $a) {
                if (!empty($a->name)) {
                    $fields[] = (string)$a->name;
                }
            }
            return $this->fieldsCache[$key] = $fields;
        } catch (\Throwable $e) {
            error_log('SensitivityPolicy: field lookup failed for ' . $modelName . ' - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Should the entire request body / response payload be dropped from logs?
     *
     * True if either the controller or the model is blanket-sensitive.
     * Field-level redaction is a separate concern handled by redactFields().
     */
    public function shouldDropPayload(?string $controllerName, ?string $modelName, ?int $accountId): bool
    {
        return $this->isControllerSensitive($controllerName, $accountId)
            || $this->isModelSensitive($modelName, $accountId);
    }

    /**
     * Apply per-field redaction to a payload using ModelAttribute.sensitive flags.
     *
     * Sensitive field names get replaced with '[REDACTED]'. Other values
     * pass through. Nested arrays recurse under the same model context
     * (no attempt is made to switch model context on FK boundaries — the
     * caller knows the top-level model).
     *
     * When the model is unknown (virtual controllers) this is a no-op;
     * the caller should have invoked shouldDropPayload() first for that
     * tier of protection.
     */
    public function redactFields(mixed $data, ?string $modelName, ?int $accountId): mixed
    {
        if (!\is_array($data) || $modelName === null) {
            return $data;
        }
        $sensitive = $this->getSensitiveFields($modelName, $accountId);
        if (empty($sensitive)) {
            return $data;
        }
        $sensitiveLower = \array_map('strtolower', $sensitive);
        $out = [];
        foreach ($data as $k => $v) {
            if (\is_string($k) && \in_array(\strtolower($k), $sensitiveLower, true)) {
                $out[$k] = '[REDACTED]';
            } elseif (\is_array($v)) {
                $out[$k] = $this->redactFields($v, $modelName, $accountId);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
