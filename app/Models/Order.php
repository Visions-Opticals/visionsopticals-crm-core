<?php

namespace App\Models;


use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Order extends Model
{
    use SoftDeletes, Searchable;

    protected $dates = ['due_at'];

    protected $casts = [
        'reminder_on' => 'boolean',
        'is_quote' => 'boolean',
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'title',
        'description',
        'product_name',
        'product_description',
        'quantity',
        'unit_price',
        'currency',
        'amount',
        'due_at',
        'reminder_on',
        'is_quote',
    ];

    /**
     * @return string
     */
    public function getInvoiceNumberAttribute(): string
    {
        return str_pad($this->attributes['id'] , 6, '0', STR_PAD_LEFT);
    }

    /**
     * Attributes that tells us if an order is still fully editable.
     * This is based on the edit_timeout config setting.
     *
     * @return bool
     */
    public function getIsFullyEditableAttribute()
    {
        $createdAt = Carbon::parse($this->attributes['created_at']);
        $editTimeout = (int) config('invoicing.edit_timeout', 900);
        return $createdAt->addSeconds($editTimeout) >= Carbon::now();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsToMany
     */
    public function customers()
    {
        return $this->belongsToMany(Customer::class)
                    ->withPivot(['is_paid', 'paid_at'])
                    ->using(CustomerOrder::class);
    }

    /**
     * @return BelongsToMany
     */
    public function items()
    {
        return $this->belongsToMany(Product::class, 'order_items')
                    ->withPivot(['uuid', 'quantity', 'unit_price']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $array = $this->toArray();
        $array['invoice_number'] = $this->invoice_number;
        $company = $this->company->toArray();
        foreach ($company as $attributeKey => $attributeValue) {
            $company['company_' . $attributeKey] = $attributeValue;
            unset($company[$attributeKey]);
        }
        $products = [];
        foreach ($this->items as $product) {
            $products[] = $product->toArray();
        }
        return array_merge($array, $company, ['items' => $products]);
    }
}