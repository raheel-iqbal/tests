<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\FirePHPHandler;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{

    protected $model;
    protected $logger;

    /**
     * @param User $model
     */
    function __construct(User $model)
    {
        parent::__construct($model);
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function createOrUpdate($id = null, $request)
    {
        $role = $request['role'];
        $name = $request['name'];
        $username = $request['username'];
        $company_id = $request['company_id'] != '' ? $request['company_id'] : 0;
        $department_id = $request['department_id'] != '' ? $request['department_id'] : 0;
        $email = $request['email'];
        $dob_or_orgid = $request['dob_or_orgid'];
        $gender = $request['gender'];
        $phone = $request['phone'];
        $mobile = $request['mobile'];
        $password = $request['password'];
        $consumer_type = $request['consumer_type'];
        $post_code = $request['post_code'];
        $address = $request['address'];
        $address_2 = $request['address_2'];
        $city = $request['city'];
        $town = $request['town'];
        $country = $request['country'];
        $reference = (isset($request['reference']) && $request['reference'] == 'yes') ? '1' : '0';
        $additional_info = $request['additional_info'];
        $cost_place = isset($request['cost_place']) ? $request['cost_place'] : '';
        $fee = isset($request['fee']) ? $request['fee'] : '';
        $time_to_charge = isset($request['time_to_charge']) ? $request['time_to_charge'] : '';
        $time_to_pay = isset($request['time_to_pay']) ? $request['time_to_pay'] : '';
        $charge_ob = isset($request['charge_ob']) ? $request['charge_ob'] : '';
        $customer_id = isset($request['customer_id']) ? $request['customer_id'] : '';
        $charge_km = isset($request['charge_km']) ? $request['charge_km'] : '';
        $maximum_km = isset($request['maximum_km']) ? $request['maximum_km'] : '';
        $translator_ex = $request['translator_ex'];
        $translator_type = $request['translator_type'];
        $worked_for = $request['worked_for'];
        $organization_number = $request['organization_number'];
        $translator_level = $request['translator_level'];
        $user_language = $request['user_language'];
        $new_towns = $request['new_towns'];
        $user_towns_projects = $request['user_towns_projects'];
        $status = $request['status'];


        $model = User::firstOrCreate(['id' => $id]);
        $model->user_type = $role;
        $model->name = $name;
        $model->company_id = $company_id;
        $model->department_id = $department_id;
        $model->email = $email;
        $model->dob_or_orgid = $dob_or_orgid;
        $model->phone = $phone;
        $model->mobile = $mobile;

        if (!$id || $id && $password) $model->password = bcrypt($password);
        $model->detachAllRoles();
        $model->save();
        $model->attachRole($role);
        $data = array();

        $user_meta = UserMeta::firstOrCreate(['user_id' => $model->id]);

        if ($role == env('CUSTOMER_ROLE_ID')) {

            if($consumer_type == 'paid')
            {
                if($company_id == '')
                {
                    $type = Type::where('code', 'paid')->first();
                    $company = Company::create(['name' => $name, 'type_id' => $type->id, 'additional_info' => 'Created automatically for user ' . $model->id]);
                    $department = Department::create(['name' => $name, 'company_id' => $company->id, 'additional_info' => 'Created automatically for user ' . $model->id]);

                    $model->company_id = $company->id;
                    $model->department_id = $department->id;
                    $model->save();
                }
            }

            $user_meta->consumer_type = $consumer_type;
            $user_meta->username = $username;
            $user_meta->city = $city;
            $user_meta->country = $country;
            $user_meta->reference = $reference;
            $user_meta->cost_place = $cost_place;
            $user_meta->fee = $fee;
            $user_meta->time_to_charge = $time_to_charge;
            $user_meta->time_to_pay = $time_to_pay;
            $user_meta->charge_ob = $charge_ob;
            $user_meta->customer_id = $customer_id;
            $user_meta->charge_km = $charge_km;
            $user_meta->maximum_km = $maximum_km;

            $blacklistUpdated = [];
            $userBlacklist = UsersBlacklist::where('user_id', $id)->get();
            $userTranslId = collect($userBlacklist)->pluck('translator_id')->all();

            $diff = null;
            if ($translator_ex) {
                $diff = array_intersect($userTranslId, $translator_ex);
            }
            if ($diff || $translator_ex) {
                foreach ($translator_ex as $translatorId) {
                    $blacklist = new UsersBlacklist();
                    if ($model->id) {
                        $already_exist = UsersBlacklist::translatorExist($model->id, $translatorId);
                        if ($already_exist == 0) {
                            $blacklist->user_id = $model->id;
                            $blacklist->translator_id = $translatorId;
                            $blacklist->save();
                        }
                        $blacklistUpdated [] = $translatorId;
                    }

                }
                if ($blacklistUpdated) {
                    UsersBlacklist::deleteFromBlacklist($model->id, $blacklistUpdated);
                }
            } else {
                UsersBlacklist::where('user_id', $model->id)->delete();
            }


        } else if ($request['role'] == env('TRANSLATOR_ROLE_ID')) {

            $user_meta = UserMeta::firstOrCreate(['user_id' => $model->id]);

            $user_meta->translator_type = $translator_type;
            $user_meta->worked_for = $worked_for;
            if ($worked_for == 'yes') {
                $user_meta->organization_number = $organization_number;
            }
            $user_meta->gender = $gender;
            $user_meta->translator_level = $translator_level;
            $user_meta->address_2 = $address_2;

            $data['translator_type'] = $translator_type;
            $data['worked_for'] = $worked_for;
            if ($worked_for == 'yes') {
                $data['organization_number'] = $organization_number;
            }
            $data['gender'] = $gender;
            $data['translator_level'] = $translator_level;

            $langidUpdated = [];
            if ($user_language) {
                foreach ($user_language as $langId) {
                    $userLang = new UserLanguages();
                    $already_exit = $userLang::langExist($model->id, $langId);
                    if ($already_exit == 0) {
                        $userLang->user_id = $model->id;
                        $userLang->lang_id = $langId;
                        $userLang->save();
                    }
                    $langidUpdated[] = $langId;

                }
                if ($langidUpdated) {
                    $userLang::deleteLang($model->id, $langidUpdated);
                }
            }

        }

        $user_meta->post_code = $post_code;
        $user_meta->address = $address;
        $user_meta->town = $town;
        $user_meta->additional_info = $additional_info;
        $user_meta->save();

        if ($new_towns) {
            $towns = new Town;
            $towns->townname = $new_towns;
            $towns->save();
            $newTownsId = $towns->id;
        }

        $townidUpdated = [];
        if ($user_towns_projects) {
            DB::table('user_towns')->where('user_id', '=', $model->id)->delete();
            foreach ($user_towns_projects as $townId) {
                $userTown = new UserTowns();
                $already_exit = $userTown::townExist($model->id, $townId);
                if ($already_exit == 0) {
                    $userTown->user_id = $model->id;
                    $userTown->town_id = $townId;
                    $userTown->save();
                }
                $townidUpdated[] = $townId;

            }
        }

        if ($status == '1') {
            if ($model->status != '1') {
                $this->enable($model->id);
            }
        } else {
            if ($model->status != '0') {
                $this->disable($model->id);
            }
        }
        return $model ? $model : false;
    }

    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '1';
        $user->save();
    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '0';
        $user->save();

    }

    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }
    
}