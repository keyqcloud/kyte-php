<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Core\SensitivityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three-tier SensitivityPolicy service.
 *
 * Matrix:
 *   - Controller.sensitive true  → isControllerSensitive() returns true
 *   - Controller.sensitive false → isControllerSensitive() returns false
 *   - foreign-account controller name collision → returns false (account scoped)
 *   - DataModel.sensitive true   → isModelSensitive() returns true
 *   - ModelAttribute.sensitive   → getSensitiveFields() returns the flagged
 *                                  field names only; non-sensitive fields excluded
 *   - shouldDropPayload OR-semantic: controller-true ⇒ true regardless of model
 *   - shouldDropPayload OR-semantic: model-true ⇒ true regardless of controller
 *   - redactFields() replaces sensitive keys (case-insensitive) and recurses
 *   - redactFields() with model=null is a no-op (virtual controller case)
 *   - Per-request cache: second lookup doesn't re-hit DB
 *   - Permissive fallback: undefined constants / null args return false / []
 */
class SensitivityPolicyTest extends TestCase
{
    private const OWN_ACCOUNT   = 'sens-policy-own';
    private const OTHER_ACCOUNT = 'sens-policy-other';

    private Api $api;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $sensitiveModelId;
    private int $plainModelId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        // Instantiating Api defines the global model-name constants
        // (Controller, DataModel, ModelAttribute, etc.).
        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(Controller);
        \Kyte\Core\DBI::createTable(DataModel);
        \Kyte\Core\DBI::createTable(ModelAttribute);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Controller` WHERE name LIKE 'SensTestCtrl%'");
        \Kyte\Core\DBI::query("DELETE FROM `DataModel` WHERE name LIKE 'SensTestModel%'");
        \Kyte\Core\DBI::query("DELETE FROM `ModelAttribute` WHERE name LIKE 'sens_test_%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT, 'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->createController('SensTestCtrlSensitive', $this->ownAccountId, 1);
        $this->createController('SensTestCtrlPlain',     $this->ownAccountId, 0);
        // Same controller name under a different account — must not leak across.
        $this->createController('SensTestCtrlSensitive', $this->otherAccountId, 0);

        $this->sensitiveModelId = $this->createDataModel('SensTestModelSensitive', $this->ownAccountId, 1);
        $this->plainModelId     = $this->createDataModel('SensTestModelPlain',     $this->ownAccountId, 0);

        // Two sensitive fields and one plain field on the plain model.
        $this->createAttribute('sens_test_alpha',    $this->plainModelId, $this->ownAccountId, 1);
        $this->createAttribute('sens_test_beta',    $this->plainModelId, $this->ownAccountId, 1);
        $this->createAttribute('sens_test_locale', $this->plainModelId, $this->ownAccountId, 0);

        SensitivityPolicy::resetForTests();
    }

    public function testControllerSensitiveFlagDetected(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertTrue($policy->isControllerSensitive('SensTestCtrlSensitive', $this->ownAccountId));
    }

    public function testControllerWithoutFlagIsNotSensitive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertFalse($policy->isControllerSensitive('SensTestCtrlPlain', $this->ownAccountId));
    }

    public function testForeignAccountControllerWithSameNameDoesNotLeak(): void
    {
        $policy = SensitivityPolicy::getInstance();
        // Same name 'SensTestCtrlSensitive' exists in the other account with flag=0.
        // Querying the other account must return false even though the own account's
        // row has flag=1.
        $this->assertFalse($policy->isControllerSensitive('SensTestCtrlSensitive', $this->otherAccountId));
    }

    public function testModelSensitiveFlagDetected(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertTrue($policy->isModelSensitive('SensTestModelSensitive', $this->ownAccountId));
    }

    public function testModelWithoutFlagIsNotSensitive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertFalse($policy->isModelSensitive('SensTestModelPlain', $this->ownAccountId));
    }

    public function testSensitiveFieldsReturnedForModel(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $fields = $policy->getSensitiveFields('SensTestModelPlain', $this->ownAccountId);
        sort($fields);
        $this->assertSame(['sens_test_alpha', 'sens_test_beta'], $fields);
    }

    public function testShouldDropPayloadTrueWhenControllerSensitive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertTrue($policy->shouldDropPayload('SensTestCtrlSensitive', null, $this->ownAccountId),
            'Controller-sensitive alone must drop payload, even with no model context (virtual controller case)');
    }

    public function testShouldDropPayloadTrueWhenModelSensitive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertTrue($policy->shouldDropPayload(null, 'SensTestModelSensitive', $this->ownAccountId));
    }

    public function testShouldDropPayloadFalseWhenNeitherFlagged(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertFalse($policy->shouldDropPayload('SensTestCtrlPlain', 'SensTestModelPlain', $this->ownAccountId));
    }

    public function testRedactFieldsReplacesSensitiveKeysCaseInsensitive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $payload = [
            'name'             => 'Example Name',
            'SENS_TEST_ALPHA'  => 'redact-me-1',  // upper-case key, still redacted
            'sens_test_beta'   => 'redact-me-2',
            'sens_test_locale' => 'en-US',
            'note'             => 'non-sensitive note',
        ];
        $out = $policy->redactFields($payload, 'SensTestModelPlain', $this->ownAccountId);

        $this->assertSame('[REDACTED]', $out['SENS_TEST_ALPHA']);
        $this->assertSame('[REDACTED]', $out['sens_test_beta']);
        $this->assertSame('en-US', $out['sens_test_locale']);
        $this->assertSame('Example Name', $out['name']);
        $this->assertSame('non-sensitive note', $out['note']);
    }

    public function testRedactFieldsRecursesIntoNestedArrays(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $payload = [
            'wrapper' => [
                'sens_test_alpha' => 'redact-me-nested',
                'inner_ok'        => 'keep',
            ],
        ];
        $out = $policy->redactFields($payload, 'SensTestModelPlain', $this->ownAccountId);
        $this->assertSame('[REDACTED]', $out['wrapper']['sens_test_alpha']);
        $this->assertSame('keep', $out['wrapper']['inner_ok']);
    }

    public function testRedactFieldsNoOpWhenModelIsNull(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $payload = ['sens_test_alpha' => 'leak'];
        // model=null reflects the no-model-context case — the caller is
        // responsible for invoking shouldDropPayload() at the controller tier
        // first. redactFields can't know which fields are sensitive without
        // a model to look up.
        $this->assertSame($payload, $policy->redactFields($payload, null, $this->ownAccountId));
    }

    public function testNullArgsReturnPermissive(): void
    {
        $policy = SensitivityPolicy::getInstance();
        $this->assertFalse($policy->isControllerSensitive(null, $this->ownAccountId));
        $this->assertFalse($policy->isControllerSensitive('SensTestCtrlSensitive', null));
        $this->assertFalse($policy->isModelSensitive(null, $this->ownAccountId));
        $this->assertSame([], $policy->getSensitiveFields(null, $this->ownAccountId));
    }

    public function testPerRequestCacheIsHonored(): void
    {
        $policy = SensitivityPolicy::getInstance();
        // First call populates the cache.
        $this->assertTrue($policy->isControllerSensitive('SensTestCtrlSensitive', $this->ownAccountId));

        // Mutate the row out from under the policy. If the cache is honored
        // the second call still returns true; if it weren't, this would flip.
        \Kyte\Core\DBI::query(
            "UPDATE `Controller` SET `sensitive` = 0 WHERE name = 'SensTestCtrlSensitive' AND kyte_account = " . (int)$this->ownAccountId
        );

        $this->assertTrue(
            $policy->isControllerSensitive('SensTestCtrlSensitive', $this->ownAccountId),
            'Cache must hold the prior value for the rest of the request'
        );
    }

    private function createAccount(string $number, string $name): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteAccount);
        $obj->create(['number' => $number, 'name' => $name]);
        return (int)$obj->id;
    }

    private function createController(string $name, int $accountId, int $sensitive): int
    {
        $obj = new \Kyte\Core\ModelObject(Controller);
        $obj->create([
            'name'         => $name,
            'sensitive'    => $sensitive,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createDataModel(string $name, int $accountId, int $sensitive): int
    {
        $obj = new \Kyte\Core\ModelObject(DataModel);
        $obj->create([
            'name'         => $name,
            'sensitive'    => $sensitive,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createAttribute(string $name, int $dataModelId, int $accountId, int $sensitive): int
    {
        $obj = new \Kyte\Core\ModelObject(ModelAttribute);
        $obj->create([
            'name'         => $name,
            'type'         => 's',
            'dataModel'    => $dataModelId,
            'sensitive'    => $sensitive,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }
}
