<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Company;
use App\CompanyGroup;
use App\Http\Requests\SuperAdmin\Companygroups\StoreRequest;
use App\Package;
use App\Role;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;
use App\FaqCategory;
use App\Helper\Reply;
class SuperAdminCompanyGroupController extends SuperAdminBaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
     public function __construct()
     {
         parent::__construct();
         $this->pageTitle = 'Companie Group';
         $this->pageIcon = 'icon-layers';
         $this->colClass = '6';

     }
    public function index()
    {
      $this->totalCompanies = CompanyGroup::count();
      $this->categories = CompanyGroup::all();
        return view('super-admin.company-groups.index',$this->data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $this->Category = new CompanyGroup();
        return view('super-admin.company-groups.create',$this->data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreRequest $request)
    {
      DB::beginTransaction();

      $CompanyGroup = new CompanyGroup();
      $CompanyGroup->name = $request->name;
      $CompanyGroup->description = $request->description;
      $CompanyGroup->save();

      DB::commit();

      return Reply::redirect(route('super-admin.company-groups.index'), 'Company added successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
      CompanyGroup::destroy($id);

      return Reply::success('messages.deleteSuccess');
    }

    public function data()
    {
        $faqCategories = CompanyGroup::all();

        return Datatables::of($faqCategories)
            ->addColumn('name', function($row) {
                $string =  ' '.$row->name.' ';
                return $string;
            })
            ->addColumn('description', function($row){
                $action = ' '.$row->description.' ';
                return $action;

            })
            ->addColumn('action', function($row){
                $action = '';
                

                $action .= ' <a href="javascript:;" class="btn btn-danger btn-circle sa-params"
                  data-toggle="tooltip" data-user-id="'.$row->id.'" data-original-title="'.trans('app.delete').'"><i class="fa fa-times" aria-hidden="true"></i></a>';

                return $action;

            })

            ->rawColumns(['action', 'name','description'])
            ->make(true);
    }


}
