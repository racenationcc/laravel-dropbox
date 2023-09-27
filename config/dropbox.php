<?php

return [

    /*
    * set the client id
    */
    'clientId' => env('DROPBOX_APP_KEY'),

    /*
    * set the client secret
    */
    'clientSecret' => env('DROPBOX_APP_SECRET'),

    /*
    * Set the url to trigger the oauth process this url should call return Dropbox::connect();
    */
    'redirectUri' => env('DROPBOX_OAUTH_URL'),

    /*
    * Set the url to redirecto once authenticated;
    */
    'landingUri' => env('DROPBOX_LANDING_URL', '/'),

    /**
     * Set access token, when set will bypass the oauth2 process
     */
    'accessToken' => env('DROPBOX_AUTH_TOKEN', ''),

    /**
     * The root folder of where the dropbox API will write too
     */
    'rootFolder' => env('DROPBOX_ROOT_FOLDER', '/'),

    /**
     * Set access token, when set will bypass the oauth2 process
     */
    'refreshToken' => env('DROPBOX_REFRESH_TOKEN', ''),

    /**
     * Set access type, options are offline and online
     * Offline - will return a short-lived access_token and a long-lived refresh_token that can be used to request a new short-lived access token as long as a user's approval remains valid.
     *
     * Online - will return a short-lived access_token
     */
    'accessType' => env('DROPBOX_ACCESS_TYPE', 'offline'),

    /*
    set the scopes to be used
    */
    'scopes' => 'account_info.read files.metadata.write files.metadata.read files.content.write files.content.read',
];