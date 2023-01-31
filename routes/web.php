<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

use App\Dorcas\Enum\PermissionName;
use App\Models\Product;
use Illuminate\Support\Facades\Route;


// Test database connection
Route::get('/test', function () {
    echo "Dorcas Core Works!";
});

Route::post('/auth/email', 'Auth\Authorize@authorizeUserByEmail');

Route::post('/create_domains', 'Auth\Register@registerBusinessDomain');



use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

// Test database connection
Route::get('/connection', function () {

    try {
        DB::connection()->getPdo();
        echo "Connected successfully to: " . DB::connection()->getDatabaseName();
    } catch (\Exception $e) {
        die("Could not connect to the database (".DB::connection()->getDatabaseName()."). Please check your configuration. error:" . $e );
    }

    //return view('welcome');
});



Route::post('setup','Setup\Init@setup');
/**
 * Routes from the Auth namespace
 */


Route::group(['namespace' => 'Auth'], function () {
    Route::post('/register', 'Register@register');
    Route::get('/oauth/authorize', 'Authorize@index');
    Route::put('/me/auth', 'Profile@updateAuthentication');
});

Route::group(['namespace' => 'Crm'], function () {
    Route::get('/customer/{phone_number}', 'Customer\Customers@customerDetails');
});

Route::group(['namespace' => 'Crm'], function () {
    Route::get('/customer-email/{email}', 'Customer\Customers@customerDetailsWithEmail');
});

Route::group(['namespace' => 'Invoicing'], function () {
    Route::get('/fetch_categories', 'Product\Product@fetchCategories');
    Route::get('/fetch_products/{category_id}', 'Product\Product@fetchProduct');
    Route::post('add_order_payment','Orders\Order@addOrderFromWhatsappProcessor');
});


//Route::group(['namespace' => 'Invoicing'], function () {
//    /**
//     * Product Endpoints
//     */
//    Route::post('/product/{id}/scan/', 'Product\Product@scanProductWithBarcode');
//
//});



/**
 * Route only available for Dorcas administrators
 */
Route::group(['middleware' => ['auth', 'administrator']], function () {
    Route::delete('/plans/{id}', 'Plans@delete');
    Route::put('/plans/{id}', 'Plans@update');
});

Route::group(['namespace' => 'Auth', 'middleware' => ['auth']], function () {
    Route::get('/', 'Profile@index');
    Route::get('/me', 'Profile@index');
    Route::post('/me', 'Profile@update');
    Route::put('/me', 'Profile@update');
    Route::get('/me/bank-accounts', 'Profile@searchBankAccounts');
    Route::post('/me/bank-accounts', 'Profile@createBankAccount');
    Route::delete('/me/bank-accounts/{id}', 'Profile@deleteBankAccount');
    Route::get('/me/bank-accounts/{id}', 'Profile@singleBankAccount');
    Route::put('/me/bank-accounts/{id}', 'Profile@updateBankAccount');
    Route::get('/me/access-requests', 'Profile@searchAccessGrantRequests');
    Route::delete('/me/access-requests', 'Profile@deleteAccessGrantRequest');
});

Route::put('/users/{id}/verify-account', 'Users\User@verifyUser');
Route::get('/users/{id}', 'Users\User@index');
Route::get('/verify-email/{id}', 'Users\User@verifyUser');

