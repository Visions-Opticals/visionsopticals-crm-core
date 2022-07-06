<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use SoftDeletes, Searchable;

    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'barcode',
        'barcode_img',
        'description',
        'unit_price',
        'inventory',
        'product_type',
        'product_parent',
        'product_variant',
        'product_variant_type'
    ];




    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo('App\Models\Product','product_parent','uuid')->where([
            ['product_type', '=', 'default'],
            ['product_parent', '=', '']
        ]);
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function variants()
    {
        return $this->hasMany('App\Models\Product','product_parent','uuid')->where([
            ['product_type', '=', 'variant'],
            ['product_parent', '<>', '']
        ]);
    }

    
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function categories()
    {
        return $this->belongsToMany(ProductCategory::class, 'product_category');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_items')
                    ->withPivot(['uuid', 'quantity', 'unit_price']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $array = $this->toArray();
        $company = $this->company->toArray();
        foreach ($company as $attributeKey => $attributeValue) {
            $company['company_' . $attributeKey] = $attributeValue;
            unset($company[$attributeKey]);
        }
        return array_merge($array, $company);
    }
}