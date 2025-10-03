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
        // Core identity attributes
        'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
        'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
        
        // Organizational attributes
        'department' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department',
        'title' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/title',
        'employee_id' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeeid',
        'employee_type' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeetype',
        
        // Contact information
        'phone' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/phone',
        'office' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/streetaddress',
        
        // Group membership (try multiple possible claim names)
        'groups' => [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/memberof',
            'http://schemas.xmlsoap.org/claims/Group',
            'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
        ],
        
        // Additional UW-specific attributes
        'student_id' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/studentid',
        'faculty' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/faculty',
        'program' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/program',
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for proxy/staging AP scenarios where this application
    | acts as both SAML proxy and client
    |
    */
    'proxy' => [
        // Enable proxy mode - acts as intermediary between client apps and UW ADFS
        'enabled' => env('UW_ADFS_PROXY_ENABLED', false),
        
        // Proxy mode: 'server' (receives from clients), 'client' (forwards to UW ADFS), 'both'
        'mode' => env('UW_ADFS_PROXY_MODE', 'both'),
        
        // Upstream ADFS configuration (when acting as client)
        'upstream' => [
            'idp_entity_id' => env('UW_ADFS_UPSTREAM_IDP_ENTITY_ID', 'https://adfs.uwaterloo.ca/adfs/services/trust'),
            'sso_url' => env('UW_ADFS_UPSTREAM_SSO_URL', 'https://adfs.uwaterloo.ca/adfs/ls/'),
            'sls_url' => env('UW_ADFS_UPSTREAM_SLS_URL', 'https://adfs.uwaterloo.ca/adfs/ls/'),
            'metadata_url' => env('UW_ADFS_UPSTREAM_METADATA_URL'),
        ],
        
        // Downstream client applications (when acting as server)
        'clients' => [
            // Format: 'entity_id' => ['acs_url' => 'url', 'sls_url' => 'url']
            // Can be configured via environment or added programmatically
        ],
        
        // Proxy-specific settings
        'session_lifetime' => env('UW_ADFS_PROXY_SESSION_LIFETIME', 3600), // 1 hour
        'allow_unsolicited' => env('UW_ADFS_PROXY_ALLOW_UNSOLICITED', true),
        'attribute_filtering' => env('UW_ADFS_PROXY_ATTRIBUTE_FILTERING', true),
        'sign_assertions' => env('UW_ADFS_PROXY_SIGN_ASSERTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Control Configuration
    |--------------------------------------------------------------------------
    |
    | Configure department restrictions and user whitelists
    |
    */
    'access_control' => [
        // Enable department-based access control
        'department_restriction_enabled' => env('UW_ADFS_DEPARTMENT_RESTRICTION', false),
        
        // Allowed departments (comma-separated list)
        'allowed_departments' => env('UW_ADFS_ALLOWED_DEPARTMENTS') 
            ? array_map('trim', explode(',', env('UW_ADFS_ALLOWED_DEPARTMENTS')))
            : [],
        
        // Enable email whitelist
        'whitelist_enabled' => env('UW_ADFS_WHITELIST_ENABLED', false),
        
        // Whitelisted email addresses (comma-separated list)
        'whitelist_emails' => env('UW_ADFS_WHITELIST_EMAILS')
            ? array_map('trim', explode(',', env('UW_ADFS_WHITELIST_EMAILS')))
            : [],
        
        // Enable group-based access control
        'group_restriction_enabled' => env('UW_ADFS_GROUP_RESTRICTION', false),
        
        // Required groups (user must belong to at least one)
        'required_groups' => env('UW_ADFS_REQUIRED_GROUPS')
            ? array_map('trim', explode(',', env('UW_ADFS_REQUIRED_GROUPS')))
            : [],
        
        // Blocked groups (users in these groups are denied access)
        'blocked_groups' => env('UW_ADFS_BLOCKED_GROUPS')
            ? array_map('trim', explode(',', env('UW_ADFS_BLOCKED_GROUPS')))
            : [],
        
        // Access denied redirect URL
        'access_denied_url' => env('UW_ADFS_ACCESS_DENIED_URL', '/access-denied'),
        
        // Custom access denied message
        'access_denied_message' => env('UW_ADFS_ACCESS_DENIED_MESSAGE', 'Access denied. You do not have permission to access this application.'),
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