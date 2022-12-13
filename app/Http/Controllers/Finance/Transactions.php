<?php


namespace App\Http\Controllers\Finance;


use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Transformers\UnverifiedTransactionsTransformer;
use Illuminate\Http\Request;
use League\Fractal\Manager;
use App\Exceptions\DeletingFailedException;
use App\Exceptions\RecordNotFoundException;
use App\Transformers\AccountingEntryTransformer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;

class Transactions extends Controller
{
    public function search(Request $request, Manager $fractal)
    {
        $search = $request->query('search');

        # get the search term in the query, if any

        $limit = $request->query('limit', 10);

        # the maximum number of customers to return

        $company = $this->company($request);
        # retrieve the company
        //$id = $request->input('id');
        # account we want to filter by
        $pagingAppends = ['limit' => $limit];
        # append values for the paginator
        if (empty($search)) {
            $builder = $company->unverifiedTransactions();
        }
        $paginator = $builder->when($search, function ($query) use ($search) {
            return $query
	            ->where('status','unverified')
	            ->where('entry_type', 'like', '%'.$search.'%')
                ->orWhere('remarks', 'like', '%'.$search.'%')
                ->orWhere('currency', 'like', '%'.$search.'%')
                ->orWhere('status', 'like', '%'.$search.'%');
        })
            ->latest()
            ->paginate($limit);
        $resource = new Collection($paginator->getCollection(), new UnverifiedTransactionsTransformer(), 'transactions');
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

    public function verify(Request $request, Manager $fractal){
	    $this->validate($request, [
		    'account_credit' => 'required|string',
		    'account_debit' => 'required|string',
		    'currency' => 'nullable|string|size:3',
		    'amount' => 'required|numeric',
		    'remark' => 'nullable|string|max:300',
		    'created_at' => 'nullable|date_format:Y-m-d',
	    ]);

	    # the configuration for loading appropriate account
	    $company = $this->company();
	    $account_credit = $company->accountingAccounts()->where('uuid', $request->account_credit)->first();
	    $account_debit = $company->accountingAccounts()->where('uuid', $request->account_debit)->first();
	    if (empty($account_credit) || empty($account_debit)) {
		    throw new RecordNotFoundException('Could not find the specified account to add the entry in.');
	    }
	    if ($request->has('created_at')) {
		    $createdAt = Carbon::createFromFormat('Y-m-d', $request->input('created_at'));
	    } else {
		    $createdAt = Carbon::now();
	    }
	    $entry = null;
	    $entry2 = null;
	    DB::transaction(function () use ($account_credit,$account_debit ,$createdAt, &$entry, &$entry2, $request) {
		    $entry = $account_debit->entries()->create([
			    'entry_type' => $account_debit->entry_type,
			    'currency' => strtoupper($request->input('currency', 'NGN')),
			    'amount' => $request->amount,
			    'memo' => $request->input('memo', null),
			    'source_type' => $request->input('source_type', 'manual'),
			    'source_info' => $request->input('source_info', 'manual'),
			    'created_at' => $createdAt->format('Y-m-d H:i:s')
		    ]);
		    # create the first entry
		    $entry2 = $account_credit->entries()->create([
			    'entry_type' => $account_credit->entry_type,
			    'currency' => strtoupper($request->input('currency', 'NGN')),
			    'amount' => $request->amount,
			    'memo' => $request->input('memo', null),
			    'source_type' => $request->input('source_type', 'manual'),
			    'source_info' => $request->input('source_info', 'manual'),
			    'created_at' => $createdAt->format('Y-m-d H:i:s')
		    ]);
		    # create the second entry
	    });
	    $resource = new Item($entry, new AccountingEntryTransformer(), 'entry');
	    return response()->json($fractal->createData($resource)->toArray(), 201);
    }

    public function generateReport(Request $request, Manager $fractal)
    {

            $transactions  =  PaymentTransaction::with('customer','order')->whereDate('created_at','>=',request()->start_date)
                ->whereDate('created_at','<=',request()->end_date)
                ->orderBy('created_at','desc')
                ->get();

            return  $transactions ;


//        $resource = new Item($transactions, new AccountingEntryTransformer(), 'entry');
//        return response()->json($fractal->createData($resource)->toArray(), 201);


    }
}