<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMcp\Contracts;

use Illuminate\Http\Client\Response;

interface SafeHttpClientContract
{
    /** @param array<string,string> $headers */
    public function get(string $url, array $headers = [], bool $personal = true): Response;

    /**
     * @param  array<string,mixed>  $form
     * @param  array<string,string>  $headers
     */
    public function postForm(string $url, array $form, array $headers = [], bool $personal = true): Response;

    /**
     * @param  array<string,mixed>  $json
     * @param  array<string,string>  $headers
     */
    public function postJson(string $url, array $json, array $headers = [], bool $personal = true): Response;
}
