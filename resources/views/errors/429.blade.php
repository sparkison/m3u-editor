@extends('errors::minimal')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message')
    {{ $exception->getMessage() ?: __('Too Many Requests. Please try again later.') }}
@endsection

@section('sub_message')
    You have made too many requests or the system is currently at its maximum capacity for this resource.
    Please wait a short while before trying again. If the problem persists, please contact support.
@endsection
