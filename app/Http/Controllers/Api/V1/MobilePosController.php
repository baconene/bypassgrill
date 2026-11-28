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
use App\Models\Reservation;
use App\Models\PrintServiceSetting;
use App\Services\DepositReconciliation;
use App\Services\DepositSnapshot;
use App\Services\InventoryService;
use App\Services\PosCheckoutService;
use App\Services\PrintJobService;
use App\Services\SalesDayInsights;
use App\Services\StoreWeather;
use Carbon\CarbonImmutable;
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
        return response()->json(['server_time'=>now()->toIso8601String(),'user'=>$this->userPayload($user),'categories'=>Category::where('is_active',true)->orderBy('display_order')->get(),'products'=>$products,'payment_tenders'=>PaymentTender::where('is_active',true)->orderBy('display_order')->orderBy('name')->get(),'print_settings'=>PrintServiceSetting::getSetting(),'realtime'=>$this->realtimeConfig()]);
    }

    public function sync(Request $request, PosCheckoutService $checkout): JsonResponse {
        $this->authenticate($request);
        $data=$request->validate(['orders'=>'array|max:100','orders.*.client_id'=>'required|string|max:100','orders.*.order_type'=>'nullable|string|max:50','orders.*.table_number'=>'nullable|string|max:50','orders.*.customer_name'=>'nullable|string|max:255','orders.*.customer_contact'=>'nullable|string|max:255','orders.*.customer_address'=>'nullable|string|max:500','orders.*.notes'=>'nullable|string|max:500','orders.*.discount_amount'=>'nullable|numeric|min:0','orders.*.offline_number'=>'nullable|string|max:20','orders.*.items'=>'required|array|min:1','orders.*.items.*.product_id'=>'required|integer|exists:products,id','orders.*.items.*.quantity'=>'required|integer|min:1','orders.*.items.*.modifiers'=>'nullable|array']);
        $results = [];
        foreach ($data['orders'] ?? [] as $payload) {
            $clientId = $payload['client_id'];
            $offlineNumber = $payload['offline_number'] ?? null;
            unset($payload['client_id'], $payload['offline_number']);
            try {
                $order = $checkout->createOrder($payload, $clientId, $offlineNumber);
                $results[] = [
                    'client_id' => $clientId,
                    'order_id' => $order->id,
                    'status' => $order->wasRecentlyCreated ? 'synced' : 'already_synced',
                    'public_token' => $order->public_token,
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['client_id' => $clientId, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return response()->json(['server_time'=>now()->toIso8601String(),'orders'=>$results]);
    }

    public function paymentStore(Request $request, PosCheckoutService $checkout): JsonResponse {
        $this->authenticate($request);
        $data = $request->validate([
            'client_id' => 'required|string|max:100',
            'order_id' => 'required|integer|exists:orders,id',
            'payment_tender_id' => 'required|integer|exists:payment_tenders,id',
            'amount' => 'required|numeric|min:0',
            'reference' => 'nullable|string|max:255',
        ]);

        $payment = $checkout->collectPayment(Order::findOrFail($data['order_id']), $data, $data['client_id']);

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->wasRecentlyCreated ? 'synced' : 'already_synced',
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }




    public function orderIndex(Request $request): JsonResponse {
        $this->authenticate($request);$perPage=min(max((int)$request->input('per_page',100),1),100);$search=trim((string)$request->input('search',''));
        $q=Order::with(['items.product','payments.tender','queueNumber'])->orderByDesc('id');
        if($request->filled('from'))$q->where('created_at','>=',$request->input('from'));
        if($request->filled('to'))$q->where('created_at','<=',$request->input('to'));
        if($search!=='')$q->where(function($w)use($search){$w->where('id',$search)->orWhere('customer_name','like','%'.$search.'%')->orWhere('customer_contact','like','%'.$search.'%')->orWhere('table_number','like','%'.$search.'%')->orWhereHas('items.product',fn($p)=>$p->where('name','like','%'.$search.'%'))->orWhereHas('queueNumber',fn($n)=>$n->where('number','like','%'.$search.'%'));});
        if($request->filled('status'))$q->where('status',$request->string('status'));if($request->filled('payment')){$payment=strtolower((string)$request->input('payment'));if($payment==='paid')$q->whereHas('payments',fn($p)=>$p->where('status','paid'));if($payment==='unpaid')$q->whereDoesntHave('payments',fn($p)=>$p->where('status','paid'));}if($request->filled('product_id')){$productId=(int)$request->input('product_id');$q->whereHas('items',fn($i)=>$i->where('product_id',$productId));}
        $page=$q->cursorPaginate($perPage,['*'],'cursor',$request->input('cursor'));
        return response()->json(['data'=>$page->items(),'next_cursor'=>$page->nextCursor()?->encode(),'has_more'=>$page->hasMorePages()]);
    }

    public function orderReconcile(Request $request): JsonResponse {
        $this->authenticate($request);
        $data=$request->validate(['server_ids'=>'required|array|max:500','server_ids.*'=>'integer']);
        $ids=array_values(array_unique(array_map('intval',$data['server_ids'])));
        return response()->json(['existing_ids'=>Order::whereIn('id',$ids)->pluck('id')->map(fn($id)=>(string)$id)->values()]);
    }

    public function orderUpdate(Request $request,int $id): JsonResponse {
        $this->authenticate($request);$order=Order::findOrFail($id);abort_if($order->status==='cancelled',422,'A cancelled order cannot be edited.');abort_if($order->payment_status==='paid',422,'A paid order cannot be edited.');return app(OrderController::class)->update($order,$request);
    }
    public function orderCancel(Request $request,int $id): JsonResponse {
        $this->authenticate($request);$order=Order::findOrFail($id);abort_if($order->status==='cancelled',422,'Order is already cancelled.');abort_if($order->payment_status==='paid',422,'A paid order cannot be cancelled.');return app(OrderController::class)->cancel($order);
    }

    /**
     * Hands the device a block of today's offline order numbers. The counter restarts every day, and blocks
     * never overlap, so two phones offline at the same time cannot print the same number.
     */
    public function numberPool(Request $request): JsonResponse {
        $this->authenticate($request);$size=max(10,min(200,(int)$request->input('size',50)));
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('mobile_pos_daily_counters'),503,'Offline number pool is not set up yet (run migrations).');
        $date=now('Asia/Manila')->toDateString();
        $end=DB::transaction(function()use($date,$size){
            DB::table('mobile_pos_daily_counters')->insertOrIgnore(['counter_date'=>$date,'last_number'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('mobile_pos_daily_counters')->where('counter_date',$date)->lockForUpdate()->first();
            $end=(int)$row->last_number+$size;
            DB::table('mobile_pos_daily_counters')->where('counter_date',$date)->update(['last_number'=>$end,'updated_at'=>now()]);
            return $end;
        });
        return response()->json(['date'=>$date,'start'=>$end-$size+1,'end'=>$end]);
    }

    /** Sends a receipt to the online print channel (Pusher) - used when this device has no printer connected */
    public function orderPrint(Request $request,int $id): JsonResponse {
        $this->authenticate($request);$order=Order::with(['items.product','user','queueNumber','payments.tender'])->findOrFail($id);
        $job=app(PrintJobService::class)->queueForOrder($order);
        abort_if($job->status==='failed',422,$job->failed_reason?:'Online printing is not configured.');
        return response()->json(['id'=>$job->id,'status'=>$job->status],201);
    }

    public function reservationIndex(Request $request): JsonResponse {
        $this->authenticate($request);$q=Reservation::orderBy('reserved_at');if($request->filled('from'))$q->where('reserved_at','>=',$request->input('from'));if($request->filled('to'))$q->where('reserved_at','<=',$request->input('to'));return response()->json($q->get());
    }
    public function reservationStore(Request $request): JsonResponse {
        $user=$this->authenticate($request);$d=$request->validate(['client_id'=>'required|uuid','contact_name'=>'required|string|max:255','phone'=>'nullable|string|max:50','social_media'=>'nullable|string|max:255','reserved_at'=>'required|date','party_size'=>'nullable|integer|min:1|max:500','notes'=>'nullable|string|max:2000','order_type'=>'nullable|in:dine_in,takeout,delivery','customer_address'=>'nullable|string|max:500']);
        if($x=Reservation::where('client_id',$d['client_id'])->first())return response()->json($x);$d['user_id']=$user->id;$d['status']='pending';return response()->json(Reservation::create($d),201);
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
    public function financialDaily(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->daily($request); }
    public function financialPeriods(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->periods($request); }
    public function paymentTenders(Request $request): JsonResponse { $this->authenticate($request); return app(PaymentTenderController::class)->index(); }
    public function financialStore(Request $request): JsonResponse { $this->authenticate($request); return app(FinancialTransactionController::class)->store($request); }

    public function weather(Request $request, StoreWeather $weather): JsonResponse
    {
        $this->authenticate($request);

        return response()->json($weather->forecast(CarbonImmutable::now(StoreWeather::TIMEZONE)));
    }

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
            'sales-insights'=>$controller->salesInsights($request, app(SalesDayInsights::class)),
            'serving-time'=>$controller->servingTime(),
            default=>abort(404,'Unknown report.')
        };
    }

    /** Public Pusher settings so the Android POS can listen for web print requests (the key is public; no secret is sent) */
    private function realtimeConfig(): ?array {
        $key=config('broadcasting.connections.pusher.key');if(!$key)return null;$setting=PrintServiceSetting::getSetting();
        return ['key'=>$key,'cluster'=>config('broadcasting.connections.pusher.options.cluster','ap1'),'channel'=>($setting->getAttribute('print_channel')?:\Illuminate\Support\Facades\Cache::get('print_service_channel'))?:'orders','event'=>'print'];
    }
    private function authenticate(Request $request) {
        $plain=$request->bearerToken();abort_unless($plain,401,'Mobile POS token required.');
        $row=DB::table('mobile_pos_tokens')->where('token_hash',hash('sha256',$plain))->first();abort_unless($row,401,'Invalid mobile POS token.');
        DB::table('mobile_pos_tokens')->where('id',$row->id)->update(['last_used_at'=>now()]);
        $user=\App\Models\User::findOrFail($row->user_id);auth()->setUser($user);$request->setUserResolver(fn()=>$user);return $user;
    }
    private function userPayload($user): array { return ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'roles'=>$user->getRoleNames()->values(),'permissions'=>$user->getAllPermissions()->pluck('name')->values()]; }
}
