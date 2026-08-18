<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMcpConnectorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('connector-mcp.enabled', false), 404);

        return $next($request);
    }
}
