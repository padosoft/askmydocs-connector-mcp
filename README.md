# askmydocs-connector-mcp

Product-level MCP connections for AskMyDocs.

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

The connector runtime is operational behind
`MCP_CONNECTOR_ENABLED=false` by default:

- public, encrypted Bearer and OAuth Authorization Code + PKCE connections;
- shared tenant/project connections and owner-scoped personal connections;
- protected-resource, authorization-server/OIDC and CIMD/DCR discovery;
- refresh-token rotation under a database lock and single-use callback state;
- automatic MCP modern/legacy negotiation through `askmydocs-mcp-pack`;
- paginated and item-bounded tool discovery, deterministic local names and
  risk-based policy;
- live tool calls, write confirmation, MRTR continuation and task recognition;
- capped LLM text plus private artifacts and signed references for binary media;
- governed resource catalogues and bounded, redacted resource ingest;
- remote Task polling, cancellation, recovery and retention;
- sandboxed MCP Apps with CSP, origin checks and a constrained host bridge;
- admin, Connected Apps, OAuth callback and conversation-interaction APIs.

The package still does not implement the scheduled source `ConnectorInterface`:
resource ingest is an explicit, governed action instead of an automatically
scheduled connector sync. Advanced MCP Apps host features remain separately
feature-gated through `MCP_CONNECTOR_APP_ADVANCED_ENABLED=false` so a deployment
can first validate the basic sandbox renderer.

## Data model

```text
McpServerDefinition
  ├── McpOAuthClient
  └── McpConnection (shared/personal + optional project)
        ├── McpCredential (encrypted access/refresh token)
        ├── McpConnectionTool (catalogue + local policy)
        └── McpConnectionResource (catalogue + ingest policy)

McpOAuthAttempt (single-use state + encrypted PKCE verifier)
McpPendingInteraction (single-use encrypted confirmation/MRTR continuation)
McpRemoteTask (poll/cancel/recovery state)
McpAppInstance (short-lived sandbox grant and encrypted host context)
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

### Optional agent capability hints

An MCP server may add a compact advisory routing hint under
`_meta["askmydocs/agent-capability"]`. The connector validates and exposes only
the following fields to hosts: `entity`, `operation`, `intent_tags`, `requires`,
`produces`, `collection_path`, `identity_fields` and `next_tools`. Supported
operations are `search`, `list`, `get`, `detail`, `summary`, `count` and `check`.

Hints are optional and never override authentication, tenant/project scope,
risk, read-only annotations or confirmation policy. Standard MCP tools without
this extension remain fully supported through host-side schema inference.

## Local development

The sibling `askmydocs-mcp-pack` repository is resolved through a Composer path
repository as `2.0.x-dev`:

```bash
composer install
composer test
composer analyse
composer format -- --test
```

Before publishing this package, replace the local path-repository development
override with the released `padosoft/askmydocs-mcp-pack:^2.0` dependency.

## HTTP surfaces

- shared administration: `/api/admin/connectors/mcp/*`;
- personal Connected Apps: `/api/me/connected-apps/mcp/*`;
- callback: `/api/connectors/mcp/oauth/callback`;
- CIMD document: `/.well-known/mcp-client.json`;
- confirmation/MRTR resume: `/api/conversations/mcp/interactions/{interaction}`.
- task status/cancel/input: `/api/conversations/mcp/tasks/*`;
- MCP Apps resolve, sandbox and bridge: `/api/conversations/mcp/apps/*` and
  `/mcp-apps/sandbox`.

All product routes are feature-gated and authenticated except the feature-gated
CIMD document. Personal owner identity is always taken from the authenticated
session, never from request input. Connection creation and discovery are
rate-limited, and each owner has a configurable personal connection quota.

Connection creation accepts an explicit `auth_method` of `oauth`, `bearer` or
`none`. OAuth creation stores a pending connection and returns a server-generated
`next_action.authorization_url`; clients must navigate to that URL instead of
handling authorization codes or tokens themselves. The callback uses PKCE,
single-use state bound to the authenticated owner, tenant, issuer and MCP
resource, then stores the resulting Bearer/refresh tokens only in encrypted
server-side credentials. Existing clients that omit `auth_method` remain
compatible: a supplied Bearer token selects `bearer`, otherwise `none`.

The principal resource controls can be tuned with:

```dotenv
MCP_CONNECTOR_OAUTH_ENABLED=true
MCP_CONNECTOR_OAUTH_ALLOW_INSECURE_LOCAL=false
MCP_CONNECTOR_MAX_PERSONAL_CONNECTIONS=10
MCP_CONNECTOR_DISCOVERY_RATE_LIMIT=10
MCP_CONNECTOR_MAX_CATALOG_ITEMS=1000
```

`MCP_CONNECTOR_OAUTH_ALLOW_INSECURE_LOCAL` is intended only for loopback
development and test providers. Production authorization, token and dynamic
registration endpoints must use HTTPS. Hosts may provide pre-registered clients
through `connector-mcp.oauth.clients`, keyed by the exact issuer; these take
priority over CIMD and dynamic client registration.

Setting `MCP_CONNECTOR_MAX_PERSONAL_CONNECTIONS=0` disables the per-owner
connection quota. The discovery rate is measured per authenticated user, with
an IP fallback, by Laravel's standard throttle middleware.

## Security posture

Personal endpoints require public HTTPS. DNS A/AAAA answers and every outbound
OAuth request/redirect are checked against private, loopback, link-local,
reserved and metadata addresses. Public DNS answers are pinned into the cURL
connection to prevent rebinding between validation and connect. Redirects are
revalidated and capped, credentials are removed on origin changes, and response
bodies are written through a hard-size-limited stream. Shared internal hosts
require an explicit admin allowlist. Tokens, PKCE verifiers, client secrets,
legacy headers and pending continuations use Laravel encrypted casts and are
never returned by the API.

## License

Apache-2.0.
