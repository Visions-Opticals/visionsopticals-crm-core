<?php

namespace App\Transformers;


use App\Dorcas\Common\APITransformerTrait;
use App\Models\CustomerOrder;
use App\Models\Order;
use League\Fractal\ParamBag;
use League\Fractal\TransformerAbstract;

class OrderTransformer extends TransformerAbstract
{
    use APITransformerTrait;
    
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = ['company', 'customers', 'products', 'customer_order'];

    /**
     * Include resources without needing it to be requested.
     *
     * @var array
     */
    protected $defaultIncludes = ['customer_order', 'products'];

    /**
     * @param Order $order
     *
     * @return array
     */
    public function transform(Order $order)
    {
        $resource = [
            'embeds' => $this->getEmbeds(),
            'id' => $order->uuid,
            'invoice_number' => $order->invoice_number,
            'title' => $order->title,
            'description' => $order->description,
            'currency' => $order->currency,
            'amount' => [
                'raw' => $order->amount,
                'formatted' => number_format($order->amount, 2)
            ],
            'has_reminders' => $order->reminder_on,
            'is_quote' => $order->is_quote,
            'due_at' => !empty($order->due_at) ? $order->due_at->toIso8601String() : null,
            'is_trashed' => $order->deleted_at !== null,
            'trashed_at' => !empty($order->deleted_at) ? $order->deleted_at->toIso8601String() : null,
            'updated_at' => $order->updated_at->toIso8601String(),
            'created_at' => $order->created_at->toIso8601String(),
            'customer' => strtolower($order->customers[0]->email) ?? null,
        ];
        if (!empty($order->product_name)) {
            # we have an inline product
            $resource['inline_product'] = [
                'name' => $order->product_name,
                'description' => $order->product_description,
                'quantity' => $order->quantity,
                'unit_price' => $order->unit_price
            ];
        }
        if (!empty($order->pivot) && $order->pivot instanceof CustomerOrder) {
            $resource['sale'] = [
                'is_paid' => $order->pivot->is_paid,
                'paid_at' => !empty($order->pivot->paid_at) ? $order->pivot->paid_at->toIso8601String() : null
            ];
        }
        return $resource;
    }

    /**
     * @param Order $order
     *
     * @return \League\Fractal\Resource\Item
     */
    public function includeCompany(Order $order)
    {
        return $this->item($order->company, new CompanyTransformer(), 'company');
    }

    /**
     * @param Order $order
     *
     * @return \League\Fractal\Resource\Item|null
     */
    public function includeCustomerOrder(Order $order)
    {
        if (empty($order->pivot) || !$order->pivot instanceof CustomerOrder) {
            return null;
        }
        return $this->item($order->pivot, new CustomerOrderTransformer(), 'customer_order');
    }

    /**
     * @param Order         $order
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeCustomers(Order $order, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $customers = $order->customers()->take($limit)
                                        ->offset($offset)
                                        ->oldest('firstname')
                                        ->oldest('lastname')
                                        ->get();
        return $this->collection($customers, new CustomerTransformer(), 'customer');
    }

    /**
     * @param Order $order
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeProducts(Order $order)
    {
        return $this->collection($order->items, new ProductTransformer(), 'product');
    }
}