Route::group(['middleware' => ['auth']], function () {
    Route::get('companies', 'Companies\Companies@index');
    Route::get('companies/access-search', 'Companies\Companies@access_search');
    Route::get('companies/{id}', 'Companies\Company@index');
    Route::post('companies/{id}/extend-plan', 'Companies\Company@extendPlan');
    Route::get('companies/{id}/status', 'Companies\Company@status');
    Route::post('companies/{id}/update-plan', 'Companies\Company@updatePlan');
    Route::post('companies/{id}/access-grant-requests', 'Companies\Company@requestAccess');
    
    Route::get('/company', 'Companies\Company@indexFromAuth');
    Route::post('/company', 'Companies\Company@update');
    Route::put('/company', 'Companies\Company@update');
    
    Route::get('/company/access-grant-requests', 'Companies\Company@searchAccessGrantRequests');
    Route::delete('/company/access-grant-requests/{id}', 'Companies\Company@deleteAccessGrantRequest');
    Route::put('/company/access-grant-requests/{id}', 'Companies\Company@updateAccessGrantRequest');
    
    Route::get('/users', 'Companies\Company@searchUsers');
    Route::post('/company/users', 'Companies\Company@createUser');
    
    Route::group(['middleware' => ['permission:' . PermissionName::MANAGE_COMPANIES.'|'.PermissionName::MANAGE_MEMBERS]], function () {
        Route::delete('companies/{id}', 'Companies\Company@delete');
        Route::post('/companies/{id}', 'Companies\Company@updateCompany');
    });
    
    Route::get('/company/extend-plan', 'Companies\Company@extendPlanFromAuth');
    Route::get('/company/status', 'Companies\Company@statusFromAuth');
    Route::post('/company/update-plan', 'Companies\Company@updatePlanFromAuth');

    Route::get('companies/{id}/bills', 'Companies\BillPayments@index');
    Route::get('company/bills', 'Companies\BillPayments@indexFromAuth');
    Route::post('companies/{id}/bills', 'Companies\BillPayments@create');
    Route::post('company/bills', 'Companies\BillPayments@createFromAuth');
    
    Route::get('/company/contacts', 'Companies\CompanyContacts@index');
    Route::post('/company/contacts', 'Companies\CompanyContacts@create');
    Route::delete('/company/contacts/{id}', 'Companies\CompanyContacts@delete');
    Route::get('/company/contacts/{id}', 'Companies\CompanyContacts@single');
    Route::put('/company/contacts/{id}', 'Companies\CompanyContacts@update');

    Route::get('/company/departments', 'Companies\CompanyDepartments@index');
    Route::post('/company/departments', 'Companies\CompanyDepartments@create');
    Route::delete('/company/departments/{id}', 'Companies\CompanyDepartments@delete');
    Route::get('/company/departments/{id}', 'Companies\CompanyDepartments@single');
    Route::put('/company/departments/{id}', 'Companies\CompanyDepartments@update');
    Route::delete('/company/departments/{id}/employees', 'Companies\CompanyDepartments@removeEmployees');
    Route::post('/company/departments/{id}/employees', 'Companies\CompanyDepartments@addEmployees');

    Route::get('/company/employees', 'Companies\CompanyEmployees@index');
    Route::post('/company/employees', 'Companies\CompanyEmployees@create');
    Route::post('/company/employees/bulk', 'Companies\CompanyEmployees@createBulk');
    Route::delete('/company/employees/{id}', 'Companies\CompanyEmployees@delete');
    Route::get('/company/employees/{id}', 'Companies\CompanyEmployees@single');
    Route::put('/company/employees/{id}', 'Companies\CompanyEmployees@update');
    Route::delete('/company/employees/{id}/teams', 'Companies\CompanyEmployees@removeTeams');
    Route::post('/company/employees/{id}/teams', 'Companies\CompanyEmployees@addTeams');
    
    Route::get('/company/invites', 'Companies\Company@sentInvites');
    Route::post('/company/invites', 'Companies\Company@invite');
    Route::delete('/company/invites/{id}', 'Companies\Company@deleteInvite');

    Route::get('/company/locations', 'Companies\CompanyLocations@index');
    Route::post('/company/locations', 'Companies\CompanyLocations@create');
    Route::delete('/company/locations/{id}', 'Companies\CompanyLocations@delete');
    Route::get('/company/locations/{id}', 'Companies\CompanyLocations@single');
    Route::put('/company/locations/{id}', 'Companies\CompanyLocations@update');

    Route::get('/company/teams', 'Companies\CompanyTeams@index');
    Route::post('/company/teams', 'Companies\CompanyTeams@create');
    Route::delete('/company/teams/{id}', 'Companies\CompanyTeams@delete');
    Route::get('/company/teams/{id}', 'Companies\CompanyTeams@single');
    Route::put('/company/teams/{id}', 'Companies\CompanyTeams@update');
    Route::delete('/company/teams/{id}/employees', 'Companies\CompanyTeams@removeEmployees');
    Route::post('/company/teams/{id}/employees', 'Companies\CompanyTeams@addEmployees');

    Route::get('/countries', 'Common\Countries@index');
    Route::get('/countries/{id}', 'Common\Countries@single');

    Route::post('statistics', 'Statistics@read');

    Route::get('/states', 'Common\States@index');
    Route::get('/states/{id}', 'Common\States@single');
    
    Route::post('/users/{id}/resend-verification', 'Users\User@resendVerificationEmail');

    Route::post('/users/{id}', 'Users\User@update');
    Route::put('/users/{id}', 'Users\User@update');
});

