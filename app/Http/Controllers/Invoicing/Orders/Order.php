<?php

namespace App\Http\Controllers\Invoicing\Orders;


use App\Exceptions\DeletingFailedException;
use App\Exceptions\RecordNotFoundException;
use App\Http\Controllers\Controller;
use App\Jobs\Invoicing\ProcessOrder;
use App\Models\Company;
use App\Models\CustomerOrder;
use App\Models\PaymentTransaction;
use App\Notifications\InvoicePaid;
use App\Transformers\CustomerTransformer;
use App\Transformers\OrderTransformer;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
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
            'email'     => 'required|max:30',
            'productId' => 'required',
            'reference' => 'required',
            'amount'    => 'required'
        ]);

        $company_uuid     = Company::first();
        $company          = Company::where('uuid',$company_uuid->uuid)->first();
        $product          = \App\Models\Product::where('uuid',$request->productId)->first();
        $ExistingCustomer = \App\Models\Customer::where('email',$request->email)->first();

        if($product->inventory > 0){
            $product->inventory -= (int) request()->quantity;
            $product->save();
        }
        # add the contact information.
        $newOrder = new \App\Models\Order();
        $newOrder->company_id          = $company->id;
        $newOrder->title               = $product->name;
        $newOrder->product_name        = $product->name;
        $newOrder->product_description = $product->description;
        $newOrder->quantity            = $request->quantity;
        $newOrder->unit_price          = $request->amount;
        $newOrder->amount              = $request->amount * $request->quantity;
        $newOrder->quantity            = $request->quantity;
        $newOrder->save();
//
        if($newOrder)
        {
            $newCustomerOrder              = new CustomerOrder();
            $newCustomerOrder->customer_id = $ExistingCustomer->id;
            $newCustomerOrder->order_id    = $newOrder->id;
            $newCustomerOrder->is_paid     = 1;
            $newCustomerOrder->paid_at     = \Carbon\Carbon::now();
            $newCustomerOrder->save();
        }

        $order = \App\Models\Order::where('uuid', $newOrder->uuid)->firstOrFail();

        $reference = $request->reference;

        $transaction                = new PaymentTransaction();
        $transaction->order_id      = $order->id;
        $transaction->customer_id   = $ExistingCustomer->id;
        $transaction->amount        = $request->amount;
        $transaction->reference     = $reference;
        $transaction->is_successful = 1;
        $transaction->json_payload  = $request;
        $transaction->save();

//        Notification::send($company->users->first(), new InvoicePaid($order, $ExistingCustomer, $transaction));

        $resource = new Item($ExistingCustomer, new CustomerTransformer(), 'customer');

        return response()->json($fractal->createData($resource)->toArray(), 201);
    }

    const DEFAULT_IMAGE = 'data:image/jpeg;base64,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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/9k=';

    /**
     * @param Request $request
     * @param string  $id
     *
     * @return mixed
     */
    public function invoicing(Request $request, string $id)
    {



        $this->data['order'] = $order = \App\Models\Order::where('uuid', $id)->firstOrFail();
        # get the order
        $this->data['recipient'] = $customer = $order->customers()->where('uuid', $request->customer)->firstOrFail();
        # get the customer
        $this->data['subject'] = ($order->is_quote ? 'Quote' : 'Invoice') . ' #'.$customer->pivot->invoice_number;
        # the page subject
        $this->data['company'] = $order->company;
        # get the company
        $this->data['plan'] = $plan = $order->company->plan;
        # set the account pricing plan
        $this->data['headerLogo'] = self::DEFAULT_IMAGE;
        # set the invoice logo
        $location = $order->company->locations()->with('state')->first();
        if (!empty($location)) {
            $this->data['location'] = $location;
        }
        if (!empty($order->company->logo)) {
            $this->data['headerLogo'] = image_to_base64($order->company->logo);
        }
        if (ProcessOrder::isServiceRequestTitle($order->title)) {
            # it was created from a service request
            $companyOwner = $order->company->users()->first();
            $this->data['account'] = $companyOwner->bankAccounts->first();
            # get the bank account belonging to the user
        }
        if ($plan->price_montly === 0) {
            $user = $order->company->users()->first();
            $partner = $user->partner;
            $this->data['footerText'] = empty($partner) ? 'Powered by Dorcas' : $partner->name;
        }
        $pdf = app('snappy.pdf.wrapper');
        $title = ($order->is_quote ? 'Quote' : 'Invoice') . ' #';
        return $pdf->view('emails.inlined.invoicing.invoice', $this->data)
            ->setOption('images', true)
            ->stream($title . $customer->pivot->invoice_number . '.pdf');
    }
}