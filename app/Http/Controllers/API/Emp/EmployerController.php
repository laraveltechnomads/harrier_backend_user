<?php

namespace App\Http\Controllers\API\Emp;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Employer;
use App\Models\Job;
use App\Models\JobCondidate;
use App\Models\User;
use App\Notifications\CVRequested;
use App\Notifications\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EmployerController extends Controller
{
    /** CV request post  */
    public function cvReqPost(Request $request)
    {   
        DB::beginTransaction();
        try {
            if(respValid($request)) { return respValid($request); }  /* response required validation */
            $request = decryptData($request['response']); /* Dectrypt  **/
           
            $data = Validator::make($request, [
                'c_uid' => 'required|exists:candidates,uuid',
                'job_id' => 'required|exists:jobs,id|exists:jobs,id',
            ],[
               'c_uid.required' => 'Candidate details required (c_uid).',
               'c_uid.exists' => 'Requested CV candidate not valid.', 
            ]);

            if ($data->fails()) {
                return sendError($data->errors()->first(), [], errorValid());
            }else{
                $request = (object) $request;
                $in = new JobCondidate();
                $in->job_id = $request->job_id;
                $in->c_uid = $request->c_uid;
                $in->is_cv = is_requested();
                $in->request_date = date('Y-m-d');
                $in->save();

                DB::commit();

                if($admin = adminTable())
                {
                    $cand = Candidate::where('uuid', $in->c_uid)->first();
                    $job = Job::find($in->job_id);
                    $emp = employer_uuid($job->emp_uid);
                    if($job && $emp && $cand)
                    {
                        $data = [
                            'type' => config('constants.notification_type.cv_req.key'),
                            'email' => $emp->email,
                            'message' => config('constants.notification_type.cv_req.message'). ' for Candidate Id #'. $cand->id
                        ];
                        $data = (object) $data;
                        $admin->notify(new CVRequested($data));
                    }
                }
                return sendDataHelper('CV request sent.', [], ok());
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            // throw $th;
            $bug = $th->getMessage();
            return sendErrorHelper('Error', $bug, error());
        }
    }

    
    /* Employer deatils update */
    public function admEmpployerProfileUpdate(Request $request)
    {
        return self::profileUpdate($request, roleAdmin());
    }

    /* Emp get a candidates list */
    public function empProfileUpdate(Request $request)
    {
        $emp = employer(auth()->user()->email);
        return self::profileUpdate($request, roleEmp());
    }

    /* Employer deatils update */
    public function profileUpdate($request, $role)
    {
        // $request->file('logo');
        try {
            $data = Validator::make($request->all(), [
                'logo' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:10048',
            ]);

            if ($data->fails()) {
                return sendError($data->errors()->first(), [], errorValid());
            }
            $logo = null;
            if($request->hasFile('logo')) {   $logo = $request->logo; }

            if(respValid($request)) { return respValid($request); }  /* response required validation */
            $request = decryptData($request['response']); /* Dectrypt  **/
            
            $data = Validator::make($request, [
                'uk_address' =>  'required',
                'hq_address' =>  'nullable',
                'billing_address' =>  'required',
                'contact_details' =>  'required',
                'url' =>  'required',
            ]);

            if ($data->fails()) {
                return sendError($data->errors()->first(), [], errorValid());
            }else{
                
                if($role == roleAdmin())
                {
                    $data = Validator::make($request, [
                        'email' =>  'required|exists:employers,email',
                    ]);
                    if ($data->fails()) {
                        return sendError($data->errors()->first(), [], errorValid());
                    }
                    $email = $request['email'];
                    $in = employer($email);
                    
                }else{
                    $email = auth()->user()->email;
                    $in = employer($email);
                    
                }
                $request = (object) $request;
                $actual_url = @$request->url;
                if(@$request->url)
                {
                    $actual_url = Str::replace(' ', '', $request->url);
                    $actual_url = actual_url($actual_url);
                    $actual_url = Str::of($actual_url)->rtrim('/');
                }
                $check_url['url'] = $actual_url;

                $data = Validator::make($check_url, [
                    'url' => 'required|unique:employers,url,'.$in->id,
                ]);
    
                if ($data->fails()) {
                    return sendError($data->errors()->first(), [], errorValid());
                }

                $in->uk_address = @$request->uk_address ?? null;
                $in->hq_address = @$request->hq_address ?? null;
                $in->billing_address = @$request->billing_address ?? null;
                $in->contact_details = @$request->contact_details ?? null;
                $in->url = $actual_url ?? null;
                $in->update(); 
                
                if ($logo)
                {   
                    if(!empty($in->logo)){
                        if (File::exists(logo_public_path().$in->logo))
                        {
                            unlink(logo_public_path().$in->logo);
                        }
                    }
                    $in->logo = uploadFile($logo, 'uploads/logo') ?? null;
                    $in->update();
                }
                
                $in = employer($email)->only('email', 'name', 'uk_address', 'hq_address', 'billing_address', 'contact_details', 'logo');
                $response = [
                    'details' => $in
                ];
                if ($response) {
                    return sendDataHelper('Details updated.', $response, ok());
                } else {
                    return sendError('Something went wrong', [], error());
                }
            }
        } catch (\Throwable $th) {
            // throw $th;
            $bug = $th->getMessage();
            return sendErrorHelper('Error', $bug, error());
        }
    }

    /*Change Password update */
    public function empChangePasswordUpdate(Request $request)
    {
        DB::beginTransaction();
        try {

            if(respValid($request)) { return respValid($request); }  /* response required validation */
            $request = decryptData($request['response']); /* Dectrypt  **/
            
            $data = Validator::make($request, [
                'password' =>  'required',
            ]);

            if ($data->fails()) {
                return sendError($data->errors()->first(), [], errorValid());
            }else{
                $request = (object) $request;
                if(canEMP())
                {   
                    $response = User::where('email', auth()->user()->email)->update(['password' => bcrypt($request->password)]);
                    DB::commit();
                    if ($response) {
                        return sendDataHelper('Details updated.', $response, ok());
                    } else {
                        return sendError('Something went wrong', [], error());
                    }
                }
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            $bug = $th->getMessage();
            return sendErrorHelper('Error', $bug, error());
        }
    }

}
