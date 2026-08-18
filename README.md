# askmydocs-connector-mcp

Per-user MCP account connections for AskMyDocs.

This package is the product/account layer above
[`padosoft/askmydocs-mcp-pack`](../askmydocs-mcp-pack). It does not reimplement
the MCP protocol and it does not turn arbitrary HTTP routes into tools. Its job
is to connect an AskMyDocs user to an approved remote MCP server, keep that
user's OAuth credentials encrypted, discover the tools available to that
identity, and apply tenant/project/tool policy before the host exposes them to
an LLM.

## Package boundary

| Package | Owns |
|---|---|
| `askmydocs-mcp-pack` v2 | Dual-era MCP wire protocol, Streamable HTTP, historical HTTP+SSE and normalized results |
| `askmydocs-connector-mcp` | Server catalogue, shared/personal connections, OAuth, SSRF guard, tool discovery/policy/execution and artifacts |
| `askmydocs-connector-api` | Arbitrary HTTP endpoint configuration and endpoint-to-tool compilation |
| AskMyDocs host | Unified tool catalogue, chat UX, host RBAC and human confirmation |

`askmydocs-connector-mcp` depends on `askmydocs-mcp-pack`; it deliberately does
not depend on `askmydocs-connector-api`.

## Current status

The connection + live-tools slice is operational behind
`MCP_CONNECTOR_ENABLED=false` by default:

- public, encrypted Bearer and OAuth Authorization Code + PKCE connections;
- shared tenant/project connections and owner-scoped personal connections;
- protected-resource, authorization-server/OIDC and CIMD/DCR discovery;
- refresh-token rotation under a database lock and single-use callback state;
- automatic MCP modern/legacy negotiation through `askmydocs-mcp-pack`;
- paginated tool discovery, deterministic local names and risk-based policy;
- live tool calls, write confirmation, MRTR continuation and task recognition;
- capped LLM text plus private artifacts and signed references for binary media;
- admin, Connected Apps, OAuth callback and conversation-interaction APIs.

Resource ingest, task polling/cancellation and interactive MCP Apps rendering
remain later slices. This package intentionally does not implement the ingest
`ConnectorInterface` yet.

## Data model

```text
McpServerDefinition
  ├── McpOAuthClient
  └── McpConnection (shared/personal + optional project)
        ├── McpCredential (encrypted access/refresh token)
        └── McpConnectionTool (catalogue + local policy)

McpOAuthAttempt (single-use state + encrypted PKCE verifier)
McpPendingInteraction (single-use encrypted confirmation/MRTR continuation)
```

Tenant server definitions are administrator-approved infrastructure. A personal
definition and connection are owned through `owner_type` + `owner_id`, so the
package does not import AskMyDocs's `User` class and remains reusable.

The effective tools for a chat will be the intersection of:

```text
tools returned for the user's OAuth identity
∩ server administrator allow-list
∩ user's enabled tools
∩ project binding
∩ host RBAC / confirmation policy
```

Discovered annotations are persisted but remain untrusted input. Unknown or
write-like tools default to disabled and confirmation-required.

## Local development

The sibling `askmydocs-mcp-pack` repository is resolved through a Composer path
repository as `2.0.x-dev`:

```bash
composer install
composer test
composer analyse
```

Before publishing this package, replace the local path-repository development
override with the released `padosoft/askmydocs-mcp-pack:^2.0` dependency.

## HTTP surfaces

- shared administration: `/api/admin/connectors/mcp/*`;
- personal Connected Apps: `/api/me/connected-apps/mcp/*`;
- callback: `/api/connectors/mcp/oauth/callback`;
- CIMD document: `/.well-known/mcp-client.json`;
- confirmation/MRTR resume: `/api/conversations/mcp/interactions/{interaction}`.

All product routes are feature-gated and authenticated except the feature-gated
CIMD document. Personal owner identity is always taken from the authenticated
session, never from request input.

## Security posture

Personal endpoints require public HTTPS. DNS A/AAAA answers and every outbound
OAuth request/redirect are checked against private, loopback, link-local,
reserved and metadata addresses. Redirects are capped and credentials are
removed on origin changes. Shared internal hosts require an explicit admin
allowlist. Tokens, PKCE verifiers, client secrets, legacy headers and pending
continuations use Laravel encrypted casts and are never returned by the API.

## License

Apache-2.0.
