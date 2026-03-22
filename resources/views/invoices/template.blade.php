<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 13px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .page {
            padding: 40px;
        }
        .header {
            width: 100%;
            margin-bottom: 30px;
        }
        .header-logo {
            display: inline-block;
            vertical-align: top;
            width: 30%;
        }
        .header-logo img {
            max-width: 120px;
            max-height: 60px;
        }
        .header-company {
            display: inline-block;
            vertical-align: top;
            width: 68%;
            text-align: right;
        }
        .header-company h2 {
            margin: 0 0 4px 0;
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header-company p {
            margin: 0;
            color: #555;
            font-size: 12px;
        }
        .divider {
            border: none;
            border-top: 2px solid #1a1a1a;
            margin: 20px 0;
        }
        .meta-block {
            margin-bottom: 30px;
        }
        .meta-block h1 {
            font-size: 28px;
            letter-spacing: 3px;
            margin: 0 0 12px 0;
            text-transform: uppercase;
        }
        .meta-row {
            font-size: 12px;
            color: #444;
            margin-bottom: 4px;
        }
        .meta-row span {
            font-weight: bold;
            color: #1a1a1a;
        }
        .billing {
            margin-top: 20px;
        }
        .billing h4 {
            margin: 0 0 6px 0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
        }
        .billing p {
            margin: 2px 0;
            font-size: 13px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        thead tr {
            background-color: #1a1a1a;
            color: #fff;
        }
        thead th {
            padding: 10px 12px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        thead th.right {
            text-align: right;
        }
        tbody tr:nth-child(even) {
            background-color: #f7f7f7;
        }
        tbody td {
            padding: 9px 12px;
            font-size: 13px;
            border-bottom: 1px solid #e5e5e5;
        }
        tbody td.right {
            text-align: right;
        }
        .total-row td {
            padding: 12px;
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #1a1a1a;
            border-bottom: none;
        }
        .footer {
            margin-top: 50px;
            padding-top: 16px;
            border-top: 1px solid #ccc;
            font-size: 11px;
            color: #777;
        }
        .footer h5 {
            margin: 0 0 6px 0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
        }
    </style>
</head>
<body>
<div class="page">

    {{-- Header: logo (conditional) + company details --}}
    <div class="header">
        <div class="header-logo">
            @if(file_exists(public_path('images/logo.png')))
                <img src="{{ public_path('images/logo.png') }}" alt="Logo">
            @endif
        </div>
        <div class="header-company">
            <h2>Acme Corp</h2>
            <p>123 Business Road</p>
            <p>London, UK, EC1A 1BB</p>
            <p>billing@acmecorp.example</p>
        </div>
    </div>

    <hr class="divider">

    {{-- Invoice meta + billing address --}}
    <div class="meta-block">
        <h1>Invoice</h1>
        <div class="meta-row">Invoice #: <span>{{ $invoiceNumber }}</span></div>
        <div class="meta-row">Date: <span>{{ $date }}</span></div>

        <div class="billing">
            <h4>Billed to</h4>
            <p>{{ $user->title->value }} {{ $user->first_name }} {{ $user->last_name }}</p>
            <p>{{ $user->address }}</p>
            <p>{{ $user->phone_number }}</p>
        </div>
    </div>

    <hr class="divider">

    {{-- Line items table --}}
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineItems as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="right">{{ $item['quantity'] }}</td>
                    <td class="right">&pound;{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="right">&pound;{{ number_format($item['line_total'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3" class="right">Grand Total</td>
                <td class="right">&pound;{{ number_format($grandTotal, 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- T&C footer --}}
    <div class="footer">
        <h5>Terms &amp; Conditions</h5>
        <p>
            Payment is due within 30 days of the invoice date. All prices are inclusive of
            applicable taxes. Late payments may be subject to a charge of 2% per month.
            Please reference the invoice number on all payments. For queries contact
            billing@acmecorp.example.
        </p>
    </div>

</div>
</body>
</html>
