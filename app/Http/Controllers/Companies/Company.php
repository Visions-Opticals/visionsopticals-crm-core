<?php

namespace App\Http\Controllers\Companies;


use App\Dorcas\Common\SupportsBankAccounts;
use App\Events\Invites\InviteCreated;
use App\Events\ModuleAccess\AccessGrantedEvent;
use App\Events\ModuleAccess\AccessRequestedEvent;
use App\Exceptions\AccountExistsException;
use App\Exceptions\ApplicationAccessDeniedException;
use App\Exceptions\DeletingFailedException;
use App\Exceptions\RecordNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Invite;
use App\Models\Plan;
use App\Models\User;
use App\Transformers\CompanyTransformer;
use App\Transformers\InviteTransformer;
use App\Transformers\UserAccessGrantTransformer;
use App\Transformers\UserTransformer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;

class Company extends Controller
{
    use SupportsBankAccounts;
    
    /**
     * @var array
     */
    protected $updateFields = [
        'registration' => 'reg_number',
        'name' => 'name',
        'email' => 'email',
        'website' => 'website',
        'phone' => 'phone',
        'extra_data' => 'extra_data',
        'logo_url' => 'logo_url'
    ];

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request, Manager $fractal, string $id)
    {
        $company = \App\Models\Company::withTrashed()->where('uuid', $id)->firstOrFail();
        # retrieve the company
        $permanently = strtolower((string) $request->input('delete_permanently', 'no'));
        # set it
        if ($permanently === 'yes' || $permanently === '1' || $permanently === 'true') {
            $status = (clone $company)->forceDelete();
        } else {
            # a regular delete
            $status = $company->delete();
        }
        if (!$status) {
            throw new DeletingFailedException('Could not delete the company');
        }
        $resource = new Item($company, new CompanyTransformer(), 'company');
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
     */
    public function index(Request $request, Manager $fractal, string $id)
    {
        $company = \App\Models\Company::where('uuid', $id)->firstOrFail();
        # retrieve the company
        $resource = new Item($company, new CompanyTransformer(), 'company');
        # get the resource
        return response()->json($fractal->createData($resource)->toArray());
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function indexFromAuth(Request $request, Manager $fractal)
    {
        $company = $this->company($request, true, false);
        # retrieve the company
        $resource = new Item($company, new CompanyTransformer(), 'company');
        # get the resource
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCompany(Request $request, Manager $fractal, string $id)
    {
        $company = \App\Models\Company::where('uuid', $id)->firstOrFail();
        # retrieve the company
        $this->validate($request, [
            'registration' => 'nullable|max:30',
            'name' => 'nullable|max:80',
            'email' => 'nullable|email|max:100',
            'website' => 'nullable|max:100',
            'phone' => 'nullable|string|max:30',
            'logo' => 'nullable|image',
            'extra_data' => 'nullable|array'
        ]);
        # validate the request
        if ($request->has('logo')) {
            # we're requesting a file upload
            if (!empty($company->logo_url)) {
                # attempt to remove the file
                Storage::disk(config('filesystems.default'))->delete($company->logo_url);
            }
            $path = $request->file('logo')->store('business-logos');
            $request->request->set('logo_url', $path);
        }
        $this->updateModelAttributes($company, $request);
        # update the attributes
        $company->saveOrFail();
        # save the changes
        $resource = new Item($company, new CompanyTransformer(), 'company');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function update(Request $request, Manager $fractal)
    {
        $company = $this->company();
        # get the company
        $this->validate($request, [
            'registration' => 'nullable|max:30',
            'name' => 'nullable|max:80',
            'email' => 'nullable|email|max:100',
            'website' => 'nullable|max:100',
            'phone' => 'nullable|string|max:30',
            'logo' => 'nullable|image',
            'extra_data' => 'nullable|array'
        ]);
        # validate the request
        if ($request->has('logo')) {
            # we're requesting a file upload
            if (!empty($company->logo_url)) {
                # attempt to remove the file
                Storage::disk(config('filesystems.default'))->delete($company->logo_url);
            }
            // $path = $request->file('logo')->store('business-logos');
            // $request->request->set('logo_url', $path);

            $path =  $request->file('logo')->store('store-' . $company->uuid.'/vompany' ,'s3');

            Storage::disk('s3')->setVisibility($path , 'public');
    
            $pathUrl = Storage::disk('s3')->url($path);
            $request->request->set('logo_url', $pathUrl);
        }

        if ($request->has('extend_subscription')) {
            $currentExpiry = empty($company->access_expires_at) || Carbon::now()->greaterThan($company->access_expires_at) ?
                Carbon::now() : $company->access_expires_at;
            # set the current expiry
            if ($request->extend_period_type === 'months') {
                $company->access_expires_at = $currentExpiry->addMonths($request->extend_period_count)->endOfDay();
            } else {
                $company->access_expires_at = $currentExpiry->addYears($request->extend_period_count)->endOfDay();
            }
        }

        if ($request->has('update_expiry')) {
            $company->access_expires_at = $request->input('access_expires_at');
        }


        $this->updateModelAttributes($company, $request);
        # update the attributes
        $company->saveOrFail();
        # save the changes
        $resource = new Item($company, new CompanyTransformer(), 'company');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Throwable
     */
    public function extendPlan(Request $request, Manager $fractal, string $id)
    {
        $this->validate($request, [
            'transaction' => 'required|array',
            'transaction.reference' => 'required|string',
            'transaction.processor' => 'required|string',
            'transaction.currency' => 'required|string|size:3',
            'transaction.amount' => 'required|numeric',
            'transaction.is_successful' => 'required|numeric|in:0,1',
            'transaction.extra_data' => 'nullable|array'
        ]);
        # validate the request
        $company = \App\Models\Company::with('plan')->where('uuid', $id)->firstOrFail();
        # get the company
        $plan = $company->plan;
        # get the plan
        if (empty($plan)) {
            throw new \UnexpectedValueException('We could not find your current account pricing plan.');
        }
        $transaction = $company->billPayments()->firstOrNew([
            'reference' => $request->transaction['reference'],
            'processor' => $request->transaction['processor']
        ]);
        # get the transaction object
        if ($transaction->currency !== null) {
            # value was previously granted
            $resource = new Item($company, new CompanyTransformer(), 'company');
            return response()->json($fractal->createData($resource)->toArray(), 200);
        }
        $transaction->plan_id = $plan->id;
        $transaction->currency = $request->transaction['currency'];
        $transaction->amount = $request->transaction['amount'];
        $transaction->json_data = $request->transaction['extra_data'] ?? [];
        $transaction->is_successful = $request->transaction['is_successful'] === 1;
        # set the details on the transaction
        $transaction->saveOrFail();
        # save the transaction
        $currentExpiry = empty($company->access_expires_at) || Carbon::now()->greaterThan($company->access_expires_at) ? Carbon::now() : $company->access_expires_at;
        # set the current expiry
        if (!empty($request->transaction['extra_data']['card']['auth_code'])) {
            $extras = $company->extra_data ?: [];
            $extras[$transaction->processor . '_authorization_code'] = $request->transaction['extra_data']['card']['auth_code'];
            $company->extra_data = $extras;
        }
        if (empty ($request->transaction['access_expires_at'])) { //no specific access_expiry_date
            if ($transaction->amount >= $plan->price_yearly) {
                # person paid for the yearly plan
                $company->access_expires_at = $currentExpiry->addYear()->endOfDay();
            } elseif ($transaction->amount >= $plan->price_monthly) {
                $company->access_expires_at = $currentExpiry->addMonth()->endOfDay();
            }            
        } elseif (!empty($request->transaction['access_expires_at'])) {
            $company->access_expires_at = Carbon::parse($request->transaction['access_expires_at']);
        }

        # set the properties
        $company->saveOrFail();
        # save changes to the company
        $resource = new Item($company, new CompanyTransformer(), 'company');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Throwable
     */
    public function extendPlanFromAuth(Request $request, Manager $fractal)
    {
        $company = $this->company($request, true, false);
        return $this->extendPlan($request, $fractal, $company->uuid);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePlan(Request $request, Manager $fractal, string $id)
    {
        $this->validate($request, [
            'plan' => 'required|string',
            'plan_type' => 'nullable|in:monthly,yearly',
            'access_expires_at' => 'nullable|date_format:Y-m-d'
        ]);
        # validate the request
        $company = \App\Models\Company::where('uuid', $id)->firstOrFail();
        # get the company
        $planName = $request->input('plan', 'starter');
        $plan = Plan::where('name', 'like', $planName)->first();
        # get the plan'
        if (empty($plan)) {
            throw new \UnexpectedValueException('We could not find the selected plan: '.$planName);
        }
        $company->plan_id = $plan->id;
        $company->plan_type = $request->input('plan_type', 'monthly');
        if ($request->has('access_expires_at')) {
            $company->access_expires_at = $request->access_expires_at . ' 23:59:59';
        }
        # set the properties
        $company->saveOrFail();
        # save changes to the company
        $resource = new Item($company, new CompanyTransformer(), 'company');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $manager
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updatePlanFromAuth(Request $request, Manager $fractal)
    {
        $company = $this->company($request, true, false);
        return $this->updatePlan($request, $fractal, $company->uuid);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Request $request, Manager $fractal, string $id)
    {
        $company = \App\Models\Company::withCount([
                                            'contactFields',
                                            'customers',
                                            'departments',
                                            'employees',
                                            'groups',
                                            'locations',
                                            'orders',
                                            'orders AS orders_completed_count' => function ($query) {
                                                $query->whereIn('id', function ($query) {
                                                    $query->select('order_id')
                                                            ->from('customer_order')
                                                            ->where('is_paid', 1);
                                                });
                                            },
                                            'products',
                                            'services',
                                            'teams'
                                        ])
                                        ->where('uuid', $id)
                                        ->firstOrFail();
        # get the company
        $resource = new Item($company, new CompanyTransformer(), 'company');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }

    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function statusFromAuth(Request $request, Manager $fractal)
    {
        $company = $this->company($request, true, false);
        # retrieve the company
        return $this->status($request, $fractal, $company->uuid);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchUsers(Request $request, Manager $fractal)
    {
        $company = $request->user()->company;
        # get the company the user is tied to
        $search = $request->query('search');
        # get the search term in the query, if any
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        if (empty($search)) {
            # no search parameter
            $paginator = $company->users()->oldest('firstname')->oldest('lastname')->paginate($limit);
            
        } else {
            # searching for something
            $paginator = $company->users()->withTrashed()
                                            ->search($search)
                                            ->orderBy('firstname')
                                            ->orderBy('lastname')
                                            ->paginate($limit);
        }
        # get the users
        $transformer = new UserTransformer();
        $transformer->setDefaultIncludes(['company', 'roles']);
        $resource = new Collection($paginator->getCollection(), $transformer, 'user');
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
     */
    public function createUser(Request $request, Manager $fractal)
    {
        $this->validate($request, [
            'email' => 'required|email|max:80|unique:users,email',
            'password' => 'required|min:3',
            'firstname' => 'required|max:30',
            'lastname' => 'required|max:30',
            'phone' => 'nullable|max:30',
            'extra_configurations' => 'nullable|array',
            'employee_id' => 'nullable|string',
        ]);
        # validate the request
        $company = $request->user()->company;
        # get the company the user is tied to
        $plan = $company->plan;
        # get the plan -- only those on the premium plan can call this endpoint
        /*if ($plan->name !== 'premium') {
            throw new ApplicationAccessDeniedException('This feature is only available to those on the Premium plan.');
        }*/
        $employee = null;
        if ($request->has('employee_id')) {
            $employee = Employee::where('uuid', $request->input('employee_id'))->first();
        }
        $user = null;
        DB::transaction(function () use ($company, $employee, $request, &$user) {
            $firstUser = $company->users()->oldest()->first();
            $user = $company->users()->create([
                'firstname' => $request->input('firstname'),
                'lastname' => $request->input('lastname'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'phone' => $request->input('phone'),
                'partner_id' => empty($firstUser) ? null : $firstUser->partner_id,
                'is_employee' => 1,
                'extra_configurations' => $request->input('extra_configurations', [])
            ]);
            # we need to create the user
            if (!empty($employee)) {
                $employee->user_id = $user->id;
                $employee->save();
            }
        });
        $transformer = new UserTransformer();
        $transformer->setDefaultIncludes(['company']);
        $resource = new Item($user, $transformer, 'user');
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sentInvites(Request $request, Manager $fractal)
    {
        $company = $request->user()->company;
        # get the company the user is tied to
        $search = $request->query('search');
        # get the search term in the query, if any
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $pagingAppends = ['limit' => $limit];
        # append values for the pagination
        $filters = $request->input('filters');
        # the available filters -- comma separated list of the filters to enable
        if (!empty($filters) && is_string($filters)) {
            $filters = explode(',', $filters);
            # explode the filter to get the keys
        }
        $paginator = Invite::when($search, function ($query) use ($search) {
                                return $query->where(function ($query) use ($search) {
                                    $query->where('email', 'like', '%'.$search.'%')
                                        ->orWhere('firstname', 'like', '%'.$search.'%')
                                        ->orWhere('lastname', 'like', '%'.$search.'%')
                                        ->orWhere('status', 'like', '%'.$search.'%');
                                });
                            })
                            ->when($filters, function ($query) use ($filters) {
                                return $query->whereIn('status', $filters);
                            })
                            ->where('inviter_type', (new \App\Models\Company)->getMorphClass())
                            ->where('inviter_id', $company->id)
                            ->latest()
                            ->paginate($limit);
        # get the paginator
        $resource = new Collection($paginator->getCollection(), new InviteTransformer(), 'invite');
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
     */
    public function invite(Request $request, Manager $fractal)
    {
        $this->validate($request, [
            'email' => 'required|email',
            'firstname' => 'required|string|max:30',
            'lastname' => 'required|string|max:30',
            'roles' => 'nullable|array',
            'roles.*' => 'required_with:roles|string'
        ]);
        # validate the request
        $company = $request->user()->company;
        # the authenticated company
        if (User::where('email', $request->input('email'))->count() > 0) {
            throw new AccountExistsException(
                'An account with the email "' . $request->input('email') . '" already exists.'
            );
        }
        $properties = ['is_verified' => 1];
        $roles = [];
        $config = [
            'action' => 'invite_user',
            'roles' => collect($roles)->unique()->all(),
            'properties' => $properties,
            'inviting_user_id' => $request->user()->id,
        ];
        $invite = $company->invites()->create([
            'firstname' => $request->input('firstname'),
            'lastname' => $request->input('lastname'),
            'email' => $request->input('email'),
            'message' => $request->input('message', ''),
            'config_data' => $config
        ]);
        # create the invite
        event(new InviteCreated($invite));
        # trigger the event
        $resource = new Item($invite, new InviteTransformer(), 'invite');
        return response()->json($fractal->createData($resource)->toArray(), 201);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteInvite(Request $request, Manager $fractal, string $id)
    {
        $company = $request->user()->company;
        # get the company the user is tied to
        $invite = Invite::where('inviter_type', (new \App\Models\Company)->getMorphClass())
                        ->where('inviter_id', $company->id)
                        ->where('uuid', $id)
                        ->first();
        # get the invite
        if (!(clone $invite)->delete()) {
            throw new DeletingFailedException('Errors while deleting the invite.');
        }
        $transformer = new InviteTransformer();
        $transformer->setDefaultIncludes([])->setAvailableIncludes([]);
        $resource = new Item($invite, $transformer, 'invite');
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchAccessGrantRequests(Request $request, Manager $fractal)
    {
        $company = $request->user()->company;
        # get the company the user is tied to
        $search = $request->query('search');
        # get the search term in the query, if any
        $limit = $request->query('limit', 10);
        # the maximum number of customers to return
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        $allowedStatuses = ['accepted', 'pending', 'rejected'];
        $statuses = $request->input('statuses', '');
        if (!empty($statuses)) {
            $statuses = explode(',', $statuses);
            $statuses = collect($statuses)->filter(function ($status) use ($allowedStatuses) {
                return in_array($status, $allowedStatuses);
            })->all();
        }
        $paginator = $company->userAccessGrants()->with(['user'])
                                                ->when($search, function ($query) use ($search) {
                                                    return $query->whereIn('user_id', function ($query) use ($search) {
                                                        $query->select('id')
                                                                ->from('users')
                                                                ->where('firstname', 'like', '%' . $search . '%')
                                                                ->orWhere('lastname', 'like', '%' . $search . '%')
                                                                ->orWhere('email', 'like', '%' . $search . '%')
                                                                ->orWhere('phone', 'like', '%' . $search . '%');
                                                    });
                                                })
                                                ->when($statuses, function ($query) use ($statuses) {
                                                    return $query->whereIn('status', $statuses);
                                                })
                                                ->latest()
                                                ->paginate($limit);
        $transformer = new UserAccessGrantTransformer();
        $transformer->setDefaultIncludes(['user']);
        $resource = new Collection($paginator->getCollection(), $transformer, 'access_grant');
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
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAccessGrantRequest(Request $request, Manager $fractal, string $id)
    {
        $company = $request->user()->company;
        # get the company the user is tied to
        $accessGrant = $company->userAccessGrants()->where('uuid', $id)->first();
        # get the access grant
        if (empty($accessGrant)) {
            throw new RecordNotFoundException('No matching access grant found.');
        }
        if (!$accessGrant->delete()) {
            throw new DeletingFailedException('Could not remove the access grant request.');
        }
        $resource = new Item($accessGrant, new UserAccessGrantTransformer(), 'access_grant');
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAccessGrantRequest(Request $request, Manager $fractal, string $id)
    {
        $this->validate($request, [
            'modules' => 'required|array',
            'modules.*' => 'required|string|min:1',
            'status' => 'required|string|in:accepted,rejected'
        ]);
        # validate the request
        $company = $request->user()->company;
        # get the company the user is tied to
        $accessGrant = $company->userAccessGrants()->where('uuid', $id)->first();
        # get the access grant
        if (empty($accessGrant)) {
            throw new RecordNotFoundException('No matching access grant found.');
        }
        $configuration = $accessGrant->extra_json;
        $newStatus = $request->input('status');
        if ($newStatus === 'rejected' && empty($configuration['modules'])) {
            # for rejections, we remove the request
            return $this->deleteAccessGrantRequest($request, $fractal, $id);
        } elseif ($newStatus === 'rejected' && !empty($configuration['modules'])) {
            # remove the rejected modules from the pending list
            $toBeRemoved = $request->input('modules', []);
            $newPendingModules = collect($configuration['pending_modules'])->filter(function ($name) use ($toBeRemoved) {
                return !in_array($name, $toBeRemoved);
            })->all();
            
        } elseif ($newStatus === 'accepted') {
            $configuration['modules'] = array_unique(array_merge($configuration['modules'], $request->input('modules', [])));
            # we set the approved modules
            $newPendingModules = collect($configuration['pending_modules'])->filter(function ($name) use ($configuration) {
                return !in_array($name, $configuration['modules']);
            })->all();
            $accessGrant->status = $newStatus;
        }
        $configuration['pending_modules'] = $newPendingModules;
        $accessGrant->extra_json = $configuration;
        $triggerEvent = false;
        if (empty($accessGrant->access_token)) {
            # no access token on the permission -- set a token on it
            $accessGrant->access_token = $request->user()->createToken('access_grant_token_' . $accessGrant->uuid)->accessToken;
            $triggerEvent = true;
        }
        if (!$accessGrant->save()) {
            throw new \RuntimeException('Could not save the access grant request. Please try again later.');
        }
        if ($triggerEvent) {
            event(new AccessGrantedEvent($accessGrant));
        }
        $transformer = new UserAccessGrantTransformer();
        $transformer->setDefaultIncludes(['user']);
        $resource = new Item($accessGrant, $transformer, 'access_grant');
        return response()->json($fractal->createData($resource)->toArray());
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestAccess(Request $request, Manager $fractal, string $id)
    {
        $this->validate($request, [
            'modules' => 'required|array',
            'modules.*' => 'required|string|min:1',
            'status' => 'nullable|string|in:pending,accepted,rejected'
        ]);
        # validate the request
        $company = \App\Models\Company::where('uuid', $id)->first();
        # get the company
        if (empty($company)) {
            throw new RecordNotFoundException('No matching company profile found.');
        }
        $user = $request->user();
        # get the requesting user
        $existingAccessGrant = $user->companyAccessGrants()->firstOrNew(['company_id' => $company->id]);
        if (empty($existingAccessGrant->id)) {
            # brand new
            $existingAccessGrant->extra_json = ['pending_modules' => $request->input('modules', []), 'modules' => []];
            $existingAccessGrant->status = $request->input('status', 'pending');
        }
        $configuration = $existingAccessGrant->extra_json;
        if (!empty($configuration['modules'])) {
            # we already have some previously approved modules
            $defaultPendingModules = array_merge($configuration['pending_modules'], $request->input('modules'));
            $newPendingModules = collect($defaultPendingModules)->filter(function ($name) use ($configuration) {
                return !in_array($name, $configuration['modules']);
            })->all();
            $configuration['pending_modules'] = $newPendingModules;
        }
        $existingAccessGrant->extra_json = $configuration;
        if (!$existingAccessGrant->save()) {
            throw new \RuntimeException('Could not save the module access grant request. Please try again.');
        }
        event(new AccessRequestedEvent($existingAccessGrant));
        $resource = new Item($existingAccessGrant, new UserAccessGrantTransformer(), 'access_grant');
        return response()->json($fractal->createData($resource)->toArray());
    }
}
