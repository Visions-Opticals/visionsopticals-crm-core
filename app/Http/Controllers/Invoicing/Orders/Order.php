<?php

namespace App\Http\Controllers\Invoicing\Orders;


use App\Exceptions\DeletingFailedException;
use App\Exceptions\RecordNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerOrder;
use App\Models\PaymentTransaction;
use App\Notifications\InvoicePaid;
use App\Transformers\CustomerTransformer;
use App\Transformers\OrderTransformer;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use Money\Currency;
use Ramsey\Uuid\Uuid;
use Yabacon\Paystack;

class Order extends Controller
{
    use OrderProcessingTrait;

    /**
     * @var array
     */
    protected $updateFields = [
        'title' => 'title',
        'description' => 'description',
        'currency' => 'currency',
        'amount' => 'amount',
        'enable_reminder' => 'reminder_on',
        'is_quote' => 'is_quote',
        'due_at' => 'due_at',
        'product.name' => 'product_name',
        'product.description' => 'product_description',
        'product.quantity' => 'quantity',
        'product.price' => 'unit_price',
    ];
    
    const RAVE_ENDPOINTS = [
        'live' => 'https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/hosted/pay',
        'test' => 'https://ravesandboxapi.flutterwave.com/flwv3-pug/getpaidx/api/v2/hosted/pay'
    ];

