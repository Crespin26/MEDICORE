<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title') — {{ config('app.name') }}</title>
<style>body{font-family:Arial,sans-serif;color:#111;max-width:900px;margin:36px auto;padding:0 20px}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{padding:12px;border-bottom:1px solid #ddd;text-align:left}h1{margin-bottom:8px}.banner{padding:16px;border:2px solid #111;font-weight:bold}.totals{text-align:right;line-height:1.8}.toolbar{display:flex;gap:24px;align-items:center;margin-bottom:30px}button{padding:10px 20px;cursor:pointer}p{overflow-wrap:anywhere}.muted{color:#555}@media print{.toolbar{display:none}body{margin:0;max-width:none}tr{break-inside:avoid}}@page{margin:18mm}</style></head>
<body><div class="toolbar"><button onclick="window.print()">Imprimer / enregistrer en PDF</button><a href="{{ route('billing.index') }}">Retour à la facturation</a></div>@php
$issuer = isset($invoice) ? $invoice->issuer_details : ($payment->invoice->issuer_details ?? null);
$issuer ??= ['name' => config('clinic.name', config('app.name')), 'address' => config('clinic.address'), 'phone' => config('clinic.phone'), 'email' => config('clinic.email')];
@endphp
<p><strong>{{ $issuer['name'] }}</strong><br>{{ $issuer['address'] ?? '' }}<br>{{ $issuer['phone'] ?? '' }} {{ $issuer['email'] ?? '' }}</p>@yield('content')</body></html>
