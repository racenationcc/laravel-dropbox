<?php

namespace RaceNation\Dropbox;

use RaceNation\Dropbox\Facades\Dropbox as Api;
use RaceNation\Dropbox\Models\DropboxToken;
use RaceNation\Dropbox\Resources\Files;

use GuzzleHttp\Client;
use Exception;

class Dropbox
{
    protected static $baseUrl = 'https://api.dropboxapi.com/2/';
    protected static $contentUrl = 'https://content.dropboxapi.com/2/';
    protected static $authorizeUrl = 'https://www.dropbox.com/oauth2/authorize';
    protected static $tokenUrl = 'https://api.dropbox.com/oauth2/token';

    public function __construct()
    {
    }

    public function files($useToken = false, $useConfigRefreshToken = false)
    {
        return new Files($useToken, $useConfigRefreshToken);
    }

    protected function addRootFolderToPath(string $path): string
    {
        return config('dropbox.rootFolder').$this->forceStartingSlash($path);
    }

    protected function forceStartingSlash($string)
    {
        if (substr($string, 0, 1) !== "/") {
            $string = "/$string";
        }
        return $string;
    }

    /**
     * __call catches all requests when no founf method is requested
     * @param  $function - the verb to execute
     * @param  $args - array of arguments
     * @return gizzle request
     */
    public function __call($function, $args)
    {

        $options = ['get', 'post', 'patch', 'put', 'delete'];
        
        list($requestPath, $requestData) = $args;

        $data = $requestData['data'] ?? null;
        $customHeaders = $requestData['headers'] ?? null;
        $useToken = $requestData['useToken'] ?? null;
        $useConfigRefreshToken = $requestData['useConfigRefreshToken'] ?? null;

        if (in_array($function, $options)) {
            return self::guzzle($function, $requestPath, $data, $customHeaders, $useToken, $useConfigRefreshToken);
        } else {
            //request verb is not in the $options array
            throw new Exception($function . ' is not a valid HTTP Verb');
        }
    }

    /**
     * Make a connection or return a token where it's valid
     * @return mixed
     */
    public function connect()
    {
        //when no code param redirect to Microsoft
        if (!request()->has('code')) {

            $url = self::$authorizeUrl . '?' . http_build_query([
                'response_type' => 'code',
                'client_id' => config('dropbox.clientId'),
                'redirect_uri' => config('dropbox.redirectUri'),
                'scope' => config('dropbox.scopes'),
                'token_access_type' => config('dropbox.accessType')
            ]);

            return redirect()->away($url);
        } elseif (request()->has('code')) {

            // With the authorization code, we can retrieve access tokens and other data.
            try {

                $params = [
                    'grant_type'    => 'authorization_code',
                    'code'          => request('code'),
                    'redirect_uri'  => config('dropbox.redirectUri'),
                    'client_id'     => config('dropbox.clientId'),
                    'client_secret' => config('dropbox.clientSecret')
                ];

                $token = $this->dopost(self::$tokenUrl, $params);
                $result = $this->storeToken($token);

                //get user details
                $me = Api::post('users/get_current_account');

                //find record and add email - not required but useful none the less
                $t = DropboxToken::findOrFail($result->id);
                $t->email = $me['email'];
                $t->save();

                return redirect(config('dropbox.landingUri'));
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
    }

    /**
     * @return object
     */
    public function isConnected()
    {
        return $this->getTokenData() == null ? false : true;
    }

    /**
     * Disables the access token used to authenticate the call, redirects back to the provided path
     * @param string $redirectPath
     * @return \Illuminate\Http\RedirectResponse
     */
    public function disconnect($redirectPath = '/')
    {
        $id = auth()->id();

        $client = new Client;
        $response = $client->post(self::$baseUrl.'auth/token/revoke', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken()
            ]
        ]);

        //delete token from db
        $token = DropboxToken::where('user_id', $id)->first();
        if ($token !== null) {
            $token->delete();
        }

        header('Location: ' .url($redirectPath));
        exit();
    }

