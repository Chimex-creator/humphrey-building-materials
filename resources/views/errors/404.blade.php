@extends('layouts.app')

@section('title', 'Page not found — ' . config('app.name'))

@section('content')
    @include('errors._frame', [
        'code' => 404,
        'heading' => 'Page not found',
        'message' => 'The page you are looking for does not exist, or it may have been moved. Try searching our products instead.',
    ])
@endsection
