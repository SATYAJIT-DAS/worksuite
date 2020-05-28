<?php

namespace App\Http\Requests\SuperAdmin\Companygroups;

use App\Http\Requests\SuperAdmin\SuperAdminBaseRequest;

class CompanyGroupRequest extends SuperAdminBaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            "title" => "required",
            "icon" => "required",
        ];
        return $rules;
    }
}
