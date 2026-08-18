<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesActor
{
    private function actor(Request $request): Model
    {
        $actor = $request->user();
        abort_unless($actor instanceof Model, 401);

        return $actor;
    }
}
