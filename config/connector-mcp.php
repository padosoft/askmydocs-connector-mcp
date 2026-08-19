<?php

declare(strict_types=1);

return [
    /*
    | The package is fail-closed until the host explicitly enables it and wires
    | the OAuth + MCP v2 runtime adapters.
    */
    'enabled' => (bool) env('MCP_CONNECTOR_ENABLED', false),

    /*
    | Per-user OAuth is supported only for remote HTTP MCP servers. Local stdio
    | processes belong to the lower-level mcp-pack and are intentionally not an
    | account connector transport.
    */
    'allowed_transports' => ['auto', 'streamable_http', 'legacy_sse', 'stdio_imported'],

    'http' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
        'max_redirects' => 3,
        'max_response_bytes' => 2_000_000,
        'max_catalog_pages' => 20,
        'internal_endpoint_allowlist' => [],
    ],

    'ingest' => [
        'max_resources_per_sync' => (int) env('MCP_CONNECTOR_MAX_RESOURCES_PER_SYNC', 500),
        'max_resource_bytes' => (int) env('MCP_CONNECTOR_MAX_RESOURCE_BYTES', 10_000_000),
        'max_sync_bytes' => (int) env('MCP_CONNECTOR_MAX_SYNC_BYTES', 100_000_000),
    ],

    'oauth' => [
        'callback_path' => env('MCP_CONNECTOR_OAUTH_CALLBACK_PATH', '/api/connectors/mcp/oauth/callback'),
        'state_ttl_seconds' => (int) env('MCP_CONNECTOR_OAUTH_STATE_TTL', 600),
        'pkce' => true,
        'client_metadata_path' => '/.well-known/mcp-client.json',
        'client_metadata_url' => env('MCP_CONNECTOR_CLIENT_METADATA_URL'),
        'client_name' => env('APP_NAME', 'AskMyDocs'),
        'client_uri' => env('APP_URL'),
        'default_scopes' => [],
    ],

    'tool_policy' => [
        'default_enabled' => false,
        'confirmation_for_unknown_writes' => true,
    ],

    'pending_interaction_ttl_seconds' => 900,
    'llm_text_limit' => 24_000,

    'routes' => [
        'middleware' => ['api', 'auth'],
        'admin_ability' => 'manage-connectors',
    ],
];
