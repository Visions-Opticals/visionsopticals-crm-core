<?php

namespace App\Http\Controllers\Invoicing\Product;


use App\Exceptions\DeletingFailedException;
use App\Exceptions\RecordNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Transformers\ProductCategoryTransformer;
use App\Transformers\ProductStockTransformer;
use App\Transformers\ProductTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

class Product extends Controller
{
    /**
     * @var array
     */
    protected $updateFields = [
        'name' => 'name',
        'description' => 'description',
        'default_price' => 'unit_price',
    ];

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company($request);
        # retrieve the company
        $product = $company->products()->with(['prices'])->where('uuid', $id)->firstOrFail();
        # try to get the product
        if (!(clone $product)->delete()) {
            throw new DeletingFailedException('Failed while deleting the product');
        }

        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');
        $url = $baseUrl . 'delete_product_from_core';
        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'product_uuid' => $id,
        ]);

        $resource = new Item($product, new ProductTransformer(), 'product');
        # get the resource
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request      $request
     * @param Manager      $fractal
     * @param string       $id
     * @param Company|null $company
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request, Manager $fractal, string $id, Company $company = null)
    {
        $company = empty($company) || empty($company->id) ? $this->company() : $company;
        # retrieve the company
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $resource = new Item($product, new ProductTransformer(), 'product');
        # get the resource
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function update(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the company


        if(empty($request->barcode)) {

            $this->validate($request, [
                'name' => 'nullable|max:80',
                'description' => 'nullable',
                'default_price' => 'nullable|numeric',
                'prices' => 'nullable|array',
                'prices.*.currency' => 'required_with:prices|string|size:3',
                'prices.*.price' => 'required_with:prices|numeric'
            ]);

            # validate the request
            $product = $company->products()->where('uuid', $id)->firstOrFail();
            # try to get the product
            $this->updateModelAttributes($product, $request);
            # update the attributes
            $productPrices = collect([]);
            # our price container
            $prices = $request->input('prices', []);
            # we check if there are alternate prices
            if (!empty($prices)) {
                # we have alternate prices
                $isoCurrencies = new ISOCurrencies();
                # our currency context
                foreach ($prices as $price) {
                    # we loop through the array of alternate prices
                    $currency = new Currency($price['currency']);
                    if (!$currency->isAvailableWithin($isoCurrencies)) {
                        # this currency is not available
                        throw new \UnexpectedValueException(
                            'One of the product prices your specified is not a valid ISO currency. You provided a ' .
                            'currency of: ' . $price['currency']
                        );
                    }
                    $productPrices = $productPrices->push(['currency' => strtoupper($price['currency']), 'unit_price' => $price['price']]);
                    # add the price to the array
                }
            }
            $productPrices = $productPrices->unique('currency')->all();
            # remove duplicate entries
            DB::transaction(function ($query) use (&$product, $productPrices) {
                $product->saveOrFail();
                # save the changes
                $currencies = collect($productPrices)->map(function ($c) {
                    return $c['currency'];
                });
                $product->prices()->whereNotIn('currency', $currencies)->delete();
                # remove the current prices based on the currencies not present
                foreach ($productPrices as $priceEntry) {
                    ProductPrice::updateOrCreate(
                        ['product_id' => $product->id, 'currency' => $priceEntry['currency']],
                        ['unit_price' => $priceEntry['unit_price']]
                    );
                    # update the price
                }
            });
        }else{

            $this->validate($request, [
                'barcode' => 'required|max:25|unique:products'
            ]);

            $product = $company->products()->where('uuid', $id)->firstOrFail();
            $generator = new \Picqer\Barcode\BarcodeGeneratorHTML();
            $barcode = $generator->getBarcode($request->barcode, $generator::TYPE_CODE_128);
            $product->update(['barcode' => $request->barcode , 'barcode_img' => $barcode]);
        }


        //this endpoint handles updating data on the backend processor for whatsapp ordering
        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');
        $url = $baseUrl . 'update_product_on_backend_processor';
        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'product_uuid' => $id,
            'product_name' => $product->name,
            'price' => $productPrices[0]['unit_price'] ?? 0,
        ]);

        # encapsulate it in a transaction
        $resource = new Item($product, new ProductTransformer(), 'product');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function removeCategory(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the company
        $this->validate($request, [
            'id' => 'required_without:ids',
            'ids' => 'required_without:id|array',
            'ids.*' => 'required|string',
        ]);
        # validate the request
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $term = 'category';
        # the term
        if ($request->has('category')) {
            $categories = $company->productCategories()->where('uuid', $request->id)->pluck('id');
        } else {
            $term = str_plural($term);
            $categories = $company->productCategories()->whereIn('uuid', $request->input('ids'))->pluck('id');
        }
        # get the categories to be removed
        if (empty($categories)) {
            throw new RecordNotFoundException('Could not find the '.$term.' to be removed.');
        }
        $product->categories()->detach($categories);


        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');

        $url = $baseUrl . 'remove_category_from_product';

        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'product_uuid' => $id,
            'category_uuid' => $request->input('ids')[0],
        ]);

        # detach the category
        $resource = new Item($product, new ProductTransformer(), 'product');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function addCategory(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the company
        $this->validate($request, [
            'id' => 'required_without:ids',
            'ids' => 'required_without:id|array',
            'ids.*' => 'required|string',
        ]);
        # validate the request
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $term = 'category';
        # the term
        if ($request->has('category')) {
            $categories = $company->productCategories()->where('uuid', $request->id)->pluck('id');
        } else {
            $term = str_plural($term);
            $categories = $company->productCategories()->whereIn('uuid', $request->input('ids'))->pluck('id');
        }
        # get the categories to be added
        if (empty($categories)) {
            throw new RecordNotFoundException('Could not find the '.$term.' to be added.');
        }
        $product->categories()->attach($categories);

        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');

        $url = $baseUrl . 'add_category_product';

        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'product_uuid' => $id,
            'category_uuid' => $request->input('ids'),
        ]);

        # attach the category
        $resource = new Item($product, new ProductTransformer(), 'product');


        return response()->json($fractal->createData($resource)->toArray(), 201);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function syncCategories(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the company
        $this->validate($request, [
            'ids' => 'required|array',
            'ids.*' => 'required|string',
        ]);
        # validate the request
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $categories = $company->productCategories()->whereIn('uuid', $request->input('ids'))->pluck('id');
        # the categories to be synced on the product
        if (empty($categories)) {
            throw new RecordNotFoundException('Could not find the categories to be set on the product.');
        }
        $product->categories()->sync($categories);
        # attach the category
        $resource = new Item($product, new ProductTransformer(), 'product');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function stocks(Request $request, Manager $fractal, string $id)
    {
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        $company = $this->company();
        # get the currently authenticated company
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $paginator = $product->stocks()->latest()->paginate($limit);
        # create the paginator
        $resource = new Collection($paginator->getCollection(), new ProductStockTransformer(), 'stock');
        # create the resource
        $paginator->appends($pagingAppends);
        # add the append terms
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));
        # set the paginator
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function deleteStocks(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the currently authenticated company
        $this->validate($request, [
            'id' => 'required_without:ids|string',
            'ids' => 'required_without:id|array',
            'ids.*' => 'required|numeric'
        ]);
        # validate the request
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $ids = $request->has('id') ? [$request->id] : $request->ids;
        # get the IDs to be deleted
        $builder = $product->stocks()->whereIn('id', $ids)->get();
        # get the stock entries to be removed
        if (empty($builder) || $builder->count() === 0) {
            throw new \UnderflowException('There are no matching stock entries for those IDs.');
        }
        $stocks = (clone $builder)->get();
        # get the stock entries first
        if (!$builder->delete()) {
            # delete the matching items
            throw new DeletingFailedException('Failed while removing the specified stock entries. Please try again later.');
        }
        $transformer = new ProductStockTransformer();
        $transformer->setDefaultIncludes([]);
        $transformer->setAvailableIncludes([]);
        # we restrict loading extra data
        $resource = new Collection($stocks, new ProductStockTransformer(), 'stock');
        # create the resource
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function stockQuantity(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company();
        # get the currently authenticated company
        $this->validate($request, [
            'action' => 'required|in:add,subtract',
            'quantity' => 'required|numeric|min:0',
            'comment' => 'nullable'
        ]);
        # validate the request
        $product = $company->products()->where('uuid', $id)->firstOrFail();
        # try to get the product
        $stock = null;
        # our stock model
        try {
            DB::transaction(function () use (&$stock, $product, $request) {
                $stock = $product->stocks()->create($request->only(['action', 'quantity', 'comment']));
                # add a new record
                if ($request->action === 'add') {
                    $product->increment('inventory', $request->quantity);
                } else {
                    if ($product->inventory > (int) $request->quantity) {
                        $product->decrement('inventory', $request->quantity);
                    } else {
                        $product->update(['inventory' => 0]);
                    }
                }
            });
            # we make a transaction out of it
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed while updating your stocks, lease try again later.');
        }
        $resource = new Item($stock, new ProductStockTransformer(), 'stock');
        return response()->json($fractal->createData($resource)->toArray(), 201);
    }

    public function scanProductWithBarcode(Request $request ,Manager $fractal,string $id)
    {
        $company = $this->company();
        # get the currently authenticated company
        $this->validate($request, [
            'action' => 'required|in:add,subtract',
            'quantity' => 'sometimes|numeric|min:0',
            'barcode' => 'required|min:25',
            'comment' => 'nullable'
        ]);
        # validate the request
//        $product = $company->products()->where('uuid', $id)->firstOrFail();
        $product = $company->products()->where('barcode', $request->barcode)->firstOrFail();
        # try to get the product
        $stock = null;
        # our stock model
        try {
            DB::transaction(function () use (&$stock, $product, $request) {
                $stock = $product->stocks()->create($request->only(['action', 'quantity', 'comment']));
                # add a new record
                if ($request->action === 'add') {
                    $product->increment('inventory', $request->quantity);
                } else {
                    if ($product->inventory > (int) $request->quantity) {
                        $product->decrement('inventory', $request->quantity);
                    } else {
                        //stick default should be one if non is passed
                        $product->update(['inventory' => 1]);
                    }
                }
            });
            # we make a transaction out of it
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed while updating your stocks, lease try again later.');
        }

        $resource = new Item($stock, new ProductStockTransformer(), 'stock');

        return response()->json($fractal->createData($resource)->toArray(), 201);
    }


    public function fetchCategories(Request $request, Manager $fractal)
    {
        $category = ProductCategory::get();

        $resource = new Collection($category, new ProductCategoryTransformer(), 'category');

        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
}