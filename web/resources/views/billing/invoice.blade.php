@extends('billing.layout')
@section('title', $invoice->number ?? 'Facture brouillon')
@section('content')
<h1>{{ $invoice->number ?? 'Brouillon #'.$invoice->id }}</h1>
@if($invoice->status === 'draft')<p class="banner">BROUILLON — NON ÉMIS</p>@endif
@if($invoice->status === 'cancelled')<p class="banner">FACTURE ANNULÉE</p>@endif
<p>Patient : {{ $invoice->patient_name }} · MC-{{ $invoice->patient_id }}</p>
<p>Statut : {{ \App\Models\Invoice::STATUSES[$invoice->status] }} · Devise : {{ $invoice->currency }}</p>
@if($invoice->issued_at)<p>Émission : {{ $invoice->issued_at->setTimezone(config('clinic.timezone'))->format('d/m/Y H:i') }} · {{ config('clinic.timezone') }}</p>@endif
<table><thead><tr><th>Acte</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr></thead><tbody>@foreach($invoice->lines as $line)<tr><td>{{ $line->code }} · {{ $line->label }}</td><td>{{ $line->quantity }}</td><td>{{ \App\Support\Money::format($line->unit_price_minor, $invoice->currency) }}</td><td>{{ \App\Support\Money::format($line->total_minor, $invoice->currency) }}</td></tr>@endforeach</tbody></table>
<div class="totals"><p>Total : <strong>{{ \App\Support\Money::format($invoice->total_minor, $invoice->currency) }}</strong><br>Payé : {{ \App\Support\Money::format($invoice->paid_minor, $invoice->currency) }}<br>Solde : {{ $invoice->status === 'cancelled' ? 'Annulé' : \App\Support\Money::format($invoice->total_minor - $invoice->paid_minor, $invoice->currency) }}</p></div>
@if($invoice->payments->count())<h2>Historique des paiements</h2><table><thead><tr><th>Reçu</th><th>Montant</th><th>État</th></tr></thead><tbody>@foreach($invoice->payments as $payment)<tr><td>{{ $payment->receipt_number }}</td><td>{{ \App\Support\Money::format($payment->amount_minor, $invoice->currency) }}</td><td>{{ $payment->voided_at ? 'Écriture annulée' : 'Enregistré' }}</td></tr>@endforeach</tbody></table>@endif
@endsection
