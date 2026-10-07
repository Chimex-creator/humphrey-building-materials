@extends('layouts.app')

@section('title', 'Session expired — ' . config('app.name'))

@section('content')
    @include('errors._frame', [
        'code' => 419,
        'heading' => 'Your session expired',
        'message' => 'For your security the page expired before it was submitted. Please go back, refresh the page and try again.',
    ])
@endsection
