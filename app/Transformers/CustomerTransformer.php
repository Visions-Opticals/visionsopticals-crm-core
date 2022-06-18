<?php

namespace App\Transformers;


use App\Dorcas\Common\APITransformerTrait;
use App\Models\Customer;
use App\Models\CustomerOrder;
use Carbon\Carbon;
use League\Fractal\ParamBag;
use League\Fractal\TransformerAbstract;

class CustomerTransformer extends TransformerAbstract
{
    use APITransformerTrait;
    
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = ['company', 'contacts', 'customer_order', 'deals', 'groups', 'notes', 'orders'];

    /**
     * Include resources without needing it to be requested.
     *
     * @var array
     */
    protected $defaultIncludes = ['contacts', 'customer_order', 'groups'];

    /**
     * @param Customer $customer
     *
     * @return array
     */
    public function transform(Customer $customer)
    {
        $resource = [
            'embeds' => $this->getEmbeds(),
            'id' => $customer->uuid,
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'photo' => $customer->photo,
            'status' => 201,
            'created_at' => $customer->created_at->toIso8601String(),
            'links' => [
                'self' => url('/customers', [$customer->uuid])
            ]
        ];
        if (!empty($customer->pivot) && $customer->pivot instanceof CustomerOrder) {
            $resource['sale'] = [
                'is_paid' => $customer->pivot->is_paid,
                'paid_at' => !empty($customer->pivot->paid_at) ? $customer->pivot->paid_at->toIso8601String() : null
            ];
        }
        return $resource;
    }

    /**
     * @param Customer $customer
     *
     * @return \League\Fractal\Resource\Item
     */
    public function includeCompany(Customer $customer)
    {
        return $this->item($customer->company, new CompanyTransformer(), 'company');
    }

    /**
     * @param Customer $customer
     *
     * @return \League\Fractal\Resource\Collection|null
     */
    public function includeContacts(Customer $customer)
    {
        $fields = $customer->contacts;
        return !empty($fields) ? $this->collection($fields, new ContactFieldTransformer(), 'contact') : null;
    }

    /**
     * @param Customer $customer
     *
     * @return \League\Fractal\Resource\Item|null
     */
    public function includeCustomerOrder(Customer $customer)
    {
        if (empty($customer->pivot) || !$customer->pivot instanceof CustomerOrder) {
            return null;
        }
        return $this->item($customer->pivot, new CustomerOrderTransformer(), 'customer_order');
    }
    
    /**
     * @param Customer $customer
     * @param ParamBag $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeDeals(Customer $customer, ParamBag $params)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $deals = $customer->deals()->with(['stages'])->take($limit)->offset($offset)->latest()->get();
        return $this->collection($deals, new DealTransformer(), 'deal');
    }

    /**
     * @param Customer $customer
     * @param ParamBag $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeGroups(Customer $customer, ParamBag $params)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $groups = $customer->groups()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($groups, new GroupTransformer(), 'group');
    }

    /**
     * @param Customer $customer
     * @param ParamBag $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeNotes(Customer $customer, ParamBag $params)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $notes = $customer->notes()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($notes, new CustomerNoteTransformer(), 'note');
    }

    /**
     * @param Customer $customer
     * @param ParamBag $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeOrders(Customer $customer, ParamBag $params)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $orders = $customer->orders()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($orders, new OrderTransformer(), 'order');
    }
}