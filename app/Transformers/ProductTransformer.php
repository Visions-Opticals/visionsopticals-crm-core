<?php

namespace App\Transformers;


use App\Dorcas\Common\APITransformerTrait;
use App\Models\Product;
use League\Fractal\ParamBag;
use League\Fractal\TransformerAbstract;

class ProductTransformer extends TransformerAbstract
{
    use APITransformerTrait;
    
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = ['categories', 'company', 'images', 'orders', 'prices', 'stocks'];

    /**
     * Include resources without needing it to be requested.
     *
     * @var array
     */
    protected $defaultIncludes = ['categories', 'images', 'prices'];

    /**
     * @param Product $product
     *
     * @return array
     */
    public function transform(Product $product)
    {
        $name =  $product->product_type==="variant" ? $product->name . " (" . $product->product_variant . ")" : $product->name;
        $resource = [
            'embeds' => $this->getEmbeds(),
            'id' => $product->uuid,
            'name' => $name,
            'barcode' => $product->barcode,
            'barcode_img' => $product->barcode_img,
            'description' => $product->description,
            'default_currency' => 'NGN',
            'default_unit_price' => [
                'raw' => $product->unit_price,
                'formatted' => number_format($product->unit_price, 2)
            ],
            'inventory' => $product->inventory,
            'product_type' => $product->product_type,
            'product_parent' => $product->product_parent,
            'product_variant' => $product->product_variant,
            'product_variant_type' => $product->product_variant_type,
            'is_trashed' => $product->deleted_at !== null,
            'trashed_at' => !empty($product->deleted_at) ? $product->deleted_at->toIso8601String() : null,
            'updated_at' => $product->updated_at->toIso8601String(),
            'created_at' => $product->created_at->toIso8601String(),
            'links' => [
                'self' => url('/products', [$product->uuid])
            ]
        ];
        if (!empty($product->orders_count)) {
            $resource['total_orders'] = $product->orders_count;
        }
        if (!empty($product->pivot)) {
            $resource['sale'] = [
                'quantity' => $product->pivot->quantity,
                'unit_price' => $product->pivot->unit_price,
            ];
        }
        return $resource;
    }
    
    /**
     * @param Product $product
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeCategories(Product $product)
    {
        return $this->collection($product->categories, new ProductCategoryTransformer(), 'category');
    }

    /**
     * @param Product $product
     *
     * @return \League\Fractal\Resource\Item
     */
    public function includeCompany(Product $product)
    {
        return $this->item($product->company, new CompanyTransformer(), 'company');
    }

    /**
     * @param Product       $product
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeOrders(Product $product, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $orders = $product->orders()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($orders, new OrderTransformer(), 'order');
    }

    /**
     * @param Product $product
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includePrices(Product $product)
    {
        return $this->collection($product->prices, new ProductPriceTransformer(),'price');
    }

    /**
     * @param Product $product
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeImages(Product $product)
    {
        return $this->collection($product->images, new ProductImageTransformer(), 'images');
    }

    /**
     * @param Product       $product
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeStocks(Product $product, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $stocks = $product->stocks()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($stocks, new ProductStockTransformer(), 'stock');
    }
}