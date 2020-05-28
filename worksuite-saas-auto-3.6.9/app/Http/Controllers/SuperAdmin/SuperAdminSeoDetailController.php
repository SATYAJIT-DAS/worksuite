<?php

namespace App\Http\Controllers\SuperAdmin;


use App\Helper\Reply;
use App\SeoDetail;
use Illuminate\Http\Request;

class SuperAdminSeoDetailController extends SuperAdminBaseController
{
    public function __construct() {
        parent::__construct();
        $this->pageTitle = 'Seo Details';
        $this->pageIcon = 'icon-settings';
    }

    public function index(){
        $this->seoDetails = SeoDetail::all();
        return view('super-admin.front-seo-detail.index', $this->data);
    }

    public function edit($id){
        $this->seoDetail = SeoDetail::findOrFail($id);
        return view('super-admin.front-seo-detail.seo-detail', $this->data);
    }

    public function update(Request $request, $id)
    {
        $seoDetail = SeoDetail::findOrFail($id);
        $seoDetail->update($request->all());

        return Reply::redirect(route('super-admin.seo-detail.index'));
    }

}
