<?php

namespace App\Http\Controllers\Invoicing\Product;


use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Transformers\ProductTransformer;
use Illuminate\Http\Request;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

class Products extends Controller
{
    /**
     * @param Request      $request
     * @param Manager      $fractal
     * @param Company|null $company
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request, Manager $fractal, Company $company = null)
    {
        $search = $request->query('search');
        # get the search term in the query, if any

        $type = $request->query('product_type') ?: 'default';
        # get the product_type from the query, if any
        $parent = $request->query('product_parent') ?: '';
        # get the product_parent from the query, if any

        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $company = empty($company) || empty($company->id) ? $this->company() : $company;
        # retrieve the company
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        $category = null;
        # get the category
        if ($request->has('category_slug')) {
            $category = $company->productCategories()->where('slug', $request->input('category_slug'))->first();
            # set the category
        }
        if (empty($search)) {
            # no search parameter

            //search with or without variant
            $paginator = $company->products()->with(['prices'])
                                                ->withCount(['orders'])
                                                ->when($category, function ($query) use ($category) {
                                                    return $query->whereIn('id', function ($query) use ($category) {
                                                        $query->select('product_id')
                                                                ->from('product_category')
                                                                ->where('product_category_id', $category->id);
                                                    });
                                                })
                                                ->where([
                                                    ['product_type', '=', $type],
                                                    ['product_parent', '=', $parent]
                                                ])
                                                ->oldest('name')
                                                ->paginate($limit);
        } else {
            # searching for something
            $paginator = \App\Models\Product::search($search)
                                            ->where('company_id', $company->id)
                                            ->paginate($limit);
        }
        # get the products
        $resource = new Collection($paginator->getCollection(), new ProductTransformer(), 'product');
//        dd($resource);
        # create the resource
        if (!empty($search)) {
            $pagingAppends['search'] = $search;
            # append the search term to the paginator
            $resource->setMetaValue('search', $search);
            # set the meta value if necessary
        }
        $paginator->appends($pagingAppends);
        # add the append terms
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));
        # set the paginator
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create(Request $request, Manager $fractal)
    {
        $company = $this->company();
        # get the company
        $this->validate($request, [
            'name' => 'required|max:80',
            'description' => 'nullable',
            'product_type' => 'nullable|string',
            'product_parent' => 'nullable|string',
            'product_variant' => 'nullable|string',
            'product_variant_type' => 'nullable|string',
            'default_price' => 'required_without:prices|numeric',
            'prices' => 'nullable|array',
            'prices.*.currency' => 'required|string|size:3',
            'prices.*.price' => 'required|numeric',
        ]);
        # validate the request
        $product = $company->products()->create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'product_type' => $request->input('product_type','default'),
            'product_parent' => $request->input('product_parent',''),
            'product_variant' => $request->input('product_variant',''),
            'product_variant_type' => $request->input('product_variant_type',''),
            'unit_price' => $request->input('default_price', 0.00),
        ]);


        # create the model
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
                        'One of the product prices your specified is not a valid ISO currency. You provided a '.
                        'currency of: '.$price['currency']
                    );
                }
                $productPrices = $productPrices->push(['currency' => strtoupper($price['currency']), 'unit_price' => $price['price']]);
                # add the price to the array
            }
            $productPrices = $productPrices->unique('currency')->all();
            # remove duplicate entries
        } else {
            $productPrices[] = ['currency' => 'NGN', 'unit_price' => $request->input('default_price')];
            # add the price to the array
        }
        $product->prices()->createMany($productPrices);
        # add the prices
        $resource = new Item($product, new ProductTransformer(), 'product');
        return response()->json($fractal->createData($resource)->toArray(), 201);
    }


}