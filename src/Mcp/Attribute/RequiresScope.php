<?php
namespace Kyte\Mcp\Attribute;

/**
 * Declares the MCP token scope required to invoke a tool.
 *
 * Pairs with mcp/sdk's #[McpTool]. ScopedCallToolHandler reads this
 * attribute via reflection on every tool/call dispatch and rejects calls
 * whose token does not carry the named scope.
 *
 * Tools without #[RequiresScope] are rejected too — the dispatcher
 * fails closed. Every Kyte MCP tool must declare its scope explicitly,
 * otherwise it is unreachable. Rationale: design doc R2 names scopes as
 * the primary mitigation for the bearer-token theft window, so an
 * accidental default-allow on a freshly-added tool would silently widen
 * that surface. Better that an undeclared tool produce a loud refusal in
 * dev than a quiet privilege escalation in production.
 *
 * Scope vocabulary tracks the design doc 5.4 token model: 'read',
 * 'draft', 'commit'. Scopes are not hierarchical — a 'commit'-scoped
 * token does NOT implicitly carry 'draft' or 'read'. Tokens are issued
 * with explicit scope sets in Shipyard, so the dispatch check is a
 * straight set-membership test.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequiresScope
{
    public function __construct(public readonly string $scope)
    {
    }
}
