<?php

namespace App\Transformers;


use App\Dorcas\Common\APITransformerTrait;
use App\Models\ProductCategory;
use League\Fractal\ParamBag;
use League\Fractal\TransformerAbstract;

class ProductCategoryTransformer extends TransformerAbstract
{
    use APITransformerTrait;
    
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = ['company', 'products'];
    
    /**
     * Include resources without needing it to be requested.
     *
     * @var array
     */
    protected $defaultIncludes = [];
    
    /**
     * @param ProductCategory $category
     *
     * @return array
     */
    public function transform(ProductCategory $category)
    {
        $resource = [
            'embeds' => $this->getEmbeds(),
            'id' => $category->uuid,
            'name' => $category->name,
            'category_id' => $category->id,
            'slug' => $category->slug,
            'description' => $category->description,
            'products_count' => $category->products()->count(),
            'updated_at' => !empty($category->updated_at) ? $category->updated_at->toIso8601String() : null,
            'created_at' => $category->created_at->toIso8601String()
        ];
        return $resource;
    }
    
    /**
     * @param ProductCategory $category
     * @param ParamBag|null   $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeProducts(ProductCategory $category, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $products = $category->products()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($products, new ProductTransformer(), 'product');
    }
}