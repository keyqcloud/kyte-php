-- =========================================================================
-- Kyte v4.6.1 - widen KyteMCPSession.payload TEXT -> LONGTEXT (regression fix)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Fixes a regression introduced in v4.6.0. The DB-backed MCP session store
-- (DbSessionStore) defined `payload` as TEXT (64KB max). The streamable-HTTP
-- SDK persists its outgoing-message queue (`_mcp.outgoing_queue`) — i.e. full
-- JSON-RPC tool *responses* — inside the session payload between request
-- handling and delivery. A single large read (e.g. `read_page` on a 300KB+
-- page) produces a ~800KB response that overflows the 64KB column; MySQL
-- silently truncates it at 65535 bytes, corrupting the stored JSON. The next
-- read of that session does `json_decode` on the truncated string and throws
-- "Control character error, possibly incorrectly encoded", which surfaces to
-- the MCP client as a 400 and breaks the tool call.
--
-- The FileSessionStore that DbSessionStore replaced wrote to files with no
-- size cap, so large responses worked. LONGTEXT (4GB) restores that parity.
--
-- Also purges any already-corrupt/truncated sessions so stale rows don't keep
-- failing on resume — MCP sessions are ephemeral, clients simply re-initialize.
--
-- Safe to re-run. See src/Mvc/Model/KyteMCPSession.php.
-- =========================================================================

ALTER TABLE `KyteMCPSession` MODIFY `payload` LONGTEXT NOT NULL;

-- Clear any sessions truncated under the old TEXT column (corrupt JSON).
DELETE FROM `KyteMCPSession` WHERE LENGTH(`payload`) >= 65535;
