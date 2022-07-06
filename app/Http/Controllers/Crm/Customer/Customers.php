<?php

namespace App\Http\Controllers\Crm\Customer;


use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Group;
use App\Transformers\CustomerTransformer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;

class Customers extends Controller
{
    /**
     * @var string
     */
    public $search;

    /**
     *
     * /customers
     *
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
        $groupFilters = $request->input('groups');
        # we want to filter by groups
        $groups = [];
        if (!empty($groupFilters)) {
            $explodedGroups = explode(',', $groupFilters);
            $groups = Group::where('company_id', $company->id)->whereIn('uuid', $explodedGroups)->pluck('id')->all();
        }
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        if (empty($search) || !empty($groups)) {
            # no search parameter
            $paginator = $company->customers()->with(['contacts'])
                                                ->when($groups, function ($query) use ($groups) {
                                                    return $query->whereIn('id', function ($query) use ($groups) {
                                                        $query->select('customer_id')
                                                                ->from('customer_group')
                                                                ->whereIn('group_id', $groups);
                                                    });
                                                })
                                                ->oldest('firstname')
                                                ->oldest('lastname')
                                                ->paginate($limit);
            # get the customers
        } else {
            # searching for something
            $paginator = \App\Models\Customer::search($search)
                                            ->where('company_id', $company->id)
                                            ->paginate($limit);
        }
        $resource = new Collection($paginator->getCollection(), new CustomerTransformer(), 'customer');
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
            'firstname' => 'required|max:30',
            'lastname' => 'nullable|max:30',
            'email' => 'nullable|email|max:80',
            'phone' => 'nullable|max:30',
            'fields' => 'nullable|array',
            'fields.*.id' => 'required|string',
            'fields.*.value' => 'required|string|max:100'
        ]);
        # validate the request
        if (!$request->has('email') && !$request->has('phone')) {
            throw new \UnexpectedValueException('You need to supply either a phone number, or an email.');
        }
        $contacts = [];
        # the contact details
        if ($request->has('fields')) {
            # we have contact fields to set
            $inputFields = collect($request->input('fields', []));
            $fields = $inputFields->map(function ($entry) { return $entry['id']; })->all();
            $values = $inputFields->map(function ($entry) { return $entry['value']; })->all();
            # set the fields and their values
            $contactFields = $company->contactFields()->whereIn('uuid', $fields)->get();
            # try to get the contact fields
            for ($i = 0; $i < count($fields); $i++) {
                if (empty($values[$i])) {
                    # no value
                    continue;
                }
                $contactField = $contactFields->where('uuid', $fields[$i])->first();
                # get the field
                if (empty($contactField)) {
                    # not found
                    continue;
                }
                $contacts[$contactField->id] = ['value' => $values[$i]];
            }
        }
        
        $customer = $company->customers()->create([
            'firstname' => $request->input('firstname'),
            'lastname' => $request->input('lastname'),
            'phone' => $request->input('phone', null),
            'email' => $request->input('email', null),
            // 'business_name' => $request->input('businessName', null),
            // 'business_address' => $request->input('businessAddress', null),
        ]);
        # create the model
        if (!empty($contacts)) {
            $customer->contacts()->sync($contacts);
            # add the contact information.
        }
        $resource = new Item($customer, new CustomerTransformer(), 'customer');
        return response()->json($fractal->createData($resource)->toArray(), 201);
    }

    public function customerDetails($phone_number , Manager $fractal){
        // $company = $this->company($request);
        $customer = \App\Models\Customer::where('phone', $phone_number)->with('contacts')->firstOrFail();
        $resource = new Item($customer, new CustomerTransformer(), 'customer');
        return response()->json($fractal->createData($resource)->toArray(), 201);

        // return response()->json(['success' => true , 'data' => compact('customer')]);
        
    }

    public function customerDetailsWithEmail($email , Manager $fractal){
        // $company = $this->company($request);
        $customer = \App\Models\Customer::where('email', $email)->firstOrFail();

        $resource = new Item($customer, new CustomerTransformer(), 'customer');
        
        return response()->json($fractal->createData($resource)->toArray(), 201);
        
    }
}