Route::group(['namespace' => 'Coupons', 'prefix' => 'coupons'], function () {
    
    Route::get('/{id}', 'Coupons@index');
    
    Route::group(['middleware' => ['auth']], function () {
        Route::post('/', 'Coupons@createCoupons');
        Route::post('/{id}/redeem', 'Coupons@redeem');
    });
});

Route::group(['namespace' => 'Developers', 'prefix' => 'developers', 'middleware' => ['auth']], function () {
    Route::get('/applications', 'Applications@index');
    Route::post('/applications', 'Applications@create');
    
    Route::get('/applications/install-stats', 'Applications@getInstallStats');
    
    Route::delete('/applications/{id}', 'Applications@delete');
    Route::get('/applications/{id}', 'Applications@get');
    Route::post('/applications/{id}', 'Applications@create');
    Route::put('/applications/{id}', 'Applications@create');
    
    Route::get('/app-store', 'AppStore@index');
    Route::get('/app-store/{id}', 'AppStore@get');
    Route::post('/app-store/{id}', 'AppStore@install');
    Route::delete('/app-store/{id}', 'AppStore@uninstallApplicationViaAppId');
    
    Route::delete('/app-store/installs/{id}', 'AppStore@uninstallApplication');
    Route::put('/app-store/installs/{id}', 'AppStore@updateInstallation');
    
});

/**
 * Routes in the Crm namespace
 */
