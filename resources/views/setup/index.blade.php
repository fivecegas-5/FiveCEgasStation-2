@extends('layouts.app')

@section('title', 'Station setup')
@section('section', 'Station setup')
@section('react-page', 'setup')
@section('react-data', json_encode($setupData, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG))
