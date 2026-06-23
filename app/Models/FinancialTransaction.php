<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FinancialTransaction extends Model {

    // Running balance is derived on read (FinancialTransactionController computes
    // `financial_balance` in chronological order). It is intentionally NOT stored,
    // since an insert-time column breaks for backdated / out-of-order entries.

    protected $fillable = [
        'type', 'amount', 'description',
        'order_id', 'payment_id', 'payment_tender_id', 'payroll_record_id',
        'distribution_snapshot_id', 'shareholder_id',
        'user_id', 'notes', 'transacted_at',
    ];
    protected $casts = [
        'amount'        => 'decimal:2',
        'transacted_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function tender() { return $this->belongsTo(PaymentTender::class, 'payment_tender_id'); }
    public function payrollRecord() { return $this->belongsTo(\App\Models\PayrollRecord::class, 'payroll_record_id'); }
    public function distributionSnapshot() { return $this->belongsTo(\App\Models\DistributionSnapshot::class, 'distribution_snapshot_id'); }
    public function shareholder() { return $this->belongsTo(\App\Models\Shareholder::class); }
    public function user() { return $this->belongsTo(User::class); }
}
