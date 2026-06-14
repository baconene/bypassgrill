<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FinancialTransaction extends Model {

    protected static function boot(): void
    {
        parent::boot();

        // Automatically compute running_balance for every new transaction so
        // records created by OrderService / PaymentService are correct too.
        static::creating(function (FinancialTransaction $tx): void {
            $prev = static::orderByDesc('transacted_at')
                ->orderByDesc('id')
                ->value('running_balance') ?? 0.0;

            $tx->running_balance = round($prev + match ($tx->type) {
                'payment', 'income_adjustment'  => (float) $tx->amount,
                'expense', 'order', 'payroll', 'asset_deduction' => -(float) $tx->amount,
                default                         => 0.0,
            }, 2);
        });
    }

    protected $fillable = [
        'type', 'amount', 'description',
        'order_id', 'payment_id', 'payment_tender_id', 'payroll_record_id',
        'user_id', 'notes', 'transacted_at', 'running_balance',
    ];
    protected $casts = [
        'amount'          => 'decimal:2',
        'running_balance' => 'decimal:2',
        'transacted_at'   => 'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function tender() { return $this->belongsTo(PaymentTender::class, 'payment_tender_id'); }
    public function payrollRecord() { return $this->belongsTo(\App\Models\PayrollRecord::class, 'payroll_record_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
