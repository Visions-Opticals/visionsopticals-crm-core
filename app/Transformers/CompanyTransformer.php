<?php

namespace App\Transformers;


use App\Dorcas\Common\APITransformerTrait;
use App\Models\Company;
use Carbon\Carbon;
use League\Fractal\ParamBag;
use League\Fractal\TransformerAbstract;

class CompanyTransformer extends TransformerAbstract
{
    use APITransformerTrait;
    
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = [
        'adverts',
        'customers',
        'deals',
        'departments',
        'domains',
        'domain_issuances',
        'employees',
        'groups',
        'integrations',
        'invites',
        'locations',
        'orders',
        'plan',
        'services',
        'teams',
        'users',
        'user_access_grants'
    ];

    /**
     * Include resources without needing it to be requested.
     *
     * @var array
     */
    protected $defaultIncludes = ['adverts', 'plan'];

    /**
     * @param Company $company
     *
     * @return array
     */
    public function transform(Company $company)
    {
        $counts = [];
        foreach ($company->attributesToArray() as $attribute => $count) {
            if (!ends_with($attribute, '_count')) {
                continue;
            }
            $counts[substr($attribute, 0, -6)] = $count;
        }
        $plan = $company->plan;
        # get the plan


        /*if ($plan->price_monthly > 0) {
            $expiry = !empty($company->access_expires_at) ? $company->access_expires_at : Carbon::now()->subMinute();
        } else {
            $expiry = Carbon::now()->addYears(10);
        }*/

        $companyAccessExpiresAt = $company->access_expires_at;
        $companyCreatedAt = $company->created_at;
        /*if ($plan->price_monthly > 0) {
            $expiry = !empty($companyAccessExpiresAt) ? $companyAccessExpiresAt : Carbon::now()->subMinute();
        } else {
            $expiry = !empty($companyAccessExpiresAt) ? $companyAccessExpiresAt->addMonth()->endOfDay() : $companyCreatedAt->addMonth()->endOfDay();
        }*/


        // Free for all and take into consideration existing people
        /*$currentExpiry = Carbon::parse($company->access_expires_at);
        $currentStartDate = Carbon::parse($company->created_at);
        $dDay = Carbon::parse("30th March 2020 9:12 PM");

        $expiry = $dDay->lessThan($currentStartDate) ? Carbon::now()->addYear()->subDay()->endOfDay() : $companyAccessExpiresAt;*/
        # for all old registrations, grant them 1 year from today expiry, other wise, leave as is

        $expiry = $companyAccessExpiresAt;

        $data = [
            'embeds' => $this->getEmbeds(),
            'id' => $company->uuid,
            'plan_type' => $company->plan_type,
            'registration' => $company->reg_number,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'logo' => $company->logo_url,
            'extra_data' => $company->extra_data ?: [],
            'prefix' => $company->prefix,
            'access_expires_at' => $expiry->toIso8601String(),
            'is_trashed' => $company->deleted_at !== null,
            'trashed_at' => !empty($company->deleted_at) ? $company->deleted_at->toIso8601String() : null,
            'updated_at' => !empty($company->updated_at) ? $company->updated_at->toIso8601String() : null,
            'created_at' => $company->created_at->toIso8601String(),
            'links' => [
                'self' => url('/companies', [$company->uuid])
            ]
        ];
        if (!empty($counts)) {
            $data['counts'] = $counts;
        }
        return $data;
    }
    
    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeAdverts(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 30);
        $adverts = $company->adverts()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($adverts, new AdvertTransformer(), 'advert');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeCustomers(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $customers = $company->customers()->take($limit)
                                            ->offset($offset)
                                            ->oldest('firstname')
                                            ->oldest('lastname')
                                            ->get();
        return $this->collection($customers, new CustomerTransformer(), 'customer');
    }
    
    /**
     * @param Company  $company
     * @param ParamBag $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeDeals(Company $company, ParamBag $params)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $deals = $company->deals()->with(['stages', 'customer'])->take($limit)->offset($offset)->latest()->get();
        # the deals data
        $transformer = (new DealTransformer())->setDefaultIncludes(['customer', 'stages']);
        return $this->collection($deals, $transformer, 'deal');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeDepartments(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $departments = $company->departments()->take($limit)->offset($offset)->oldest('name')->get();
        return $this->collection($departments, new DepartmentTransformer(), 'department');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeDomains(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $domains = $company->domains()->take($limit)->offset($offset)->oldest('domain')->get();
        return $this->collection($domains, new DomainTransformer(), 'domain');
    }
    
    /**
     * @param Company $partner
     *
     * @return \League\Fractal\Resource\Collection|null
     */
    public function includeDomainIssuances(Company $partner)
    {
        $issuances = $partner->domainIssuances;
        if (empty($issuances)) {
            return null;
        }
        return $this->collection($issuances, new DomainIssuanceTransformer(), 'domain_issuance');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeEmployees(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $employees = $company->employees()->take($limit)
                                            ->offset($offset)
                                            ->oldest('firstname')
                                            ->oldest('lastname')
                                            ->get();
        return $this->collection($employees, new EmployeeTransformer(), 'employee');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeGroups(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $groups = $company->groups()->take($limit)->offset($offset)->oldest('name')->get();
        return $this->collection($groups, new GroupTransformer(), 'group');
    }

    /**
     * @param Company $company
     *
     * @return \League\Fractal\Resource\Collection|null
     */
    public function includeIntegrations(Company $company)
    {
        if (empty($company->integrations)) {
            return null;
        }
        return $this->collection($company->integrations, new IntegrationTransformer(), 'integration');
    }
    
    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeInvites(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $builder = $company->invites();
        $filter = $params->get('status');
        if (!empty($filter)) {
            $builder->whereIn('status', $filter);
        }
        $invites = $builder->take($limit)->offset($offset)->latest()->get();
        return $this->collection($invites, new InviteTransformer(), 'invite');
    }

    /**
     * @param Company $company
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeLocations(Company $company)
    {
        $locations = $company->locations()->oldest('name')->get();
        return $this->collection($locations, new LocationTransformer(), 'location');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeOrders(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $orders = $company->orders()->take($limit)->offset($offset)->latest()->get();
        return $this->collection($orders, new OrderTransformer(), 'order');
    }

    /**
     * @param Company $company
     *
     * @return \League\Fractal\Resource\Item
     */
    public function includePlan(Company $company)
    {
        return $this->item($company->plan, new PlanTransformer(), 'plan');
    }

    /**
     * @param Company $company
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeServices(Company $company)
    {
        return $this->collection($company->services, new ServiceTransformer(), 'service');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeTeams(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $teams = $company->teams()->take($limit)->offset($offset)->oldest('name')->get();
        return $this->collection($teams, new TeamTransformer(), 'team');
    }

    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeUsers(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $users = $company->users()->take($limit)->offset($offset)->oldest('firstname')->get();
        $transformer = new UserTransformer();
        $transformer->setDefaultIncludes(['partner']);
        return $this->collection($users, $transformer, 'user');
    }
    
    /**
     * @param Company       $company
     * @param ParamBag|null $params
     *
     * @return \League\Fractal\Resource\Collection
     */
    public function includeUserAccessGrants(Company $company, ParamBag $params = null)
    {
        list($limit, $offset) = parse_fractal_params($params, 0, 10);
        $accessGrants = $company->userAccessGrants()->take($limit)->offset($offset)->oldest()->get();
        $transformer = new UserAccessGrantTransformer();
        $transformer->setDefaultIncludes(['user']);
        return $this->collection($accessGrants, $transformer, 'access_grant');
    }
}