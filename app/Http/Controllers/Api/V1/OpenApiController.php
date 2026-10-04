<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    public function spec(): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
        return response()->json([
            'openapi'=>'3.0.3',
            'info'=>['title'=>'Bypass Grill API','version'=>'1.0.0','description'=>'Internal API documentation and Android POS integration endpoints.'],
            'servers'=>[['url'=>url('/api/v1')]],
            'paths'=>[
                '/mobile-pos/login'=>['post'=>['summary'=>'Android POS login','tags'=>['Mobile POS'],'requestBody'=>['required'=>true,'content'=>['application/json'=>['schema'=>['type'=>'object','required'=>['email','password'],'properties'=>['email'=>['type'=>'string','format'=>'email'],'password'=>['type'=>'string'],'device_name'=>['type'=>'string']]]]]],'responses'=>['200'=>['description'=>'Authenticated'],'422'=>['description'=>'Invalid credentials']]]],
                '/mobile-pos/bootstrap'=>['get'=>['summary'=>'Download POS catalog','tags'=>['Mobile POS'],'security'=>[['bearerAuth'=>[]]],'responses'=>['200'=>['description'=>'Catalog'],'401'=>['description'=>'Unauthorized']]]],
                '/mobile-pos/sync'=>['post'=>['summary'=>'Sync offline Android orders','tags'=>['Mobile POS'],'security'=>[['bearerAuth'=>[]]],'responses'=>['200'=>['description'=>'Sync results'],'401'=>['description'=>'Unauthorized'],'422'=>['description'=>'Validation error']]]],
                '/products'=>['get'=>['summary'=>'List products','tags'=>['Catalog'],'responses'=>['200'=>['description'=>'Products']]]],
                '/categories'=>['get'=>['summary'=>'List categories','tags'=>['Catalog'],'responses'=>['200'=>['description'=>'Categories']]]],
                '/orders'=>['get'=>['summary'=>'List orders','tags'=>['Orders'],'responses'=>['200'=>['description'=>'Orders']]],'post'=>['summary'=>'Create order','tags'=>['Orders'],'responses'=>['201'=>['description'=>'Created']]]],
            ],
            'components'=>['securitySchemes'=>['bearerAuth'=>['type'=>'http','scheme'=>'bearer']]],
        ]);
    }
}
