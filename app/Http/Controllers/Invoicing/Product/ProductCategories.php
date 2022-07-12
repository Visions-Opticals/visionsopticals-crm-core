<?php

namespace App\Http\Controllers\Invoicing\Product;


use App\Exceptions\DeletingFailedException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Transformers\ProductCategoryTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;

class ProductCategories extends Controller
{
    /**
     * @var array
     */
    protected $updateFields = [
        'name' => 'name',
        'slug' => 'slug',
        'description' => 'description'
    ];
    
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
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $company = empty($company) || empty($company->id) ? $this->company() : $company;
        # retrieve the company
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        if (empty($search)) {
            # no search parameter
            $paginator = $company->productCategories()->withCount('products')->oldest('name')->paginate($limit);
        } else {
            # searching for something
            $paginator = ProductCategory::search($search)->where('company_id', $company->id)->paginate($limit);
        }
        # get the products
        $resource = new Collection($paginator->getCollection(), new ProductCategoryTransformer(), 'category');
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
            'description' => 'nullable'
        ]);
        # validate the request
        $slug = $company->id . '-' . str_slug($request->name);
        # set the slug
        if (ProductCategory::where('slug', $slug)->count() > 0) {
            $slug .= '-' . uniqid();
        }
        $category = $company->productCategories()->create([
            'name' => $request->input('name'),
            'slug' => $slug,
            'description' => $request->input('description')
        ]);



        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');

        $url = $baseUrl . 'add_category_from_core';

        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'product_category' => $request->input('name'),
            'category_uuid' => $category->uuid,
        ]);

        # create the model
        $resource = new Item($category, new ProductCategoryTransformer(), 'category');

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
    public function delete(Request $request, Manager $fractal, string $id)
    {

        $company = $this->company($request);
        # retrieve the company
        $category = $company->productCategories()->withCount('products')->where('uuid', $id)->firstOrFail();

        # try to get the category
        if (!(clone $category)->delete()) {
            throw new DeletingFailedException('Failed while deleting the category');
        }
        $resource = new Item($category, new ProductCategoryTransformer(), 'category');


        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');
        $url = $baseUrl . 'delete_product_category';
        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'category_uuid' => $id,
        ]);

        # create the model
        $resource = new Item($category, new ProductCategoryTransformer(), 'category');
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
    public function single(Request $request, Manager $fractal, string $id, Company $company = null)
    {
        $allowedColumns = ['uuid', 'slug'];
        # the columns we can select by
        $company = empty($company) || empty($company->id) ? $this->company() : $company;
        # retrieve the company
        $column = strtolower($request->has('select_using', 'uuid'));
        $column = !in_array($column, $allowedColumns) ? $allowedColumns[0] : $column;
        # fix a wrong select column, when required
        $category = $company->productCategories()->withCount('products')->where($column, $id)->firstOrFail();
        # try to get the category
        $resource = new Item($category, new ProductCategoryTransformer(), 'category');
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
        $this->validate($request, [
            'name' => 'nullable|max:80',
            'slug' => 'nullable|max:80',
            'description' => 'nullable',
        ]);
        # validate the request
        $slug = $request->input('slug');
        # the slug to update to
        if (empty($slug) && $request->has('update_slug') && $request->has('name')) {
            # update the slug, with the provided name
            $slug = $company->id . '-' . str_slug($request->name);
        }
        if (!empty($slug)) {
            $found = ProductCategory::where('slug', $slug)->first();
            if (empty($found) || $found->uuid !== $id) {
                # a different product category
                throw new \UnexpectedValueException(
                    'The slug "' . $slug.'" is already in use for another product category.'
                );
            }
        }
        if (!empty($slug)) {
            $request->request->set('slug', $slug);
        }
        $category = $company->productCategories()->withCount('products')->where('uuid', $id)->firstOrFail();
        # try to get the category
        $this->updateModelAttributes($category, $request);
        # update the attributes
        $category->saveOrFail();



        $baseUrl = env('WHATSAPP_PROCESSOR_ENDPOINT');
        $url = $baseUrl . 'update_product_category';
        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
        ];

        $response = Http::withHeaders($headers)->post($url ,[
            'category_uuid' => $id,
            'name' => $request->name,
        ]);


        # commit the changes
        $resource = new Item($category, new ProductCategoryTransformer(), 'category');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
}