Route::group(['namespace' => 'Crm', 'middleware' => ['auth']], function () {
    /**
     * Contact Field Endpoints
     */
    Route::get('/contact-fields', 'Customer\ContactFields@index');
    Route::post('/contact-fields', 'Customer\ContactFields@create');
    Route::delete('/contact-fields/{id}', 'Customer\ContactField@delete');
    Route::get('/contact-fields/{id}', 'Customer\ContactField@index');
    Route::put('/contact-fields/{id}', 'Customer\ContactField@update');

    /**
     * Customer Endpoints
     */
    Route::get('/customers', 'Customer\Customers@index');
    Route::post('/customers', 'Customer\Customers@create');
    Route::delete('/customers/{id}', 'Customer\Customer@delete');
    Route::get('/customers/{id}', 'Customer\Customer@index');
    Route::put('/customers/{id}', 'Customer\Customer@update');
    Route::delete('/customers/{id}/contacts', 'Customer\CustomerContacts@delete');
    Route::get('/customers/{id}/contacts', 'Customer\CustomerContacts@index');
    Route::post('/customers/{id}/contacts', 'Customer\CustomerContacts@sync');
    Route::delete('/customers/{id}/notes', 'Customer\Notes@delete');
    Route::get('/customers/{id}/notes', 'Customer\Notes@index');
    Route::post('/customers/{id}/notes', 'Customer\Notes@create');
    
    Route::get('/customers/{id}/deals', 'Customer\Customer@listDeals');
    Route::post('/customers/{id}/deals', 'Customer\Customer@createDeal');
    
    /**
     * Deals Endpoints
     */
    Route::get('/deals', 'Deals\Deals@index');
    Route::post('/deals', 'Deals\Deals@create');
    Route::delete('/deals/{id}', 'Deals\Deals@delete');
    Route::get('/deals/{id}', 'Deals\Deals@get');
    Route::post('/deals/{id}', 'Deals\Deals@update');
    
    Route::post('/deals/{id}/stages', 'Deals\Stages@create');
    Route::delete('/deals/{id}/stages', 'Deals\Stages@delete');
    Route::put('/deals/{id}/stages', 'Deals\Stages@update');

    /**
     * Groups
     */
    Route::get('/groups', 'Groups\Groups@index');
    Route::post('/groups', 'Groups\Groups@create');
    Route::delete('/groups/{id}', 'Groups\Group@delete');
    Route::get('/groups/{id}', 'Groups\Group@index');
    Route::put('/groups/{id}', 'Groups\Group@update');
    Route::delete('/groups/{id}/customers', 'Groups\Group@removeCustomers');
    Route::get('/groups/{id}/customers', 'Groups\Group@customers');
    Route::post('/groups/{id}/customers', 'Groups\Group@addCustomers');
});

Route::group(['namespace' => 'Directory', 'prefix' => 'directory', 'middleware' => ['auth']], function () {
    Route::get('/', 'Profile@search');
    
    Route::get('/categories', 'Categories@search');
    Route::post('/categories', 'Categories@add');
    Route::put('/categories/{id}', 'Categories@update');
    
    Route::post('/credentials', 'Credentials@add');
    Route::delete('/credentials/{id}', 'Credentials@delete');
    Route::put('/credentials/{id}', 'Credentials@update');
    
    Route::post('/experiences', 'Experiences@add');
    Route::delete('/experiences/{id}', 'Experiences@delete');
    Route::put('/experiences/{id}', 'Experiences@update');
    
    Route::get('/services', 'Services@search');
    Route::post('/services', 'Services@add');
    
    Route::get('/services/list-requests', 'ServiceRequests@listRequests');
    
    Route::get('/services/{id}/requests', 'ServiceRequests@get');
    Route::post('/services/{id}/requests', 'ServiceRequests@create');
    Route::delete('/services/{id}', 'Services@delete');
    Route::get('/services/{id}', 'Services@single');
    Route::put('/services/{id}', 'Services@update');
    
    Route::get('/service-requests', 'ServiceRequests@search');
    Route::delete('/service-requests/{id}', 'ServiceRequests@delete');
    Route::get('/service-requests/{id}', 'ServiceRequests@single');
    Route::put('/service-requests/{id}', 'ServiceRequests@update');
    
    Route::delete('/social-connections', 'Profile@deleteSocialConnections');
    Route::post('/social-connections', 'Profile@addSocialConnections');
    
    Route::get('/{id}', 'Profile@profile');
});

Route::get('/domains/resolver', 'ECommerce\Domains\Domains@resolver');
# to prevent overlapping

