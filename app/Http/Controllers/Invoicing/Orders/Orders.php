<?php

namespace App\Http\Controllers\Invoicing\Orders;


use App\Http\Controllers\Controller;
use App\Jobs\Invoicing\ProcessOrder;
use App\Models\Company;
use App\Transformers\OrderTransformer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

class Orders extends Controller
{
    use OrderProcessingTrait;

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request, Manager $fractal)
    {
        $search = $request->query('search');
        # get the search term in the query, if any
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $company = $this->company();
        # retrieve the company
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        if (empty($search)) {
            # no search parameter
            $paginator = $company->orders()->with(['items'])
                                            ->withCount(['customers'])
                                            ->latest()
                                            ->paginate($limit);
        } else {
            # searching for something
            $paginator = \App\Models\Order::search($search)
                                                ->where('company_id', $company->id)
                                                ->paginate($limit);
        }
        # get the orders
        $resource = new Collection($paginator->getCollection(), new OrderTransformer(), 'order');
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
     * @param Request      $request
     * @param Manager      $fractal
     * @param Company|null $company
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create(Request $request, Manager $fractal, Company $company = null)
    {
        $company = empty($company) || empty($company->id) ? $this->company() : $company;
        # retrieve the company
        $this->validate($request, [
            'title' => 'required|max:80',
            'description' => 'nullable',
            'currency' => 'required|string|size:3',
            'amount' => 'nullable|numeric',
            'due_date' => 'nullable|date_format:Y-m-d',
            'product' => 'required_without:products|array',
            'product.name' => 'required_with:product|string|max:80',
            'product.description' => 'nullable|string',
            'product.quantity' => 'nullable|numeric',
            'product.price' => 'required_with:product|numeric',
            'products' => 'required_without:product|array',
            'products.*.id' => 'required_with:products|string',
            'products.*.quantity' => 'required_with:products|numeric',
            'products.*.price' => 'nullable|numeric',
            'customers' => 'required|array|min:1',
            'customers.*' => 'required|string',
            'enable_reminder' => 'nullable|in:0,1',
            'is_quote' => 'nullable|in:0,1',
        ]);
        # validate the request
        $isoCurrencies = new ISOCurrencies();
        # our currency context
        $currency = new Currency($request->input('currency'));
        # create a currency instance for validating it
        if (!$currency->isAvailableWithin($isoCurrencies)) {
            # this currency is not available
            throw new \UnexpectedValueException(
                'The currency for the order is not a valid ISO currency. You provided a currency of: '.$currency->getCode()
            );
        }
        $date = null;
        if ($request->has('due_date')) {
            # we check the provided due date
            $date = Carbon::createFromFormat('Y-m-d', $request->input('due_date'));
            if ($date->lessThanOrEqualTo(Carbon::yesterday())) {
                throw new \UnexpectedValueException(
                    'The due date you provided is in the past. The due date needs to be set to the future.'
                );
            }
        }
        $isReminderOn = (int) $request->input('enable_reminder', 0);
        # we get the reminder status
        if ($isReminderOn === 1 && !$request->has('due_date')) {
            # reminder is turned on, but no due date provided
            throw new \UnexpectedValueException(
                'To effectively use invoice reminders, a due date needs to be set on the order'
            );
        }
        $orderData = [
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'currency' => $request->input('currency'),
            'amount' => $request->input('amount', 0),
            'reminder_on' => $isReminderOn,
            'is_quote' => $request->input('is_quote', 0),
            'due_at' => $request->input('due_date', null)
        ];
        $orderItems = [];
        # the order items
        $totalAmount = 0;
        # the total amount of the order
        if ($request->has('product')) {
            # we're using a line item
            $product = $request->input('product');
            # get the array
            $orderData = array_merge($orderData, [
                'product_name' => $product['name'],
                'product_description' => $product['description'] ?? null,
                'quantity' => $product['quantity'] ?? 1,
                'unit_price' => $product['price'] ?? 0
            ]);
            # extend the array
            $totalAmount = $orderData['unit_price'] > 0 ? $orderData['unit_price'] * $orderData['quantity'] : 0;
            # compute the total amount

        } else {
            # using actual products
            $requestedProducts = $request->input('products', []);
            # get the requested products
            $orderItems = $this->productsToOrderItems($company, $requestedProducts, $currency, $totalAmount);
            # we get the product items
        }
        if ($orderData['amount'] === 0 && $totalAmount > 0) {
            # we set the amount, since it wasn't previously set
            $orderData['amount'] = $totalAmount;
        }
        $customerIds = $company->customers()->whereIn('uuid', $request->input('customers'))->pluck('id');
        # get the customers
        $order = $company->orders()->create($orderData);
        # create the model
        if (!empty($orderItems)) {
            $order->items()->sync($orderItems);
            # synchronise the order items
        }
        $order->customers()->sync($customerIds);
        # synchronise the customers as well
        # $job = (new ProcessOrder($order))->delay(config('invoicing.edit_timeout', 900));
        dispatch(new ProcessOrder($order));
        # dispatch the order processing job
        try {
            $request->query->set('return_payment_url', 1);
            $request->query->set('customer', $request->input('customers')[0]);
            $paymentUrl = (new Order())->pay($request, $order->uuid);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
        }
        $resource = new Item($order, new OrderTransformer(), 'order');
        if (!empty($paymentUrl)) {
            $resource->setMetaValue('payment_url', $paymentUrl);
        }
        return response()->json($fractal->createData($resource)->toArray(), 201);
    }
}
