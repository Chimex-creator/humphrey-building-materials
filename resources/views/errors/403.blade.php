@extends('layouts.app')

@section('title', 'Access denied — ' . config('app.name'))

@section('content')
    @include('errors._frame', [
        'code' => 403,
        'heading' => 'You do not have access to this page',
        'message' => 'Your account does not have permission to view this area. If you think this is a mistake, contact the administrator.',
    ])
@endsection
