<?php
namespace Kyte\Mcp\Session;

use Mcp\Server\Session\Session;

/**
 * Session subclass that fixes an upstream mcp/sdk v0.4.x bug.
 *
 * `Mcp\Server\Session\Session::save()` accesses the typed property
 * `$this->data` directly:
 *
 *   public function save(): bool
 *   {
 *       return $this->store->write($this->id, json_encode($this->data, ...));
 *   }
 *
 * `$data` is a typed array property with no default value, so when no
 * `set()` / `clear()` / `readData()` call has hydrated it yet, accessing
 * it raises a fatal "must not be accessed before initialization" error.
 * That happens in practice on every tool/call request that doesn't write
 * to the session — which is most of them. The fatal kills the FPM worker
 * mid-request; the response gets dropped and the client sees an HTTP 202
 * with an empty body.
 *
 * The fix is one line: route through `readData()` (which lazy-inits to
 * the persisted store value, or to `[]` on miss). All other methods on
 * the parent class already do this; `save()` is the lone outlier.
 *
 * Filed upstream — once it lands and we bump `mcp/sdk`, this whole
 * subclass + `SaveSafeSessionFactory` can go away. Until then we wire
 * the subclass via `Builder::setSession(..., new SaveSafeSessionFactory())`
 * in `Endpoint::process` so customers get the fix automatically without
 * any vendor patching.
 */
final class SaveSafeSession extends Session
{
    public function save(): bool
    {
        return $this->getStore()->write(
            $this->getId(),
            json_encode($this->all(), \JSON_THROW_ON_ERROR)
        );
    }
}
