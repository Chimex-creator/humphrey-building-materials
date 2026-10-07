@extends('layouts.app')

@section('title', 'Something went wrong — ' . config('app.name'))

@section('content')
    @include('errors._frame', [
        'code' => 500,
        'heading' => 'Something went wrong',
        'message' => 'An unexpected error occurred while processing your request. Please go back and try again, or return to the home page.',
    ])
@endsection
