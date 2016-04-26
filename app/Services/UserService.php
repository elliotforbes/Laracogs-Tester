<?php

namespace App\Services;

use DB;
use Auth;
use Mail;
use Config;
use Exception;
use App\Repositories\User\UserRepository;
use App\Repositories\UserMeta\UserMetaRepository;

class UserService
{
    public function __construct(UserMetaRepository $userMetaRepo, UserRepository $userRepo)
    {
        $this->userMetaRepo = $userMetaRepo;
        $this->userRepo = $userRepo;
    }

    /*
    |--------------------------------------------------------------------------
    | Getters
    |--------------------------------------------------------------------------
    */

    public function all()
    {
        return $this->userRepo->all();
    }

    public function find($id)
    {
        return $this->userRepo->find($id);
    }

    public function search($input)
    {
        return $this->userRepo->search($input, env('paginate', 25));
    }

    public function findByEmail($email)
    {
        return $this->userRepo->findByEmail($email);
    }

    /*
    |--------------------------------------------------------------------------
    | Setters
    |--------------------------------------------------------------------------
    */

    /**
     * Create a user's profile
     *
     * @param  User $user User
     * @param  string $password the user password
     * @param  string $role the role of this user
     * @param  boolean $sendEmail Whether to send the email or not
     * @return User
     */
    public function create($user, $password, $role = 'member', $sendEmail = true)
    {
        try {
            DB::beginTransaction();
                // create the user meta
                $this->userMetaRepo->findByUserId($user->id);
                // Set the user's role
                $this->userRepo->assignRole($role, $user->id);

                if ($sendEmail) {
                    // Email the user about their profile
                    Mail::send('emails.new-user', ['user' => $user, 'password' => $password], function ($m) use ($user) {
                        $m->from('info@app.com', 'App');
                        $m->to($user->email, $user->name)->subject('You have a new profile!');
                    });
                }
            DB::commit();

            return $user;
        } catch (Exception $e) {
            throw new Exception("We were unable to generate your profile, please try again later.", 1);
        }
    }

    /**
     * Update a user's profile
     *
     * @param  int $userId User Id
     * @param  array $inputs UserMeta info
     * @return boolean
     */
    public function update($userId, $inputs)
    {
        if (isset($inputs['meta']) && ! isset($inputs['meta']['terms_and_cond'])) {
            throw new Exception("You must agree to the terms and conditions.", 1);
        }

        try {
            DB::beginTransaction();
                $userMetaResult = (isset($inputs['meta'])) ? $this->userMetaRepo->update($userId, $inputs['meta']) : true;
                $userResult = $this->userRepo->update($userId, $inputs);
                if (isset($inputs['roles'])) {
                    $this->userRepo->unassignAllRoles($userId);
                    $this->userRepo->assignRole($inputs['roles'], $userId);
                }
            DB::commit();
        } catch (Exception $e) {
            throw new Exception("We were unable to update your profile", 1);
        }

        return ($userMetaResult && $userResult);
    }

    /**
     * Invite a new member
     * @param  array $info
     * @return void
     */
    public function invite($info)
    {
        $password = substr(md5(rand(1111, 9999)), 0, 10);

        $user = $this->userRepo->create([
            'email' => $info['email'],
            'name' => $info['name'],
            'password' => bcrypt($password)
        ]);

        return $this->create($user, $password, $info['roles'], true);
    }

    /**
     * Destroy the profile
     *
     * @param  int $id
     * @return bool
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
                $this->userRepo->unassignAllRoles($id);
                $this->userRepo->leaveAllTeams($id);
                $userMetaResult = $this->userMetaRepo->destroy($id);
                $userResult = $this->userRepo->destroy($id);
            DB::commit();
        } catch (Exception $e) {
            throw new Exception("We were unable to delete this profile", 1);
        }

        return ($userMetaResult && $userResult);
    }

}
