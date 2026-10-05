<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class OpenApiController extends Controller
{
    public function spec(): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $paths = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!Str::startsWith($uri, 'api/v1/')) continue;
            if ($uri === 'api/v1/tools/openapi.json') continue;

            $path = '/'.Str::after($uri, 'api/v1/');
            $middleware = $route->gatherMiddleware();
            $auth = collect($middleware)->contains(fn ($m) => Str::contains((string) $m, ['auth', 'role:', 'can:']))
                || Str::startsWith($path, '/mobile-pos/');
            if ($path === '/mobile-pos/login') $auth = false;

            $action = $route->getActionName();
            $controller = Str::contains($action, '@') ? Str::before($action, '@') : null;
            $handler = Str::contains($action, '@') ? Str::after($action, '@') : null;
            $group = Str::startsWith($path, '/mobile-pos/') ? 'Mobile POS' : Str::headline(explode('/', trim($path, '/'))[0] ?? 'API');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $lower = strtolower($method);
                $summary = $route->getName()
                    ? Str::headline(str_replace(['.', '-'], ' ', $route->getName()))
                    : Str::headline(($handler ?: $method).' '.trim($path, '/'));

                $operation = [
                    'summary' => $summary,
                    'tags' => [$group],
                    'x-route-name' => $route->getName(),
                    'x-controller' => $controller,
                    'x-action' => $handler,
                    'responses' => ['200' => ['description' => 'Successful response']],
                ];
                if ($auth) $operation['security'] = [['bearerAuth' => []]];
                $paths[$path][$lower] = $operation;
            }
        }

        ksort($paths);
        return response()->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Bypass Grill API',
                'version' => '1.1.0',
                'description' => 'Live API documentation generated from the registered Laravel API routes.',
            ],
            'servers' => [['url' => url('/api/v1')]],
            'paths' => $paths,
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ]);
    }
}
