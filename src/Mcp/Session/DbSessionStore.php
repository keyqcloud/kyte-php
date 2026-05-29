<?php
namespace Kyte\Mcp\Session;

use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Database-backed MCP session store — the cross-instance replacement for the
 * SDK's bundled FileSessionStore.
 *
 * The file store keys sessions to files under sys_get_temp_dir(), which is
 * local to one host. Behind a load balancer the `initialize` request and its
 * follow-ups land on different instances, so the follow-up can't find the
 * session and the MCP connection collapses to a 404 / OAuth-discovery fallback.
 * This store persists sessions in the `KyteMCPSession` table, so every
 * instance sharing the database resolves the same session regardless of which
 * box served `initialize`. See KyteMCPSession model docblock + design doc §11.
 *
 * Contract (Mcp\Server\Session\SessionStoreInterface):
 *   - read/write/exists/destroy operate on a single Uuid.
 *   - gc() removes everything past the TTL and returns the deleted ids.
 *
 * TTL semantics deliberately mirror FileSessionStore exactly:
 *   - `last_activity` is the last *write* time. The SDK calls session->save()
 *     at the end of every handled request (Protocol::handleMessage), so an
 *     active session's timestamp slides forward — this is an idle timeout.
 *   - exists() reports expiry but does NOT delete (matches the file store).
 *   - read() deletes the row on expiry (matches the file store unlink-on-read).
 *
 * Tenancy:
 *   - read/write/exists/destroy are scoped to the account that owns the /mcp
 *     request (resolved from the bearer token before this store is built), so
 *     a session can only ever be resolved under the account that created it.
 *   - gc() is intentionally NOT account-scoped: it only ever removes rows that
 *     are already past their TTL (ephemeral, payload never read), and a global
 *     sweep keeps the table tidy on a shared/SaaS database no matter which
 *     tenant's request happens to trigger the ~1%-probability collection.
 *
 * Rows are hard-deleted (purge), not soft-deleted: these are throwaway protocol
 * sessions, and leaving `deleted=1` tombstones would defeat the UNIQUE index on
 * `session_id` when a UUID is (astronomically rarely) reused.
 */
final class DbSessionStore implements SessionStoreInterface
{
    /** Default idle TTL in seconds; matches the SDK FileSessionStore default. */
    public const DEFAULT_TTL = 3600;

    /** Safety cap on rows purged per gc() sweep so a single request never stalls. */
    private const GC_BATCH_LIMIT = 1000;

    public function __construct(
        private int $kyteAccount,
        private int $ttl = self::DEFAULT_TTL,
    ) {
        if ($this->ttl <= 0) {
            $this->ttl = self::DEFAULT_TTL;
        }
    }

    public function exists(Uuid $id): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }

        return !$this->isExpired($row);
    }

    public function read(Uuid $id): string|false
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }

        if ($this->isExpired($row)) {
            // Mirror FileSessionStore: expired-on-read is purged, then miss.
            try {
                $row->purge();
            } catch (\Throwable $e) {
                error_log('DbSessionStore::read - failed to purge expired session - ' . $e->getMessage());
            }
            return false;
        }

        return (string)$row->payload;
    }

    public function write(Uuid $id, string $data): bool
    {
        $now = time();

        try {
            $row = $this->find($id);
            if ($row !== null) {
                return $row->save([
                    'payload'       => $data,
                    'last_activity' => $now,
                ]);
            }

            $row = new ModelObject(KyteMCPSession);
            return $row->create([
                'session_id'    => $id->toRfc4122(),
                'payload'       => $data,
                'last_activity' => $now,
                'kyte_account'  => $this->kyteAccount,
            ]);
        } catch (\Throwable $e) {
            // A concurrent create on the same UUID (UNIQUE violation) is the
            // only realistic failure here; re-resolve and update so the write
            // still lands. Anything else is logged and reported as a miss.
            try {
                $row = $this->find($id);
                if ($row !== null) {
                    return $row->save([
                        'payload'       => $data,
                        'last_activity' => $now,
                    ]);
                }
            } catch (\Throwable $inner) {
                $e = $inner;
            }
            error_log('DbSessionStore::write - ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(Uuid $id): bool
    {
        try {
            $row = $this->find($id);
            if ($row !== null) {
                $row->purge();
            }
            return true;
        } catch (\Throwable $e) {
            error_log('DbSessionStore::destroy - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove sessions whose last activity is older than the TTL. Global (not
     * account-scoped) by design — see class docblock. Returns the purged ids.
     *
     * @return Uuid[]
     */
    public function gc(): array
    {
        $cutoff = time() - $this->ttl;
        $deleted = [];

        try {
            $model = new Model(KyteMCPSession);
            $model->retrieve(
                null,
                null,
                false,
                [['field' => 'last_activity', 'operator' => '<', 'value' => $cutoff]],
                false,
                null,
                self::GC_BATCH_LIMIT
            );

            foreach ($model->objects as $row) {
                $sid = (string)$row->session_id;
                try {
                    $row->purge();
                } catch (\Throwable $e) {
                    error_log('DbSessionStore::gc - failed to purge ' . $sid . ' - ' . $e->getMessage());
                    continue;
                }
                try {
                    $deleted[] = Uuid::fromString($sid);
                } catch (\Throwable) {
                    // non-UUID session_id should never happen; skip silently
                }
            }
        } catch (\Throwable $e) {
            error_log('DbSessionStore::gc - ' . $e->getMessage());
        }

        return $deleted;
    }

    /**
     * Resolve the session row for this account, or null on miss. Scoping the
     * lookup to kyte_account keeps sessions isolated per tenant even though
     * session_id is globally unique.
     */
    private function find(Uuid $id): ?ModelObject
    {
        $row = new ModelObject(KyteMCPSession);
        $found = $row->retrieve(
            'session_id',
            $id->toRfc4122(),
            [['field' => 'kyte_account', 'value' => $this->kyteAccount]]
        );

        return $found ? $row : null;
    }

    private function isExpired(ModelObject $row): bool
    {
        return (time() - (int)$row->last_activity) > $this->ttl;
    }
}
