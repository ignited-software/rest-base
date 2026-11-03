<?php

namespace Ignited\RestBase\Http;

use Ignited\RestBase\OpenApi;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** The OpenAPI document (the committed file when there is one, else built from the registry) and the Swagger UI page. */
class OpenApiController
{
    public function spec(OpenApi $openApi): Response
    {
        $file = config('rest-base.openapi.file');
        $json = is_string($file) && is_file($file) ? (string) file_get_contents($file) : $openApi->json();

        return response($json, 200, ['Content-Type' => 'application/json']);
    }

    public function ui(): View
    {
        return view('rest-base::swagger', [
            'title' => (string) config('rest-base.docs.title', config('rest-base.openapi.title', 'API')),
            'specUrl' => route(config('rest-base.route_name', 'rest-base.').'openapi'),
            'cdn' => (array) config('rest-base.docs.swagger_ui'),
        ]);
    }
}