Route::group(['namespace' => 'ECommerce'], function () {
    
    Route::get('/domains/hosting-capacity', 'Domains\Domains@hostingCapacity');
    
    Route::group(['middleware' => ['auth']], function () {
        Route::get('/adverts', 'Adverts@index');
        Route::post('/adverts', 'Adverts@create');
        Route::get('/adverts/{id}', 'Adverts@single');
        Route::delete('/adverts/{id}', 'Adverts@delete');
        Route::put('/adverts/{id}', 'Adverts@update');
        Route::post('/adverts/{id}', 'Adverts@update');
        
        Route::get('/blog/posts', 'Blog\Posts@index');
        Route::post('/blog/posts', 'Blog\Posts@create');
        Route::delete('/blog/posts/{id}', 'Blog\Posts@delete');
        Route::get('/blog/posts/{id}', 'Blog\Posts@single');
        Route::post('/blog/posts/{id}', 'Blog\Posts@update');
        Route::put('/blog/posts/{id}', 'Blog\Posts@update');
        
        Route::get('/blog/categories', 'Blog\Categories@index');
        Route::post('/blog/categories', 'Blog\Categories@create');
        Route::delete('/blog/categories/{id}', 'Blog\Categories@delete');
        Route::get('/blog/categories/{id}', 'Blog\Categories@single');
        Route::put('/blog/categories/{id}', 'Blog\Categories@update');
    
        Route::get('/blog/media', 'Blog\Media@index');
        Route::post('/blog/media', 'Blog\Media@create');
        Route::delete('/blog/media/{id}', 'Blog\Media@delete');
        Route::get('/blog/media/{id}', 'Blog\Media@single');
        
        
        Route::get('/domains', 'Domains\Domains@index');
        Route::post('/domains', 'Domains\Domains@create');
    
        Route::get('/domains/issuances', 'Domains\DomainIssuances@index');
        Route::post('/domains/issuances', 'Domains\DomainIssuances@create');
        Route::get('/domains/issuances/availability', 'Domains\DomainIssuances@checkAvailability');
        Route::delete('/domains/issuances/{id}', 'Domains\DomainIssuances@delete');
        Route::post('/domains/issuances/{id}', 'Domains\DomainIssuances@single');

        Route::get('/domains/{id}', 'Domains\Domains@single');
        Route::delete('/domains/{id}', 'Domains\Domains@delete');
        Route::put('/domains/{id}', 'Domains\Domains@update');
    });
});

Route::group(['namespace' => 'Finance', 'middleware' => ['auth'], 'prefix' => 'finance'], function () {
    Route::post('/install', 'Setup@install');
    
    Route::get('/accounts', 'Accounts@search');
    Route::post('/accounts', 'Accounts@create');
    
    Route::get('/accounts/{id}/entries', 'Entries@search');
    Route::delete('/accounts/{id}', 'Accounts@delete');
    Route::get('/accounts/{id}', 'Accounts@single');
    Route::put('/accounts/{id}', 'Accounts@update');
    
    Route::get('/entries', 'Entries@search');
    Route::post('/entries', 'Entries@create');
    Route::post('/entries/bulk', 'Entries@createBulk');
    Route::delete('/entries/{id}', 'Entries@delete');
    Route::get('/entries/{id}', 'Entries@single');
    Route::put('/entries/{id}', 'Entries@update');

    Route::group(['namespace'=>'Tax','prefix'=> 'tax'],function (){
        Route::get('/authority','TaxAuthority@search');
        Route::post('/authority','TaxAuthority@create' );
        Route::get('/authority/{id}','TaxAuthority@single' );
        Route::put('/authority/{id}','TaxAuthority@update');
        Route::delete('/authority/{id}','TaxAuthority@delete');



        Route::get('/element','TaxElement@search');
        Route::post('/element','TaxElement@create');
        Route::get('/element/{id}','TaxElement@single' );
        Route::put('/element/{id}','TaxElement@update');
        Route::delete('/element/{id}','TaxElement@delete');

        Route::get('/run','TaxRun@search');
        Route::post('/run','TaxRun@create');
        Route::get('/run/{id}','TaxRun@single' );
        Route::put('/run/{id}','TaxRun@update');
        Route::get('/run/{id}/processed-authorities','TaxRun@getRunAuthorities' );

//        Route::get('/run-job','TaxRun@runJob');
//        Route::put('/run/start/{id}','TaxRun@start');
//        Route::delete('/run/{id}','TaxRun@delete');


    });
    
    Route::get('/reports/configure', 'Reports@configuredReports');
    Route::post('/reports/configure', 'Reports@configure');
    Route::get('/reports/configure/{id}', 'Reports@reportConfiguration');
    Route::put('/reports/configure/{id}', 'Reports@updateReportConfiguration');
    
    Route::post('/reports/creator', 'ReportCreator@create');
    Route::post('/reports/creatorpdf', 'ReportCreator@createpdf');
    Route::post('/reports/balance_sheet', 'ReportCreator@balance_sheet');
    Route::post('/reports/income_statement', 'ReportCreator@income_statement');

    Route::get('/transtrak/mail','Transtrak@index');
    Route::post('/transtrak/setup','Transtrak@create');

    //unverified transactions routes
	Route::get('/transactions','Transactions@search');
	Route::post('/transactions/verify','Transactions@verify');

    Route::post('/transactions/generate-report','Transactions@generateReport');
});

