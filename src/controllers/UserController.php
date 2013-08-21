<?php 

namespace MrJuliuss\Syntara\Controllers;

use MrJuliuss\Syntara\Controllers\BaseController;
use View;
use Input;
use Response;
use Request;
use Sentry;
use Validator;
use Config;
use URL;

class UserController extends BaseController 
{

    /**
    * Display a list of all users
    *
    * @return Response
    */
    public function getIndex()
    {
        // get alls users
        $emptyUsers =  Sentry::getUserProvider()->getEmptyUser();

        // users search
        $userId = Input::get('userIdSearch');
        if(!empty($userId))
        {
            $emptyUsers = $emptyUsers->where('id', $userId);
        }
        $username = Input::get('usernameSearch');
        if(!empty($username))
        {
            $emptyUsers = $emptyUsers->where('username', 'LIKE', '%'.$username.'%');
        }
        $email = Input::get('emailSearch');
        if(!empty($email))
        {
            $emptyUsers = $emptyUsers->where('email', 'LIKE', '%'.$email.'%');
        }

        $users = $emptyUsers->paginate(20);
        $datas['links'] = $users->links();
        $datas['users'] = $users;

        // ajax request : reload only content container
        if(Request::ajax())
        {
            $html = View::make('syntara::user.list-users', array('datas' => $datas))->render();
            
            return Response::json(array('html' => $html));
        }
        
        $this->layout = View::make('syntara::user.index-user', array('datas' => $datas));
        $this->layout->title = "Users list";
        $this->layout->breadcrumb = Config::get('syntara::breadcrumbs.users');
    }
    
    /**
    * Show new user form view
    */
    public function getCreate()
    {
        $groups = Sentry::getGroupProvider()->findAll();
        
        $this->layout = View::make('syntara::user.new-user', array('groups' => $groups));
        $this->layout->title = "New user";
        $this->layout->breadcrumb = Config::get('syntara::breadcrumbs.create_user');
    }

    /**
    * Create new user
    */
    public function postCreate()
    {
        try
        {
            $validator = Validator::make(
                Input::all(),
                Config::get('syntara::rules.users.create')
            );
            
            if($validator->fails())
            {
                return Response::json(array('userCreated' => false, 'errorMessages' => $validator->messages()->getMessages()));
            }
            
            // create user
            $user = Sentry::getUserProvider()->create(array(
                'email'    => Input::get('email'),
                'password' => Input::get('pass'),
                'username' => Input::get('username'),
                'last_name' => (string)Input::get('last_name'),
                'first_name' => (string)Input::get('first_name')
            ));
            
            // activate user
            $activationCode = $user->getActivationCode();
            $user->attemptActivation($activationCode);

            $groups = Input::get('groups');
            if(isset($groups) && is_array($groups))
            {
                foreach($groups as $groupId)
                {
                    $group = Sentry::getGroupProvider()->findById($groupId);
                    $user->addGroup($group);
                }
            }
        }
        catch (\Cartalyst\Sentry\Users\LoginRequiredException $e){} // already catch by validators
        catch (\Cartalyst\Sentry\Users\PasswordRequiredException $e){} // already catch by validators
        catch (\Cartalyst\Sentry\Groups\GroupNotFoundException $e){}
        catch (\Cartalyst\Sentry\Users\UserExistsException $e)
        {
            return json_encode(array('userCreated' => false, 'message' => 'User with this login already exists.', 'messageType' => 'danger'));
        }
        catch(\Exception $e)
        {
            return Response::json(array('userCreated' => false, 'message' => 'A user with this username already exists.', 'messageType' => 'danger'));
        }

        return json_encode(array('userCreated' => true));
    }
    
    /**
    * Delete a user
    */
    public function delete()
    {
        try
        {
            $userId = Input::get('userId');
            if($userId !== Sentry::getUser()->getId())
            {
                $user = Sentry::getUserProvider()->findById($userId);
                $user->delete();
            }
            else
            {
                return Response::json(array('deletedUser' => false, 'message' => "You can't delete your own user !", 'messageType' => 'danger'));
            }
        }
        catch (\Cartalyst\Sentry\Users\UserNotFoundException $e)
        {
            return Response::json(array('deletedUser' => false, 'message' => 'User does not exists.', 'messageType' => 'danger'));
        }
        
        return Response::json(array('deletedUser' => true, 'message' => 'User removed with success.', 'messageType' => 'success'));
    }

    /**
    * View user account
    * @param int $userId
    */
    public function getShow($userId)
    {
        try
        {
            $user = Sentry::getUserProvider()->findById($userId);
            $throttle = Sentry::getThrottleProvider()->findByUserId($userId);
            $groups = Sentry::getGroupProvider()->findAll();
            
            $this->layout = View::make('syntara::user.show-user', array(
                'user' => $user,
                'throttle' => $throttle,
                'groups' => $groups,
            ));
            $this->layout->title = 'User '.$user->username;
            $this->layout->breadcrumb = array(
                    array(
                        'title' => 'Users', 
                        'link' => "dashboard/users", 
                        'icon' => 'glyphicon-user'
                    ), 
                    array(
                     'title' => $user->username, 
                     'link' => URL::current(), 
                     'icon' => ''
                    )
            );
        }
        catch (\Cartalyst\Sentry\Users\UserNotFoundException $e)
        {
            $this->layout = View::make('syntara::dashboard.error', array('message' => 'Sorry, user not found ! '));
        }
    }

    /**
    * Update user account
    * @param int $userId
    * @return Response
    */
    public function putShow($userId)
    {
        try
        {
            $validator = Validator::make(
                Input::all(),
                Config::get('syntara::rules.users.show')
            );
            if($validator->fails())
            {
                return Response::json(array('userUpdated' => false, 'errorMessages' => $validator->messages()->getMessages()));
            }
            
            // Find the user using the user id
            $user = Sentry::getUserProvider()->findById($userId);
            $user->username = Input::get('username');
            $user->email = Input::get('email');
            $user->last_name = Input::get('last_name');
            $user->first_name = Input::get('first_name');
            
            $pass = Input::get('pass');
            if(!empty($pass))
            {
                $user->password = $pass;
            }
            
            // Update the user
            if($user->save())
            {
                // if the user has permission to update
                if(Sentry::getUser()->hasAccess('user-group-management'))
                {
                    $groups = (Input::get('groups') === null) ? array() : Input::get('groups');
                    $userGroups = $user->getGroups()->toArray();
                    
                    foreach($userGroups as $group)
                    {
                        if(!in_array($group['id'], $groups))
                        {
                            $group = Sentry::getGroupProvider()->findById($group['id']);
                            $user->removeGroup($group);
                        }
                    }
                    if(isset($groups) && is_array($groups))
                    {
                        foreach($groups as $groupId)
                        {
                            $group = Sentry::getGroupProvider()->findById($groupId);
                            $user->addGroup($group);
                        }
                    }
                }
                
                return Response::json(array('userUpdated' => true, 'message' => 'User has been updated with success.', 'messageType' => 'success'));
            }
            else 
            {
                return Response::json(array('userUpdated' => false, 'message' => 'Can not update this user, please try again.', 'messageType' => 'danger'));
            }
        }
        catch(\Cartalyst\Sentry\Users\UserExistsException $e)
        {   
            return Response::json(array('userUpdated' => false, 'message' => 'A user with this email already exists.', 'messageType' => 'danger'));
        }
        catch(\Exception $e)
        {
            return Response::json(array('userUpdated' => false, 'message' => 'A user with this username already exists.', 'messageType' => 'danger'));
        }
    }
}