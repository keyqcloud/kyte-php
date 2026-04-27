<?php
namespace Kyte\Mcp\Session;

use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

/**
 * Factory that constructs SaveSafeSession instances instead of the SDK's
 * built-in Session. Wired into the Builder via setSession(...) in
 * Endpoint::process. See SaveSafeSession docblock for why we need this.
 *
 * Mirrors the SDK's bundled SessionFactory exactly except for the class
 * being instantiated — single-purpose shim.
 */
final class SaveSafeSessionFactory implements SessionFactoryInterface
{
    public function create(SessionStoreInterface $store): SessionInterface
    {
        return new SaveSafeSession($store, new UuidV4());
    }

    public function createWithId(Uuid $id, SessionStoreInterface $store): SessionInterface
    {
        return new SaveSafeSession($store, $id);
    }
}
