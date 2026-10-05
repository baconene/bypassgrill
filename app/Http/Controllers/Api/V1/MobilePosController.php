<?php
namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryTransactionType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\PaymentTender;
use App\Models\Product;
use App\Models\PrintServiceSetting;
use App\Services\DepositReconciliation;
use App\Services\DepositSnapshot;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobilePosController extends Controller
{
    public function login(Request $request): JsonResponse {
        $data=$request->validate(['email'=>'required|email','password'=>'required|string']);
        $user=\App\Models\User::where('email',$data['email'])->first();
        abort_unless($user&&Hash::check($data['password'],$user->password),422,'Invalid credentials.');
        $token=Str::random(80);
        DB::table('mobile_pos_tokens')->updateOrInsert(['user_id'=>$user->id],['token_hash'=>hash('sha256',$token),'device_name'=>$request->input('device_name'),'last_used_at'=>now(),'updated_at'=>now(),'created_at'=>now()]);
        return response()->json(['token'=>$token,'user'=>$this->userPayload($user)]);
    }

    public function bootstrap(Request $request): JsonResponse {
        $user=$this->authenticate($request);
        $products=Product::with(['category','modifiers','recipes.ingredient'])->where('is_active',true)->orderBy('display_order')->get()->map(function(Product $p){$row=$p->toArray();$row['image_url']=$p->image?asset('storage/'.$p->image):null;return array_merge($row,$p->stockStatus());});
        return response()->json(['server_time'=>now()->toIso8601String(),'user'=>$this->userPayload($user),'categories'=>Category::where('is_active',true)->orderBy('display_order')->get(),'products'=>$products,'payment_tenders'=>PaymentTender::where('is_active',true)->orderBy('display_order')->orderBy('name')->get(),'print_settings'=>PrintServiceSetting::getSetting(),'orders'=>Order::with(['items.product','payments.tender'])->latest()->limit(100)->get()]);
    }

    public function sync(Request $request,OrderService $orders): JsonResponse {
        $user=$this->authenticate($request);
        $data=$request->validate(['orders'=>'array|max:100','orders.*.client_id'=>'required|string|max:100','orders.*.order_type'=>'nullable|string|max:50','orders.*.table_number'=>'nullable|string|max:50','orders.*.customer_name'=>'nullable|string|max:255','orders.*.customer_contact'=>'nullable|string|max:255','orders.*.customer_address'=>'nullable|string|max:500','orders.*.notes'=>'nullable|string|max:500','orders.*.discount_amount'=>'nullable|numeric|min:0','orders.*.items'=>'required|array|min:1','orders.*.items.*.product_id'=>'required|integer|exists:products,id','orders.*.items.*.quantity'=>'required|integer|min:1','orders.*.items.*.modifiers'=>'nullable|array']);
        $results=[];foreach($data['orders']??[] as $payload){$existing=DB::table('mobile_pos_sync_records')->where('client_id',$payload['client_id'])->first();if($existing){$results[]=['client_id'=>$payload['client_id'],'order_id'=>$existing->order_id,'status'=>'already_synced'];continue;}try{$order=DB::transaction(function()use($orders,$payload,$user){$d=$payload;unset($d['client_id']);$d['user_id']=$user->id;$order=$orders->createOrder($d);DB::table('mobile_pos_sync_records')->insert(['client_id'=>$payload['client_id'],'order_id'=>$order->id,'user_id'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);return $order;});$results[]=['client_id'=>$payload['client_id'],'order_id'=>$order->id,'status'=>'synced'];}catch(\Throwable $e){report($e);$results[]=['client_id'=>$payload['client_id'],'status'=>'error','message'=>$e->getMessage()];}}
        return response()->json(['server_time'=>now()->toIso8601String(),'orders'=>$results]);
    }

    public function paymentStore(Request $request): JsonResponse {
        $user=$this->authenticate($request);
        $data=$request->validate(['client_id'=>'required|string|max:100','order_id'=>'required|integer|exists:orders,id','payment_tender_id'=>'required|integer|exists:payment_tenders,id','amount'=>'required|numeric|min:0','reference'=>'nullable|string|max:255']);
        $existing=DB::table('mobile_pos_payment_sync_records')->where('client_id',$data['client_id'])->first();
        if($existing)return response()->json(['payment_id'=>$existing->payment_id,'status'=>'already_synced']);
        $payment=DB::transaction(function()use($data,$user){$payload=$data;unset($payload['client_id']);$order=\App\Models\Order::findOrFail($payload['order_id']);$payment=app(\App\Services\PaymentService::class)->processPayment($order,$payload);DB::table('mobile_pos_payment_sync_records')->insert(['client_id'=>$data['client_id'],'payment_id'=>$payment->id,'order_id'=>$order->id,'user_id'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);return $payment;});
        return response()->json(['payment_id'=>$payment->id,'status'=>'synced'],201);
    }



    public function orderReconcile(Request $request): JsonResponse {
        $this->authenticate($request);
        $data=$request->validate(['server_ids'=>'required|array|max:500','server_ids.*'=>'integer']);
        $ids=array_values(array_unique(array_map('intval',$data['server_ids'])));
        return response()->json(['existing_ids'=>Order::whereIn('id',$ids)->pluck('id')->map(fn($id)=>(string)$id)->values()]);
    }

    public function orderUpdate(Request $request,int $id): JsonResponse {
        $this->authenticate($request);$order=Order::findOrFail($id);return app(OrderController::class)->update($order,$request);
    }
    public function orderCancel(Request $request,int $id): JsonResponse {
        $this->authenticate($request);$order=Order::findOrFail($id);return app(OrderController::class)->cancel($order);
    }

    public function userInfo(Request $request): JsonResponse { return response()->json($this->userPayload($this->authenticate($request))); }

    public function depositIndex(Request $request): JsonResponse {
        $this->authenticate($request);
        return app(DepositControlController::class)->index();
    }
    public function depositStore(Request $request, DepositSnapshot $snapshots): JsonResponse {
        $this->authenticate($request);
        return app(DepositControlController::class)->store($request,$snapshots);
    }
    public function depositClose(Request $request,int $id,DepositSnapshot $snapshots): JsonResponse {
        $this->authenticate($request);
        return app(DepositControlController::class)->close($request,DepositControl::findOrFail($id),$snapshots);
    }
    public function depositReconcile(Request $request,int $id,DepositReconciliation $reconciliation): JsonResponse {
        $this->authenticate($request);
        return app(DepositControlController::class)->reconcile($request,DepositControl::findOrFail($id),$reconciliation);
    }

    public function inventoryIndex(Request $request): JsonResponse {
        $this->authenticate($request);
        return app(InventoryController::class)->index();
    }
    public function inventoryAdjust(Request $request,InventoryService $inventory): JsonResponse {
        $user=$this->authenticate($request);
        abort_unless($user->can('manage inventory'),403,'Unauthorized');
        $data=$request->validate(['ingredient_id'=>'required|integer|exists:ingredients,id','quantity'=>'required|numeric|min:0','unit_cost'=>'nullable|numeric|min:0','type'=>'required|in:stock_in,stock_out,adjustment,waste','reference'=>'nullable|string|max:100','notes'=>'nullable|string|max:500']);
        $ingredient=Ingredient::findOrFail($data['ingredient_id']);
        $tx=$inventory->recordTransaction($ingredient,(float)$data['quantity'],InventoryTransactionType::from($data['type']),$data['reference']??null,$data['notes']??null,unitCost:isset($data['unit_cost'])?(float)$data['unit_cost']:null);
        return response()->json(['transaction'=>$tx,'ingredient'=>$ingredient->fresh()],201);
    }

    public function financialIndex(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->index($request); }
    public function financialSummary(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->summary($request); }
    public function financialStore(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->store($request); }

    public function report(Request $request,string $type): JsonResponse {
        $this->authenticate($request);
        $controller=app(ReportController::class);
        return match($type){
            'daily-sales'=>$controller->dailySales(),
            'monthly-sales'=>$controller->monthlySales(),
            'product-sales'=>$controller->productSales(),
            'profit-loss'=>$controller->profitLoss(),
            'heatmap'=>$controller->heatmap(),
            'analytics'=>$controller->analytics(),
            'serving-time'=>$controller->servingTime(),
            default=>abort(404,'Unknown report.')
        };
    }

    private function authenticate(Request $request) {
        $plain=$request->bearerToken();abort_unless($plain,401,'Mobile POS token required.');
        $row=DB::table('mobile_pos_tokens')->where('token_hash',hash('sha256',$plain))->first();abort_unless($row,401,'Invalid mobile POS token.');
        DB::table('mobile_pos_tokens')->where('id',$row->id)->update(['last_used_at'=>now()]);
        $user=\App\Models\User::findOrFail($row->user_id);auth()->setUser($user);$request->setUserResolver(fn()=>$user);return $user;
    }
    private function userPayload($user): array { return ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'roles'=>$user->getRoleNames()->values(),'permissions'=>$user->getAllPermissions()->pluck('name')->values()]; }
}
