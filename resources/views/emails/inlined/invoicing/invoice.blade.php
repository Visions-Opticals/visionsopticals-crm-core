<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>

    <style>
        .invoice-box {
            max-width: 800px;
            margin: auto;
            padding: 30px;
            border: 1px solid #eee;
            box-shadow: 0 0 10px rgba(0, 0, 0, .15);
            font-size: 16px;
            line-height: 24px;
            font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
            color: #555;
        }

        .invoice-box table {
            width: 100%;
            line-height: inherit;
            text-align: left;
        }

        .invoice-box table td {
            padding: 5px;
            vertical-align: top;
        }

        .invoice-box table tr td:nth-child(2) {
            text-align: right;
        }

        .invoice-box table tr.top table td {
            padding-bottom: 20px;
        }

        .invoice-box table tr.top table td.title {
            font-size: 45px;
            line-height: 45px;
            color: #333;
        }

        .invoice-box table tr.information table td {
            padding-bottom: 40px;
        }

        .invoice-box table tr.heading td {
            background: #eee;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
        }

        .invoice-box table tr.details td {
            padding-bottom: 20px;
        }

        .invoice-box table tr.item td{
            border-bottom: 1px solid #eee;
        }

        .invoice-box table tr.item.last td {
            border-bottom: none;
        }

        .invoice-box table tr.total td:nth-child(2) {
            border-top: 2px solid #eee;
            font-weight: bold;
        }

        .invoice-box table tr.footer {
            font-size: 13px !important;
        }

        @media only screen and (max-width: 600px) {
            .invoice-box table tr.top table td {
                width: 100%;
                display: block;
                text-align: center;
            }

            .invoice-box table tr.information table td {
                width: 100%;
                display: block;
                text-align: center;
            }
        }

        /** RTL **/
        .rtl {
            direction: rtl;
            font-family: Tahoma, 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
        }

        .rtl table {
            text-align: right;
        }

        .rtl table tr td:nth-child(2) {
            text-align: left;
        }
    </style>
</head>

<body>
<div class="invoice-box" style="max-width: 800px;margin: auto;padding: 30px;border: 1px solid #eee;box-shadow: 0 0 10px rgba(0, 0, 0, .15);font-size: 16px;line-height: 24px;font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;color: #555;">
    <table cellpadding="0" cellspacing="0" style="width: 100%;line-height: inherit;text-align: left;">
        <tr class="top">
            <td colspan="3" style="padding: 5px;vertical-align: top;">
                <table style="width: 100%;line-height: inherit;text-align: left;">
                    <tr>
                        <td class="title" style="padding: 5px;vertical-align: top;padding-bottom: 20px;font-size: 45px;line-height: 45px;color: #333;">
                            <img src="{{ $headerLogo }}" style="width:100%; max-width:300px;">
                        </td>
                        <td style="padding: 5px;vertical-align: top;text-align: right;padding-bottom: 20px;">
                            {{ $order->is_quote ? 'Quote' : 'Invoice' }} #: @if ($recipient->pivot instanceof \App\Models\CustomerOrder){{ $recipient->pivot->invoice_number }} @else {{ $order->invoice_number }}@endif<br>
                            Created: {{ $order->created_at->format('F d, Y') }}<br>
                            @if (!empty($order->due_at))
                                Due: {{ $order->due_at->format('F d, Y') }}
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr class="information">
            <td colspan="3" style="padding: 5px;vertical-align: top;">
                <table style="width: 100%;line-height: inherit;text-align: left;">
                    <tr>
                        <td style="padding: 5px;vertical-align: top; padding-bottom: 40px;">
                            <strong>{{ $company->name }}</strong><br>
                            @if (!empty($location))
                                {{ $location->address1 }},<br>
                                {!! !empty($location->address2) ? $location->address2 . ',<br>' : '' !!}
                                {{ !empty($location->city) ? $location->city . ', ' : '' }}{{ $location->state->name }}.
                            @endif
                        </td>
                        <td style="padding: 5px;vertical-align: top;text-align: right;padding-bottom: 40px;">
                            {{ $recipient->name }}<br>
                            {{ $recipient->email }}<br>
                            {{ $recipient->phone }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        @if (!empty($account))
            <tr class="details" style="padding-bottom: 10px;">
                <td  colspan="3" style="padding: 5px;vertical-align: top;border-bottom: 1px solid #ddd;font-weight: bold;">
                    <small style="text-transform: uppercase;">Payable To</small><br>
                    Bank: {{ strtoupper($account->json_data['bank_name']) }}<br>
                    Account No.: {{ $account->account_number }}<br>
                    Account Name: {{ $account->account_name }}<br>
                </td>
            </tr>
        @endif
        <tr class="heading">
            <td style="padding: 5px;vertical-align: top;background: #eee;border-bottom: 1px solid #ddd;font-weight: bold;">Title</td>
            <td colspan="2" style="padding: 5px;vertical-align: top;text-align: right;background: #eee;border-bottom: 1px solid #ddd;font-weight: bold;">{{ $order->title }}</td>
        </tr>
        <tr class="details">
            <td colspan="3" style="padding: 5px;vertical-align: top;padding-bottom: 20px;">{{ $order->description }}</td>
        </tr>
        <tr class="heading">
            <td style="padding: 5px;vertical-align: top;background: #eee;border-bottom: 1px solid #ddd;font-weight: bold;">Item</td>
            <td style="padding: 5px;vertical-align: top;text-align: right;background: #eee;border-bottom: 1px solid #ddd;font-weight: bold;">Quantity</td>
            <td style="padding: 5px;vertical-align: top;text-align: right;background: #eee;border-bottom: 1px solid #ddd;font-weight: bold;">Price</td>
        </tr>
        @if ($order->items->count() > 0)
            @foreach ($order->items as $item)
                <tr class="item">
                    <td style="padding: 5px;vertical-align: top;border-bottom: 1px solid #eee;">{{ $item->name }}</td>
                    <td style="padding: 5px;vertical-align: top;text-align: right;border-bottom: 1px solid #eee;">x{{ $item->pivot->quantity }}</td>
                    <td style="padding: 5px;vertical-align: top;text-align: right;border-bottom: 1px solid #eee;">{{ $order->currency }}{{ number_format($item->pivot->unit_price, 2) }}</td>
                </tr>
            @endforeach
        @else
            <tr class="item">
                <td style="padding: 5px;vertical-align: top;border-bottom: 1px solid #eee;">{{ $order->product_name }}</td>
                <td style="padding: 5px;vertical-align: top;text-align: right;border-bottom: 1px solid #eee;">x{{ $order->quantity }}</td>
                <td style="padding: 5px;vertical-align: top;text-align: right;border-bottom: 1px solid #eee;">{{ $order->currency }}{{ number_format($order->unit_price, 2) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td colspan="2" style="padding: 5px;vertical-align: top;"></td>
            <td style="padding: 5px;vertical-align: top;text-align: right;border-top: 2px solid #eee;font-weight: bold;">Total: {{ $order->currency }}{{ number_format($order->amount, 2) }}</td>
        </tr>
        <tr class="footer">
            <td colspan="3" style="padding: 5px;vertical-align: bottom;text-align: center;color: #333333;font-size: 13px;">
                &copy; {{ date('Y') }} {{ !empty($app['product_name']) ? $app['product_name'] : 'Dorcas' }}. All rights reserved.
            </td>
        </tr>
    </table>
</div>
</body>
</html>
