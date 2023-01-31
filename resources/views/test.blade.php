<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
{{--    <title>{{ $pageTitle or 'Payment Confirmation' }}</title>--}}
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700" rel="stylesheet">
    <link rel="stylesheet" href="{{ web_url('css/pay-form-style.css') }}">
    <meta name="robots" content="noindex,follow" />
    <style type="text/css">
        .success-box {
            border:  1px solid #4caf50;
            background: #e8f5e9;
        }
        .error-box {
            border:  1px solid #f44336;
            background: #ffebee;
        }
    </style>
</head>
<body>
<div class="checkout-panel" style="height: auto;">
    <div class="panel-body">
{{--        <div><center><img height="50" src="{{ $company_logo }}"/></center></div>--}}
{{--        <h2><center>{{ $company_name }} Store</center></h2>--}}
        <div><br/></div>
        <h3 class="title">Verification Successful</h3>
        <div class="progress-bar">
            <div class="step active"></div>
            <div class="step active"></div>
            <div class="step active"></div>
            <div class="step"></div>
        </div>

        <div class="payment-method">
            <label for="card" class="method card info-box" style="width: 100%;">
                <p><a href="{{ env('APP_URL').'/dashboard' }}">Back to Store</a></p>
            </label>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
{{--<script src="{{ web_url('js/payment-form.js') }}"></script>--}}
</body>
</html>

