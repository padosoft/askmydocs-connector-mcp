<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class TestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
