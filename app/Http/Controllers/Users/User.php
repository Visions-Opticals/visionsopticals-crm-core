<?php

namespace App\Http\Controllers\Users;


use App\Events\AccountRegistered;
use App\Http\Controllers\Controller;
use App\Transformers\UserAccessGrantTransformer;
use App\Transformers\UserTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;

class User extends Controller
{
    /**
     * @var array
     */
    protected $updateFields = [
        'firstname' => 'firstname',
        'lastname' => 'lastname',
        'gender' => 'gender',
        'token' => 'remember_token',
        'user_type' => 'user_type',
        'phone' => 'phone',
        'password' => 'password',
        'address' => 'address',
        'is_partner' => 'is_partner',
        'is_professional' => 'is_professional',
        'is_verified' => 'is_verified',
        'extra_configurations' => 'extra_configurations',
    ];

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
        $column = $request->input('column', null);
        $value = $request->input('value', null);
        # get the data for filtering the query
        $allowedColumns = ['uuid', 'email', 'remember_token'];
        $usingColumn = strtolower($request->input('select_using', 'uuid'));
        $usingColumn = in_array($usingColumn, $allowedColumns) ? $usingColumn : $allowedColumns[0];
        # determine the column to select by.
        if (!empty($column) && empty($value)) {
            throw new \UnexpectedValueException('You passed a column filter but no value for it!');
        }
        $user = \App\Models\User::where($usingColumn, $id)
                                ->when($column, function ($query) use ($column, $value) {
                                    return $query->where($column, (string) $value);
                                })
                                ->firstOrFail();
        # retrieve the user
        $resource = new Item($user, new UserTransformer(), 'user');
        # get the resource
        if (!empty($request->bearerToken())) {
            $company = $request->user()->company;
            $grant = $company->userAccessGrants()->where('access_token', $request->bearerToken())->first();
            if (!empty($grant)) {
                $grantResource = new Item($grant, (new UserAccessGrantTransformer())->setDefaultIncludes(['user']), 'access_grant');
                $resource->setMetaValue('granted_for', $fractal->createData($grantResource)->toArray());
            }
        }
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
        $user = \App\Models\User::where('uuid', $id)->firstOrFail();
        # get the user
        $this->validate($request, [
            'firstname' => 'nullable|max:30',
            'lastname' => 'nullable|max:30',
            'token' => 'nullable|max:100',
            'gender' => 'nullable|in:female,male',
            'phone' => 'nullable|numeric',
            'password' => 'nullable|string',
            'address' => 'nullable|string',
            'extra_configurations' => 'nullable|array',
            'photo' => 'nullable|image|max:4096',
            'is_partner' => 'nullable|numeric',
            'is_professional' => 'nullable|numeric',
            'is_verified' => 'nullable|numeric',
        ]);
        # validate the request
        $this->updateModelAttributes($user, $request);
        # update the attributes
        if ($request->has('password')) {
            $user->password = Hash::make($request->input('password'));
        }
        if ($request->has('photo')) {
            if (!empty($user->photo_url)) {
                Storage::disk(config('filesystems.default'))->delete($user->photo_url);
            }
            $user->photo_url = $request->file('photo')->store('profile-photos');
            # update the profile photo
        }
        $user->saveOrFail();
        # save the changes
        $resource = new Item($user, new UserTransformer(), 'user');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
//     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyUser(Request $request, Manager $fractal, string $id)
    {
        $user = \App\Models\User::where('uuid', $id)->firstOrFail();
        # get the user
        $user->is_verified = 1;
        $user->saveOrFail();
        # save the changes
        $resource = new Item($user, new UserTransformer(), 'user');

        return view('test');
//        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
    
    /**
     * @param Request $request
     * @param Manager $fractal
     * @param string  $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerificationEmail(Request $request, Manager $fractal, string $id)
    {
        $user = \App\Models\User::where('uuid', $id)->firstOrFail();

        # get the user
        event(new AccountRegistered($user, $user->company));
        # trigger the event
        $resource = new Item($user, new UserTransformer(), 'user');
        return response()->json($fractal->createData($resource)->toArray(), 200);
    }
}