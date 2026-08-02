@extends('errors.layout')

@section('code', '500')
@section('tone', 'destructive')
@section('icon', 'ki-shield-cross')
@section('heading', 'Something broke on our side')
@section('message', 'This is a fault in Kargah, not in what you did. The error has been logged. Try again in a moment.')

@section('extra')
    <a href="https://github.com/morpheusadam/kargah/issues/new" target="_blank" rel="noopener"
       class="text-sm text-primary hover:underline">Report this issue</a>
@endsection