Route::group(['namespace' => 'Finance', 'prefix' => 'finance'], function () {
    Route::get('/reports/creator', 'ReportCreator@create');
    Route::get('/reports/creatorpdf', 'ReportCreator@createpdf');
});

/**
 * Routes in the Invoicing namespace
 */
Route::group(['namespace' => 'Invoicing', 'middleware' => ['auth']], function () {
    Route::get('/product-categories', 'Product\ProductCategories@index');
    Route::post('/product-categories', 'Product\ProductCategories@create');
    Route::delete('/product-categories/{id}', 'Product\ProductCategories@delete');
    Route::get('/product-categories/{id}', 'Product\ProductCategories@single');
    Route::put('/product-categories/{id}', 'Product\ProductCategories@update');
    
    /**
     * Product Endpoints
     */
    Route::get('/products', 'Product\Products@index');
    Route::post('/products', 'Product\Products@create');
    Route::delete('/products/{id}', 'Product\Product@delete');
    Route::get('/products/{id}', 'Product\Product@index');
    Route::post('/products/{id}', 'Product\Product@update');
    
    Route::delete('/products/{id}/categories', 'Product\Product@removeCategory');
    Route::post('/products/{id}/categories', 'Product\Product@addCategory');
    Route::put('/products/{id}/categories', 'Product\Product@syncCategories');

    Route::delete('/products/{id}/images', 'Product\ProductImages@delete');
    Route::get('/products/{id}/images', 'Product\ProductImages@index');
    Route::post('/products/{id}/images', 'Product\ProductImages@create');

    Route::delete('/products/{id}/prices', 'Product\ProductPrices@delete');
    Route::get('/products/{id}/prices', 'Product\ProductPrices@index');
    Route::post('/products/{id}/prices', 'Product\ProductPrices@create');
    Route::put('/products/{id}/prices', 'Product\ProductPrices@update');

    Route::get('/products/{id}/stocks', 'Product\Product@stocks');
    Route::delete('/products/{id}/stocks', 'Product\Product@deleteStocks');
    Route::post('/products/{id}/stocks', 'Product\Product@stockQuantity');

    Route::post('/product/scan/', 'Product\Product@scanProductWithBarcode');

    /**
     * Order Endpoints
     */
    Route::get('/orders', 'Orders\Orders@index');
    Route::post('/orders', 'Orders\Orders@create');
    Route::delete('/orders/{id}', 'Orders\Order@delete');
    Route::get('/orders/{id}', 'Orders\Order@index');
    Route::put('/orders/{id}', 'Orders\Order@update');
    Route::delete('/orders/{id}/customers', 'Orders\OrderCustomers@delete');
    Route::get('/orders/{id}/customers', 'Orders\OrderCustomers@index');
    Route::put('/orders/{id}/customers', 'Orders\OrderCustomers@update');
    Route::get('/orders/{id}/reminders', 'Orders\Order@reminders');
    Route::post('/orders/invoices/{id}', 'Orders\Order@invoicing');
});

