<?php
namespace Kyte\Test;

use Kyte\Mcp\Session\SaveSafeSession;
use Kyte\Mcp\Session\SaveSafeSessionFactory;
use Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

/**
 * Verifies the SaveSafeSession workaround for the upstream mcp/sdk
 * Session::save() initialization-order bug.
 *
 * Reproduces the failure mode by constructing a session and immediately
 * calling save() with no prior set()/clear()/all() — the SDK's parent
 * class fatals here. SaveSafeSession should succeed and persist an
 * empty record.
 */
class SaveSafeSessionTest extends TestCase
{
    public function testSaveAfterConstructionDoesNotFatal(): void
    {
        $store = new InMemorySessionStore(3600);
        $session = new SaveSafeSession($store, new UuidV4());

        // The bug-exposing call: no set(), no all(), straight to save().
        // The parent Session::save() would raise:
        //   Error: Typed property Mcp\Server\Session\Session::$data must
        //   not be accessed before initialization
        $result = $session->save();

        $this->assertTrue($result);
        $this->assertTrue($store->exists($session->getId()));

        // Persisted payload should be the empty record (since nothing
        // was set), not null/false.
        $loaded = $store->read($session->getId());
        $this->assertSame('[]', $loaded);
    }

    public function testSavePreservesSetValues(): void
    {
        $store = new InMemorySessionStore(3600);
        $session = new SaveSafeSession($store, new UuidV4());

        $session->set('initialized', true);
        $session->set('client_info.name', 'phpunit');

        $this->assertTrue($session->save());

        $payload = json_decode($store->read($session->getId()), true);
        $this->assertSame(true, $payload['initialized']);
        $this->assertSame('phpunit', $payload['client_info']['name']);
    }

    public function testFactoryProducesSaveSafeSession(): void
    {
        $factory = new SaveSafeSessionFactory();
        $store = new InMemorySessionStore(3600);

        $created = $factory->create($store);
        $this->assertInstanceOf(SaveSafeSession::class, $created);

        $id = new UuidV4();
        $rebuilt = $factory->createWithId($id, $store);
        $this->assertInstanceOf(SaveSafeSession::class, $rebuilt);
        $this->assertSame($id->toRfc4122(), $rebuilt->getId()->toRfc4122());
    }
}