    /**
     * Return authenticated access token or request new token when expired
     * @param  $id integer - id of the user
     * @param  $returnNullNoAccessToken null when set to true return null
     * @return return string access token
     */
    public function getAccessToken($useConfigRefreshToken = false, $returnNullNoAccessToken = null)
    {
        //use token from .env if exists
        if (config('dropbox.accessToken') !== '') {
            return config('dropbox.accessToken');
        }

        if($useConfigRefreshToken){
            if(config('dropbox.refeshToken') !== ''){
                return $this->refreshAccessToken(config('dropbox.refreshToken'))['access_token'];
            }else{
                //refresh token missing return null
                return null;
            }
        }


        //use id if passed otherwise use logged in user
        $id    = auth()->id();
        $token = DropboxToken::where('user_id', $id)->first();

        // Check if tokens exist otherwise run the oauth request
        if (!isset($token->access_token)) {

            //don't redirect simply return null when no token found with this option
            if ($returnNullNoAccessToken == true) {
                return null;
            }

            header('Location: ' . config('dropbox.redirectUri'));
            exit();
        }

        if (isset($token->refresh_token)) {
            // Check if token is expired
            // Get current time + 5 minutes (to allow for time differences)
            $now = time() + 300;
            if ($token->expires_in <= $now) {
                // Token is expired (or very close to it) so let's refresh
                $params = [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $token->refresh_token,
                    'client_id'     => config('dropbox.clientId'),
                    'client_secret' => config('dropbox.clientSecret')
                ];

                $tokenResponse       = $this->dopost(self::$tokenUrl, $params);
                $token->access_token = $tokenResponse['access_token'];
                $token->expires_in   = now()->addseconds($tokenResponse['expires_in']);
                $token->save();

                return $token->access_token;
            }
        }

        // Token is still valid, just return it
        return $token->access_token;
    }

    /**
     * Return authenticated access token or request new token when expired
     * @param  $refreshToken string - refresh token for account
     * @return return string access token
     */
    public function refreshAccessToken($refreshToken)
    {

        // Token is expired (or very close to it) so let's refresh
        $params = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => config('dropbox.clientId'),
            'client_secret' => config('dropbox.clientSecret')
        ];

        return  $this->dopost(self::$tokenUrl, $params);

    }

    /**
     * @param  $id - integar id of user
     * @return object
     */
    public function getTokenData()
    {
        //use token from .env if exists
        if (config('dropbox.accessToken') !== '') {
            return false;
        }

        $id = auth()->id();
        return DropboxToken::where('user_id', $id)->first();
    }

    /**
     * Store token
     * @param  $access_token string
     * @param  $refresh_token string
     * @param  $expires string
     * @param  $id integer
     * @return object
     */
    protected function storeToken($token)
    {
        $id = auth()->id();

        $data = [
            'user_id'       => $id,
            'access_token'  => $token['access_token'],
            'expires_in'    => now()->addseconds($token['expires_in']),
            'token_type'    => $token['token_type'],
            'uid'           => $token['uid'],
            'account_id'    => $token['account_id'],
            'scope'         => $token['scope']
        ];

        if (isset($token['refresh_token'])) {
            $data['refresh_token'] = $token['refresh_token'];
        }

        //cretate a new record or if the user id exists update record
        return DropboxToken::updateOrCreate(['user_id' => $id], $data);
    }

    /**
     * run guzzle to process requested url
     * @param  $type string
     * @param  $request string
     * @param  $data array
     * @param  $customHeaders array
     * @param  $useToken bool
     * @param  $useConfigRefreshToken bool
     * @return json object
     */
    protected function guzzle($type, $request, $data = [], $customHeaders = null, $useToken = true, $useConfigRefreshToken = false)
    {
        try {
            $client = new Client;

            $headers = [
                'content-type' => 'application/json'
            ];

            if ($useToken == true) {
                $headers['Authorization'] = 'Bearer ' . $this->getAccessToken($useConfigRefreshToken);
            }

            if ($customHeaders !== null) {
                foreach ($customHeaders as $key => $value) {
                    $headers[$key] = $value;
                }
            }

            $response = $client->$type(self::$baseUrl . $request, [
                'headers' => $headers,
                'body' => json_encode($data)
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (ClientException $e) {

            throw new Exception($e->getResponse()->getBody()->getContents());

        } catch (Exception $e) {

            throw new Exception($e->getMessage()); 
            
        }
    }

    protected static function dopost($url, $params)
    {
        try {
            $client = new Client;
            $response = $client->post($url, ['form_params' => $params]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            return json_decode($e->getResponse()->getBody()->getContents(), true);
        }
    }
}