Route::group(['namespace' => 'Invoicing'], function () {
    Route::get('/orders/{id}/pay', 'Orders\Order@pay');
    Route::get('/orders/{id}/verify-payment', 'Orders\Order@verifyPayment');
    Route::post('/orders/{id}/verify-payment', 'Orders\Order@verifyPayment');
});

Route::group(['namespace'=> 'Payroll','middleware'=> ['auth'], 'prefix'=> 'payroll'], function() {
    Route::post('/authority','Authority@create');
    Route::get('/authority','Authority@search');
    Route::put('/authority/{id}','Authority@update');
    Route::get('/authority/{id}','Authority@single');
    Route::delete('/authority/{id}','Authority@delete');

    Route::get('/allowance','Allowance@search');
    Route::get('/allowance/{id}','Allowance@single');
    Route::post('/allowance','Allowance@create');
    Route::put('/allowance/{id}','Allowance@update');
    Route::delete('/allowance/{id}','Allowance@delete');

    Route::post('/paygroup','Paygroup@create');
    Route::get('/paygroup','Paygroup@search');
    Route::get('/paygroup/{id}','Paygroup@single');
    Route::put('/paygroup/{id}', 'Paygroup@update');
    Route::delete('/paygroup/{id}', 'Paygroup@delete');


    Route::get('/paygroup/employees/{id}','Paygroup@employees');
    Route::post('/paygroup/employees/{id}','Paygroup@addEmployee');
    Route::delete('/paygroup/employees/{id}', 'Paygroup@removeEmployees');


    Route::post('/paygroup/allowances/{id}','Paygroup@addAllowance');
    Route::get('/paygroup/allowances/{id}','Paygroup@allowances');
    Route::delete('/paygroup/allowances/{id}', 'Paygroup@removeAllowances');

    Route::post('/transaction','Transactions@create');
    Route::get('/transaction','Transactions@search');
    Route::put('/transaction/{id}','Transactions@update');
    Route::get('/transaction/{id}','Transactions@single');
    Route::delete('/transaction/{id}','Transactions@delete');

    Route::get('/run','Run@search');
    Route::post('/run','Run@create');
    Route::get('/run/{id}','Run@single');
    Route::put('/run/{id}','Run@update');
    Route::delete('/run/{id}','Run@delete');
    Route::get('/run/{id}/processed-employees','Run@getRunEmployeeInvoices' );
    Route::get('/run/{id}/processed-authorities','Run@getRunAuthorities' );

//    Route::get('/run/start/{id}','Run@handle');

//    Route::


});

Route::group(['namespace' => 'Blog', 'prefix' => 'blog'], function () {
    Route::get('/{id}/categories', 'Posts@categories');
    Route::get('/{id}/category', 'Posts@category');
    Route::get('/{id}/posts', 'Posts@post');
    Route::get('/{id}', 'Posts@search');
});

Route::group(['namespace' => 'Store', 'prefix' => 'store'], function () {
    Route::get('/{id}/categories', 'Products@categories');
    Route::get('/{id}/category', 'Products@category');
    Route::post('/{id}/customers', 'Customers@fetchOrCreate');
    Route::get('/{id}/product', 'Products@product');
    Route::post('/{id}/checkout', 'Orders@checkout');
    Route::get('/{id}', 'Products@search');
});

/**
 * Routes in the Integrations namespace
 */
Route::group(['namespace' => 'Integrations', 'middleware' => ['auth']], function () {
    /**
     * Integration Endpoints
     */
    Route::get('/integrations', 'Integrations@index');
    Route::post('/integrations', 'Integrations@create');
    Route::delete('/integrations/{id}', 'Integrations@delete');
    Route::get('/integrations/{id}', 'Integrations@view');
    Route::put('/integrations/{id}', 'Integrations@update');
});


