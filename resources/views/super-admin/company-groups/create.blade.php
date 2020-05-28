<link rel="stylesheet" href="{{ asset('plugins/bower_components/summernote/dist/summernote.css') }}">
<div class="modal-header">
    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
    <h4 class="modal-title">@if(isset($Category->id)) @lang('app.edit') @else @lang('app.addNew') @endif @lang('app.companyGroupCat')</h4>
</div>
<div class="modal-body">
    <div class="portlet-body">
        {!! Form::open(['id'=>'addEditcompanycategory','class'=>'ajax-form']) !!}
        @if(isset($Category->id)) <input type="hidden" name="_method" value="PUT"> @endif
        <input type="hidden" name="faq_category_id" value="{{ $Category->id }}">
        <div class="form-body">
            <div class="row">
                <div class="col-xs-12 ">
                    <div class="form-group">
                        <label>@lang('app.name')</label>
                        <input type="text" name="name" class="form-control" value="{{ $Category->name ?? '' }}">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xs-12 ">
                    <div class="form-group">
                        <label>@lang('app.description')</label>
                        <textarea name="description" class="form-control summernote">{{ $Category->description ?? '' }}</textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" id="save-faq-category" onclick="saveCategory({{ $Category->id }} {{ isset($Category->id) ? ','.$Category->id : '' }});return false;" class="btn btn-success"> <i class="fa fa-check"></i> @lang('app.save')</button>
            @if(isset($Category->id))
                <button type="button" onclick="deleteFAQ({{ $Category->id }}, {{  $Category->id }});return false;" class="btn btn-danger"> <i class="fa fa-trash"></i> @lang('app.delete')</button>
            @endif
        </div>
        {!! Form::close() !!}
    </div>
</div>
<script src="{{ asset('plugins/bower_components/summernote/dist/summernote.min.js') }}"></script>
<script>
    $('.summernote').summernote({
        height: 200,                 // set editor height
        minHeight: null,             // set minimum height of editor
        maxHeight: null,             // set maximum height of editor
        focus: false,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['fontname', ['fontname']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link',  'hr']],
            ['view', ['fullscreen', 'codeview']],
            ['help', ['help']]
        ]
    });
</script>
