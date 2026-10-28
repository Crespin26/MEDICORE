@extends('billing.layout')
@section('title', $payment->receipt_number)
@section('content')
<h1>Reçu {{ $payment->receipt_number }}</h1>
@if($payment->voided_at)<p class="banner">ÉCRITURE DE PAIEMENT ANNULÉE</p><p>Motif : {{ $payment->void_reason }}<br>Par {{ $payment->voider_name }}, le {{ $payment->voided_at->setTimezone(config('clinic.timezone'))->format('d/m/Y H:i') }}</p>@endif
<p>Facture : {{ $payment->invoice->number }}<br>Patient : {{ $payment->invoice->patient_name }} · MC-{{ $payment->invoice->patient_id }}</p>
<p>Date d’enregistrement : {{ $payment->paid_at->setTimezone(config('clinic.timezone'))->format('d/m/Y H:i') }} · {{ config('clinic.timezone') }}</p>
<p>Montant encaissé : <strong>{{ \App\Support\Money::format($payment->amount_minor, $payment->invoice->currency) }}</strong></p>
<p>Mode : {{ ['cash' => 'Espèces', 'transfer' => 'Virement', 'card' => 'Carte (déjà encaissée)'][$payment->method] }}<br>Référence : {{ $payment->reference ?? 'Non renseignée' }}<br>Enregistré par : {{ $payment->recorder_name }}</p>
<p class="muted">Solde juste après cet enregistrement : {{ \App\Support\Money::format($payment->balance_after_minor, $payment->invoice->currency) }}. Cette valeur historique n’intègre pas les opérations ultérieures.</p>
<p><a href="{{ route('billing.show', $payment->invoice_id) }}">Consulter le solde actuel de la facture</a></p>
@endsection
