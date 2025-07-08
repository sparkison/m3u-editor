@extends('errors::minimal')

@section('title', __('Max Streams Reached'))
@section('code', '429')
@section('message')
    {{-- Prefer the specific message from the abort() call via the exception --}}
    {{ $exception->getMessage() ?: __('Maximum stream limits have been reached. Please try again later.') }}
@endsection

@section('sub_message')
    The system is currently at its maximum capacity for this channel or playlist.
    Please wait a short while before trying again. If the problem persists, please contact support.
@endsection
