<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CustomerOrder extends Pivot
{
    protected $dates = ['paid_at'];
    public $timestamps = false; 

    protected $casts = [
        'is_paid' => 'boolean'
    ];

    protected $fillable = [
        'customer_id',
        'order_id',
        'is_paid',
        'paid_at',
    ];

    /**
     * @return string
     */
    public function getInvoiceNumberAttribute(): string
    {
        $invoice = str_pad($this->attributes['order_id'], 6, '0', STR_PAD_LEFT);
        return $invoice . '-' . $this->attributes['customer_id'];
    }

    /**
     * Returns the transactions for this particular customer order.
     *
     * @return Collection
     */
    public function getTransactionsAttribute(): Collection
    {
        return PaymentTransaction::where('order_id', $this->attributes['order_id'])
                                    ->where('customer_id', $this->attributes['customer_id'])
                                    ->latest()
                                    ->get();
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return static::UPDATED_AT;
    }
}