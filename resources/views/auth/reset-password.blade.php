@extends('layouts.auth')

@section('title', 'Reset password')
@section('react-page', 'reset-password')
@section('reset-token', $token)
@section('auth-email', old('email', $email))