Route::group(['prefix' => 'partners', 'middleware' => ['auth', 'permission:'.PermissionName::MANAGE_MEMBERS]], function () {
    Route::get('/companies', 'Partners@searchCompanies');
    Route::delete('/companies/{id}', 'Partners@deleteCompany');
    Route::get('/invites', 'Partners@sentInvites');
    Route::post('/invites', 'Partners@invite');
    Route::delete('/invites/{id}', 'Partners@deleteInvite');
    Route::get('/users', 'Partners@searchUsers');
    Route::delete('/users/{id}', 'Partners@deleteUser');
    
    Route::post('/{id}', 'Partners@update');
    Route::put('/{id}', 'Partners@update');
});

Route::get('/invites/{id}', 'Invites@get');
Route::post('/invites/{id}', 'Invites@respond');

Route::get('/invoices/{id}', 'Invoicing\Invoice@index');

Route::group(['middleware' => ['auth']], function () { //added nov 7 6:47pm
    Route::get('/partners', 'Partners@index');
    Route::get('/partners/{id}', 'Partners@single');
    Route::get('/plans', 'Plans@index');
    Route::get('/plans/{id}', 'Plans@view');
});


Route::group(['namespace'=> 'Approval','middleware'=> ['auth'], 'prefix'=> 'approvals'], function() {
    Route::get('/approval', 'Approval@index');
    Route::post('/approval', 'Approval@create');
    Route::get('/approval/{id}', 'Approval@single');
    Route::put('/approval/{id}', 'Approval@update');
    Route::delete('/approval/{id}', 'Approval@delete');

    Route::post('/authorizers','ApprovalAuthorizer@create');
    Route::post('/authorize','ApprovalAuthorizer@authorizeRequest');
    Route::delete('/authorizers','ApprovalAuthorizer@delete');
//
    Route::get('/requests','ApprovalRequests@index');
    Route::get('/requests/authorizer','ApprovalRequests@authorizersIndex');
    Route::get('/requests/{id}','ApprovalRequests@single');
    Route::post('/requests','ApprovalRequests@action');


    //wildcard Cron Test Route

    Route::get('/cron','ApprovalRequests@handle');

});

Route::group(['namespace'=> 'Leave','middleware'=> ['auth'], 'prefix'=> 'leave'], function() {
    Route::get('/types', 'LeaveTypes@index');
    Route::post('/types', 'LeaveTypes@create');
    Route::get('/types/{id}', 'LeaveTypes@single');
    Route::put('/types/{id}', 'LeaveTypes@update');
    Route::delete('/types/{id}', 'LeaveTypes@delete');

    Route::get('/groups', 'LeaveGroups@index');
    Route::post('/groups', 'LeaveGroups@create');
    Route::get('/groups/{id}', 'LeaveGroups@single');
    Route::put('/groups/{id}', 'LeaveGroups@update');
    Route::delete('/groups/{id}', 'LeaveGroups@delete');

    Route::get('/requests','LeaveRequests@index');
    Route::get('/requests/types/{id}','LeaveRequests@getEmployeeLeaveTypes');
    Route::post('/requests','LeaveRequests@create');
    Route::get('/requests/{id}','LeaveRequests@single');
    Route::put('/requests/{id}','LeaveRequests@update');
    Route::delete('/requests/{id}','LeaveRequests@delete');

});

Route::group(['namespace'=>'Transtrak','prefix'=> 'transtrak'],function (){
    Route::post('incoming','Incoming@logTranstrak');
    Route::get('single','Incoming@process_incoming_email');
});
/**
 * Test Endpoints
 */
Route::get('test-notification/{id}', function ($id) {
        $invite = App\Models\Invite::where('uuid', $id)->first();
        # get the invite record
        if (empty($invite)) {
            throw new RecordNotFoundException('Could not find the specified invite.');
        }
        //return $invite;

        return (new App\Notifications\InviteNotification($invite))->toMail($invite->email);
});

Route::get('php-info', function () {
        return phpinfo();
});
