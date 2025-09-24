<?php

return [
    /*
    |--------------------------------------------------------------------------
    | University of Waterloo ADFS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for UW ADFS SAML authentication
    |
    */

    'environment' => env('UW_ADFS_ENVIRONMENT', 'development'), // 'production' or 'development'

    'sp' => [
        'entityId' => env('UW_ADFS_SP_ENTITY_ID', config('app.url')),
        'assertionConsumerService' => [
            'url' => env('UW_ADFS_SP_ACS_URL', config('app.url') . '/saml/acs'),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'singleLogoutService' => [
            'url' => env('UW_ADFS_SP_SLS_URL', config('app.url') . '/saml/sls'),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'x509cert' => env('UW_ADFS_SP_X509_CERT', ''),
        'privateKey' => env('UW_ADFS_SP_PRIVATE_KEY', ''),
    ],

    'idp' => [
        'production' => [
            'entityId' => 'adfs.uwaterloo.ca',
            'singleSignOnService' => [
                'url' => 'https://adfs.uwaterloo.ca/adfs/ls/',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'singleLogoutService' => [
                'url' => 'https://adfs.uwaterloo.ca/adfs/ls/',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'metadata_url' => 'https://adfs.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml',
            'metadata_file' => storage_path('app/saml/prod.xml'), // Fallback local file
        ],
        'development' => [
            'entityId' => 'adfstest.uwaterloo.ca',
            'singleSignOnService' => [
                'url' => 'https://adfstest.uwaterloo.ca/adfs/ls/',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'singleLogoutService' => [
                'url' => 'https://adfstest.uwaterloo.ca/adfs/ls/',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'metadata_url' => 'https://adfstest.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml',
            'metadata_file' => storage_path('app/saml/dev.xml'), // Fallback local file
        ],
    ],

    'security' => [
        'nameIdEncrypted' => false,
        'authnRequestsSigned' => false,
        'logoutRequestSigned' => false,
        'logoutResponseSigned' => false,
        'signMetadata' => false,
        'wantMessagesSigned' => false,
        'wantAssertionsSigned' => false,
        'wantNameId' => true,
        'wantAssertionsEncrypted' => false,
        'wantNameIdEncrypted' => false,
        'requestedAuthnContext' => true,
        'requestedAuthnContextComparison' => 'exact',
        'wantXMLValidation' => true,
        'relaxDestinationValidation' => false,
        'destinationStrictlyMatches' => false,
        'allowRepeatAttributeName' => false,
        'rejectUnsolicitedResponsesWithInResponseTo' => false,
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for fetching and caching SAML metadata
    |
    */
    'metadata' => [
        'cache_enabled' => env('UW_ADFS_METADATA_CACHE', true),
        'cache_duration' => env('UW_ADFS_METADATA_CACHE_DURATION', 3600), // 1 hour in seconds
        'timeout' => env('UW_ADFS_METADATA_TIMEOUT', 30), // HTTP timeout in seconds
        'fallback_to_local' => env('UW_ADFS_METADATA_FALLBACK_LOCAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model Configuration
    |--------------------------------------------------------------------------
    */
    'user_model' => env('UW_ADFS_USER_MODEL', App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Attribute Mapping
    |--------------------------------------------------------------------------
    |
    | Map SAML attributes to user model attributes
    |
    */
    'attribute_mapping' => [
        'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
        'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
        'groups' => 'http://schemas.xmlsoap.org/claims/Group',
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Configuration
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'login' => '/saml/login',
        'acs' => '/saml/acs',
        'sls' => '/saml/sls',
        'logout' => '/saml/logout',
        'metadata' => '/saml/metadata',
    ],
];