    /**
     * When an order is no longer fully editable, these are the keys that should be ignored
     *
     * @var array
     */
    protected $removeKeys = [
        'currency',
        'amount',
        'product.name',
        'product.description',
        'product.quantity',
        'product.price',
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
        $skipTrash = (int) $request->input('skip_trash', 0);
        # skip trash
        $order = $company->orders()->with(['items'])
                                    ->withCount([
                                        'customers as customers_paid_count' => function ($query) {
                                            $query->where('is_paid', 1);
                                        }
                                    ])
                                    ->where('uuid', $id)
                                    ->firstOrFail();
        # try to get the order
        if ($order->customers_paid_count === 0) {
            # no customers have made payment yet
            $skipTrash = true;
        }
        $status = $skipTrash ? (clone $order)->forceDelete() : (clone $order)->delete();
        # check the status
        if (!$status) {
            throw new DeletingFailedException('Failed while deleting the order');
        }
        $resource = new Item($order, new OrderTransformer(), 'order');
        # get the resource
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     *
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request, Manager $fractal, string $id)
    {
        $company = $this->company($request);
        # retrieve the company
        $order = $company->orders()->with(['items'])->where('uuid', $id)->firstOrFail();
        # try to get the order
        $resource = new Item($order, new OrderTransformer(), 'order');
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
        $company = $this->company($request);
        # retrieve the company
        $this->validate($request, [
            'title' => 'nullable|max:80',
            'description' => 'nullable',
            'currency' => 'nullable|string|size:3',
            'amount' => 'nullable|numeric',
            'enable_reminder' => 'nullable|numeric|in:0,1',
            'is_quote' => 'nullable|numeric|in:0,1',
            'due_at' => 'nullable|date_format:Y-m-d',
            'product' => 'nullable|array',
            'product.name' => 'required_with:product|string|max:80',
            'product.description' => 'nullable|string',
            'product.quantity' => 'nullable|numeric',
            'product.price' => 'nullable|numeric',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|string',
            'products.*.quantity' => 'required_with:products|numeric',
        ]);
        # validate the request
        $order = $company->orders()->where('uuid', $id)->firstOrFail();
        # try to get the order
        if ($request->input('enable_reminder', 0) == 1 && empty($order->due_at) && !$request->has('due_at')) {
            throw new \UnexpectedValueException(
                'Since you want to enable reminders, you need to set a due date (due_at) on this order as well.'
            );
        }
        if (!$order->is_fully_editable) {
            # not fully editable, we update the edit fields
            $this->updateFields = $this->getLimitedEditFields();
        } else {
            if ($request->has('product') && $request->has('products')) {
                throw new \UnexpectedValueException(
                    'You cannot specify both the product and products key at the same time.'
                );
            }
        }
        $this->updateModelAttributes($order, $request);
        # update the attributes
        if ($order->is_fully_editable) {
            # it's still fully editable
            $totalAmount = 0;
            # the total amount of the order
            if ($request->has('product.quantity') || $request->has('product.price')) {
                # we're using a line item
                $totalAmount = $order->unit_price * $order->quantity;
                # compute the total amount
                $order->items()->sync([]);
                # remove the product items
            } elseif ($request->has('products')) {
                # changing the linked product items in the order
                $requestedProducts = $request->input('products', []);
                # get the requested products
                $currency = new Currency($order->currency);
                # create the currency instance
                $orderItems = $this->productsToOrderItems($company, $requestedProducts, $currency, $totalAmount);
                # we get the product items
                $order->items()->sync($orderItems);
                # synchronise the order items
                $order->product_name = null;
                $order->product_description = null;
                $order->quantity = null;
                $order->unit_price = null;
                # clear the inline product information
            }
            if ($totalAmount > 0 && !$request->has('amount')) {
                # update the amount - since there's an update to the quantity/price, but no value for the amount
                $order->amount = $totalAmount;
            }
        }
        $order->saveOrFail();
        # save the changes
        $resource = new Item($order, new OrderTransformer(), 'order');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * Returns the update fields array when the order model is no longer fully editable.
     *
     * @return array
     */
    private function getLimitedEditFields(): array
    {
        $keys = array_keys($this->updateFields);
        # get the keys
        $updateFields = collect(array_diff($keys, $this->removeKeys))->mapWithKeys(function ($key) {
            return [$key => $this->updateFields[$key]];
        });
        return $updateFields->all();
    }
    
    /**
     * @param Request $request
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function reminders(Request $request, string $id)
    {
        $company = $this->company($request);
        # retrieve the company
        $order = $company->orders()->where('uuid', $id)->firstOrFail();
        # try to get the order
        if (empty($order->due_at)) {
            throw new RecordNotFoundException(
                'This order does not have a due date set, and so does not support reminders. You can update the order '.
                'and set a due date on it.'
            );
        }
        if (!$order->reminder_on) {
            throw new RecordNotFoundException(
                'Reminders are turned off for this order even though it has a due date set. You can turn reminders on '.
                'by setting reminder_on to 1.'
            );
        }
        $now = Carbon::now()->startOfDay();
        # get the current date
        $currentDate = $now;
        # set the current date
        $dates = [];
        # the dates the reminders will be sent
        while($order->due_at->greaterThanOrEqualTo($currentDate)) {
            $diffDays = (int) $order->due_at->diff($currentDate)->days;
            # get the difference in days
            $diffFromCreationDate = $now->diff($order->created_at);
            # get the difference from the order creation date
            if ($diffDays >= 4 && $diffFromCreationDate->days % 4 !== 0) {
                # so long as we have more than 4 days to the due date, we send reminders every 4 days
                $currentDate->addDay();
                continue;
            }
            $dates[] = clone $currentDate;
            $currentDate->addDay();
        }
        $reminders = collect($dates)->map(function ($date) {
            return $date->toIso8601String();
        });
        return response()->json(['data' => $reminders]);
    }

    /**
     * @param Request $request
     * @param string  $id
     *
     * @return \Illuminate\Http\RedirectResponse|\Laravel\Lumen\Http\Redirector|string
     */
    public function pay(Request $request, string $id)
    {
        $order = \App\Models\Order::where('uuid', $id)->firstOrFail();
        # try to get the order
        $company = $order->company;
        # retrieve the company
        $returnPaymentUrl = $request->has('return_payment_url');
        # whether or not to return the redirect_url, as opposed to actually redirecting
        $gateway = $company->integrations()->where('type', 'payment')
                                            ->when($request->input('channel'), function ($query) use ($request) {
                                                return  $query->where('name', $request->input('channel'));
                                            })
                                            ->first();
        # we retrieve the gateway
        if (empty($gateway)) {
            abort(500, 'We could not find the payment gateway configuration for '.$company->name);
        }
        $request->request->set('channel', $gateway->name);
        if (!$request->has('customer')) {
            abort(400, 'No customer id was provided in the payment URL.');
        }
        $customer = $company->customers()->where('uuid', $request->customer)->first();
        # try to get the customer
        if (empty($customer)) {
            abort(500, 'We could not retrieve the customer information for this payment.');
        }
        $configurations = collect($gateway->configuration);
        # we get the configuration
        $query = $request->only(['channel', 'customer']);
        $redirectUrl = url('/orders', [$order->uuid, 'verify-payment']) . '?' . http_build_query($query);
        # the redirect URL for the payment
        try {
            $paymentUrl = null;
            switch ($request->channel) {
                case 'paystack':
                    $privateKey = $configurations->where('name', 'private_key')->first();
                    $paystack = new Paystack($privateKey !== null ? $privateKey['value'] : '');
                    # initialize Paystack
                    $transaction = $paystack->transaction->initialize([
                        'amount' => $order->amount * 100,
                        'email' => $customer->email,
                        'callback_url' => $redirectUrl,
                        'metadata' => json_encode([
                            'cart_id' => $order->id,
                            'custom_fields'=> [
                                ['display_name'=> "Paid via", 'variable_name'=> "paid_via", 'value'=> 'Invoice']
                            ]
                        ])
                    ]);
                    # create the transaction on Paystack
                    if (!$transaction->status) {
                        abort(500, $transaction->message);
                    }
                    $paymentUrl = $transaction->data->authorization_url;
                    break;
                    
                case 'rave':
                    $env = strtolower((string) $configurations->where('mode', 'mode')->first());
                    $env = empty($env) || !in_array($env, ['test', 'live']) ? 'live' : $env;
                    $publicKey = $configurations->where('name', 'public_key')->first();
                    $transaction = rave_init_transaction($env, [
                        'txref' => $customer->id . '-' . uniqid($customer->id),
                        'PBFPubKey' => $publicKey['value'],
                        'customer_email' => $customer->email,
                        'amount' => $order->amount,
                        'currency' => $order->currency,
                        'redirect_url' => $redirectUrl
                    ]);
                    if ($transaction['status'] !== 'success' || empty($transaction['data']['link'])) {
                        abort(500, $transaction['message']);
                    }
                    $paymentUrl = $transaction['data']['link'];
                    break;
            }
            if (!empty($paymentUrl)) {
                return $returnPaymentUrl ? $paymentUrl : redirect($paymentUrl);
            }
        } catch (\Throwable $e) {
            Log::error($e->getMessage(), ['config' => $configurations->all(), 'request' => $request->all()]);
            abort(500, 'Something went wrong: '. $e->getMessage());
        }
        return 'Contact the seller, providing the URL from the email if you are not redirected to the payment page.';
    }
    
    /**
     * @param Request $request
     * @param string  $id
     *
     * @return string
     * @throws AuthorizationException
     */
    public function verifyPayment(Request $request, string $id)
    {
        $order = \App\Models\Order::where('uuid', $id)->firstOrFail();
        # try to get the order
        $company = $order->company;
        # retrieve the company
        if (!$request->has('channel')) {
            abort(400, 'No payment channel was provided in the payment URL.');
        }
        $gateway = $company->integrations()->where('type', 'payment')
                                            ->where('name', $request->channel)
                                            ->first();
        # we retrieve the gateway
        if (empty($gateway)) {
            abort(500, 'We could not find the payment gateway configuration for '.$company->name);
        }
        if (!$request->has('customer')) {
            abort(400, 'No customer id was provided in the payment URL.');
        }
        $customer = $company->customers()->where('uuid', $request->customer)->first();
        # try to get the customer
        if (empty($customer)) {
            abort(500, 'We could not retrieve the customer information for this payment.');
        }
        $configurations = collect($gateway->configuration);
        # we get the configuration
        $transaction = null;
        # our transaction object
        try {
            switch ($request->channel) {
                case 'paystack':
                    if (!$request->has('reference')) {
                        abort(400, 'No payment reference was provided by the P payment gateway.');
                    }
                    $reference = $request->reference;
                    $privateKey = $configurations->where('name', 'private_key')->first();
                    $transaction = payment_verify_paystack($privateKey['value'], $reference, $order);
                    break;
                case 'rave':
                    if (!$request->has('txref')) {
                        abort(400, 'No payment reference was provided by the R payment gateway.');
                    }
                    if ($request->has('cancelled') && $request->cancelled == 'true') {
                        return 'You cancelled the payment. You may try again at a later time.';
                    }
                    $reference = $request->txref;
                    $env = strtolower((string) $configurations->where('mode', 'mode')->first());
                    $env = empty($env) || !in_array($env, ['test', 'live']) ? 'live' : $env;
                    $privateKey = $configurations->where('name', 'private_key')->first();
                    $transaction = payment_verify_rave($env, $privateKey['value'], $reference, $order);
                    break;
            }
        } catch (\UnexpectedValueException $e) {
            abort(400, $e->getMessage());
        } catch (\HttpException $e) {
            abort(500, $e->getMessage());
        } catch (\Throwable $e) {
            abort(500, 'Something went wrong: '. $e->getMessage());
        }
        $txn = $order->transactions()->firstOrNew([
            'reference' => $reference,
            'channel' => $transaction['channel']
        ]);
        # we try to get the instance if necessary
        if (!empty($txn->customer_id) && $txn->customer_id !== $customer->id) {
            # a different customer owns this transaction, than the person verifying it
            throw new AuthorizationException('This transaction does not belong to your account.');
        }
        $txn->customer_id = $customer->id;
        # set the customer id
        foreach ($transaction as $key => $value) {
            # set properties on the object
            $txn->{$key} = $value;
        }
        if (!$txn->save()) {
            abort(500, 'We encountered issues while saving the transaction. Kindly email your transaction reference to support: '.$reference);
        }
        # try to create the transaction, if required
        if (!$txn->is_successful) {
            abort(400, 'The payment transaction failed, try and make a successful payment to continue.');
        }
        $customer = $order->customers()->where('customer_id', $customer->id)->first();
        # get the customer with the Pivot
        if (!$customer->pivot instanceof CustomerOrder) {
            abort(500, 'Something went wrong, we could not retrieve your purchase. Please report this to support along with your Payment reference: '.$reference);
        }
        $customerOrder = $customer->pivot;
        $customerOrder->is_paid = true;
        $customerOrder->paid_at = Carbon::now();
        if (!$customerOrder->save()) {
            abort(500, 'Something went wrong, we could not mark your purchase as paid. Please report this to support along with your Payment reference: '.$reference);
        }
        Notification::send($company->users->first(), new InvoicePaid($order, $customer, $txn));
        # send the notification to members of the company
        $data = [
            'reference' => $reference,
            'txn' => $txn,
            'message' => 'Successfully completed order payment. Your reference is: '.$reference,
            'company_name' => $company->name,
            'company_logo' => $company->logo,
            'webstore_url' => "https://" . $company->domainIssuances->first()->prefix . ".store.dorcas.io"
        ];
        return view('payment.payment-complete-response', $data);
    }


    public function addOrderFromWhatsappProcessor(Request $request ,Manager $fractal)
    {

        $this->validate($request, [
            'email' => 'required|max:30',
            'productId' => 'required',
            'reference' => 'required',
            'amount' => 'required'
        ]);

        $company_uuid = Company::first();
        $company = Company::where('uuid',$company_uuid->uuid)->first();
        $product = \App\Models\Product::where('uuid',$request->productId)->first();
        $ExistingCustomer = \App\Models\Customer::where('email',$request->email)->first();

        # add the contact information.
        $newOrder = new \App\Models\Order();
        $newOrder->company_id = $company->id;
        $newOrder->title = $product->name;
        $newOrder->product_name = $product->name;
        $newOrder->product_description = $product->description;
        $newOrder->quantity = $request->quantity;
        $newOrder->unit_price = $request->amount;
        $newOrder->amount = $request->amount * $request->quantity;
        $newOrder->quantity = $request->quantity;
        $newOrder->save();
//
        if($newOrder)
        {
            $newCustomerOrder = new CustomerOrder();
            $newCustomerOrder->customer_id = $ExistingCustomer->id;
            $newCustomerOrder->order_id = $newOrder->id;
            $newCustomerOrder->is_paid = 1;
            $newCustomerOrder->paid_at = \Carbon\Carbon::now();
            $newCustomerOrder->save();
        }

        $order = \App\Models\Order::where('uuid', $newOrder->uuid)->firstOrFail();

        $reference = $request->reference;

        $transaction = new PaymentTransaction();
        $transaction->order_id = $order->id;
        $transaction->customer_id = $ExistingCustomer->id;
        $transaction->amount = $request->amount;
        $transaction->reference = $reference;
        $transaction->is_successful = 1;
        $transaction->json_payload = $request;
        $transaction->save();

//        Notification::send($company->users->first(), new InvoicePaid($order, $ExistingCustomer, $transaction));

        $resource = new Item($ExistingCustomer, new CustomerTransformer(), 'customer');

        return response()->json($fractal->createData($resource)->toArray(), 201);
    }
}