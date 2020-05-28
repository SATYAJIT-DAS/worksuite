@extends('layouts.sass-app')
@section('content')
    <section class="pricing-section bg-white sp-100-40">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p>{!! $slugData->description !!}</p>
                </div>
            </div>
        </div>
    </section>

@endsection
@push('footer-script')

@endpush
