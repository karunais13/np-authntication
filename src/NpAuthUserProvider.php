<?php
namespace Karu\NpAuthentication;

use App\Helpers\DBConnectionHelper;
use App\Models\DepotSalesrep;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Contracts\Auth\UserProvider;

use Auth;
use HTTPHelper;
use Log;
use Session;
use Cache;
use CacheHelper;
use Cookie;
use NpRes;

class NpAuthUserProvider implements UserProvider {

    protected $model;
    protected $salesrepModel;

    const
        CLIENT_TYPE_WEB = 'web',
        CLIENT_TYPE_API = 'api'
    ;

    public function __construct()
    {

        $this->salesrepModel = config('npauth.auth_main_class');

        $authUser   = CacheHelper::getAuthUser();
        if( $authUser )
            $this->model  = $authUser;
        else{}
            $this->model  = new $this->salesrepModel();
    }

    public function retrieveById($identifier)
    {
        return $this->model;
    }

    public function retrieveByToken($identifier, $token)
    {
        return new \Exception('not implemented');
    }

    public function updateRememberToken(UserContract $user, $token)
    {
        return new \Exception('not implemented');
    }

    /**
     * @param array $credentials
     * @return GenericUser|UserContract|null|void
     */
    public function retrieveByCredentials(array $credentials)
    {

        //check user exists in application user table
//        if( !$this->checkUserExists($credentials['username']))
//            return Session::flash('error', __("User not found"));

        $params   = [
            'username'  => $credentials['username'],
            'password'  => $credentials['password'],
            'country_code'  => $credentials['country_code'],
            'system_code'   => config('app.sso.system_code')
        ];
        $res    = HTTPHelper::makePublicPOSTRequest($params, config('endpoints.sso.login'), 'sso');
        if( $res['http_status_code'] == 401 ){

            if( $credentials['client'] == self::CLIENT_TYPE_WEB  ){
                Session::flash('error', $res['payload']->message);
            }
            elseif( $credentials['client'] == self::CLIENT_TYPE_API ){
                return NpRes::res(false, 10001, $res);
            }

            Log::emergency(sprintf('%s/%s', get_class($this), __FUNCTION__));
            return;
        }

        if( $res['http_status_code'] == 500 ){
            if( $credentials['client'] == self::CLIENT_TYPE_WEB  ){
                Session::flash('error', 'SSO - Internal Server Error');
            }
            elseif( $credentials['client'] == self::CLIENT_TYPE_API ){
                return NpRes::res(false, 10001, 'SSO - Internal Server Error');
            }

            Log::emergency(sprintf('%s/%s', get_class($this), __FUNCTION__));
            return;
        }

        $data   = $res['payload'];
        if( !$data->succeeded ){
            if( $credentials['client'] == self::CLIENT_TYPE_WEB  ){
                Session::flash('error', $data->message);
            }
            elseif( $credentials['client'] == self::CLIENT_TYPE_API ){
                return;
            }
        }
        else {
            $user   = $this->getUserDetails($data->objects->user);
            if( !isset($user) || empty($user)  ){
                Session::flash('error', __('User Not Found'));
            }
            if( $credentials['client'] == self::CLIENT_TYPE_WEB  ){

                Cache::put(CacheHelper::getCacheKey('user', $user->emp_code), $user, now()->addMonth());
                $this->model    = new GenericUser($user->toArray());

                return $this->model;
            }
            elseif( $credentials['client'] == self::CLIENT_TYPE_API ){
                return new GenericUser($user->toArray());
            }
        }
    }

    public function validateCredentials(UserContract $user, array $credentials)
    {
        return $this->model;
    }

    protected function checkUserExists( $empCode )
    {
          return $this->salesrepModel::where('emp_code', $empCode)->active()->exists();
    }

    protected function getUserDetails( $response )
    {
        DBConnectionHelper::setDBConnection($response->country_code);

        $user   = $this->salesrepModel::where('emp_code', $response->emp_code)
//                    ->where('country_code', $response->country_code)
                    ->active()
                    ->first();

        $user['id']   = $user['emp_code'];
        $user['country_code']   = $response->country_code;
        $user['timezone'] = DBConnectionHelper::getCountryTimezone($response->country_code);
        $user['currency_code'] = DBConnectionHelper::getCountryCurrency($response->country_code);

        return $user;
    }
}
